<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Integration\Async\Store;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Scheduler\Integration\Async\Store\FileExecutionLedgerIndexCodec;

final class FileExecutionLedgerIndexCodecTest extends TestCase
{
    public function testBoundedWindowsAcceptEscapedActiveEntryStrings(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $expected = [
            ['taskKey' => 'a', 'occurrenceId' => '1'],
            ['taskKey' => 'b"\\{}', 'occurrenceId' => '2"\\{}'],
        ];
        $json = $codec->encodeActive($expected);

        self::assertSame($expected, $codec->decodeActive($json));

        $initial = $codec->decodeActiveWindowInitial($json, 1);
        self::assertSame([$expected[0]], $initial['entries']);
        self::assertFalse($initial['complete']);
        $continuation = $codec->decodeActiveWindowFast($json, $initial['next'], 1, $expected[0]);
        self::assertSame([$expected[1]], $continuation['entries']);
        self::assertTrue($continuation['complete']);

        $handle = fopen('php://temp', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, $json);
        $streamInitial = $codec->decodeActiveWindowInitialStream($handle);
        self::assertSame([$expected[0]], $streamInitial['entries']);
        self::assertFalse($streamInitial['complete']);
        $streamContinuation = $codec->decodeActiveWindowStream($handle, $streamInitial['next'], 1, $expected[0]);
        self::assertSame([$expected[1]], $streamContinuation['entries']);
        self::assertTrue($streamContinuation['complete']);
        fclose($handle);
    }

    public function testStreamingWindowsAcceptPrettyPrintedWhitespace(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = <<<'JSON'
{
  "schema": "dev",
  "entries": [
    {
      "taskKey": "a",
      "occurrenceId": "1"
    },
    {
      "taskKey": "b",
      "occurrenceId": "2"
    }
  ]
}
JSON;
        $expected = [
            ['taskKey' => 'a', 'occurrenceId' => '1'],
            ['taskKey' => 'b', 'occurrenceId' => '2'],
        ];

        self::assertSame($expected, $codec->decodeActive($json));

        $handle = fopen('php://temp', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, $json);
        $initial = $codec->decodeActiveWindowInitialStream($handle);
        self::assertSame([$expected[0]], $initial['entries']);
        self::assertFalse($initial['complete']);

        $continuation = $codec->decodeActiveWindowStream($handle, $initial['next'], 1, $initial['entries'][0]);
        self::assertSame([$expected[1]], $continuation['entries']);
        self::assertTrue($continuation['complete']);
        fclose($handle);
    }

    public function testWindowUsesByteContinuationAndRejectsStalePositions(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = $codec->encodeActive([
            ['taskKey' => 'a', 'occurrenceId' => '1'],
            ['taskKey' => 'b', 'occurrenceId' => '2'],
        ]);

        $first = $codec->decodeActiveWindow($json, 0, 1);
        self::assertSame([['taskKey' => 'a', 'occurrenceId' => '1']], $first['entries']);
        self::assertFalse($first['complete']);
        $second = $codec->decodeActiveWindow($json, $first['next'], 1);
        self::assertSame([['taskKey' => 'b', 'occurrenceId' => '2']], $second['entries']);
        self::assertTrue($second['complete']);

        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindow($json, 1, 1);
    }

    public function testInitialStreamingWindowRequiresCurrentSchemaBeforeYielding(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $handle = fopen('php://temp', 'w+b');
        self::assertIsResource($handle);
        fwrite($handle, '{"entries":[{"taskKey":"a","occurrenceId":"1"}]}');
        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindowInitialStream($handle);
        fclose($handle);
    }

    public function testInitialWindowDoesNotScanMalformedTailButContinuationRejectsIt(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},broken]}';

        $page = $codec->decodeActiveWindowInitial($json, 1);
        self::assertSame([['taskKey' => 'a', 'occurrenceId' => '1']], $page['entries']);
        self::assertFalse($page['complete']);

        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindowFast($json, $page['next'], 1);
    }

    public function testInitialWindowLeavesDuplicateAndUnsortedTailToStrictDecode(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        foreach ([
            '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"1"}]}',
            '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"0"}]}',
        ] as $json) {
            $page = $codec->decodeActiveWindowInitial($json, 1);
            self::assertFalse($page['complete']);
            try {
                $codec->decodeActive($json);
                self::fail('Strict decode should reject the tail.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFastContinuationDoesNotScanMalformedUnvisitedTail(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"b","occurrenceId":"2"},broken]}';
        $position = strpos($json, '{"taskKey":"b"');
        self::assertIsInt($position);

        $page = $codec->decodeActiveWindowFast($json, $position, 1);
        self::assertSame([['taskKey' => 'b', 'occurrenceId' => '2']], $page['entries']);
        self::assertFalse($page['complete']);
    }

    public function testFastContinuationRejectsMalformedEntryWhenConsumed(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},broken]}';
        $position = strpos($json, 'broken');
        self::assertIsInt($position);

        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindowFast($json, $position, 1);
    }

    public function testEncodeActiveRejectsUnsortedEntries(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $this->expectException(InvalidArgumentException::class);
        $codec->encodeActive([
            ['taskKey' => 'b', 'occurrenceId' => '1'],
            ['taskKey' => 'a', 'occurrenceId' => '1'],
        ]);
    }

    public function testWindowRejectsDuplicateEntriesWithoutMaterializingArray(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindow('{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"1"}]}', 0, 1);
    }

    public function testContinuationRejectsDuplicateAndCrossBoundaryUnsortedEntries(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        foreach ([
            '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"1"}]}',
            '{"schema":"dev","entries":[{"taskKey":"a","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"0"}]}',
        ] as $json) {
            $initial = $codec->decodeActiveWindowInitial($json, 1);
            try {
                $codec->decodeActiveWindowFast($json, $initial['next'], 1);
                self::fail('Continuation should reject invalid data after the page boundary.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function testUnsortedActiveIndexesAreRejectedDuringDecodeAndStreamingValidation(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();
        $json = '{"schema":"dev","entries":[{"taskKey":"b","occurrenceId":"1"},{"taskKey":"a","occurrenceId":"1"}]}';

        $this->expectException(InvalidArgumentException::class);
        $codec->decodeActiveWindow($json, 0, 1);
    }

    public function testDuplicateSchemaAndEntriesMembersAreRejected(): void
    {
        $codec = new FileExecutionLedgerIndexCodec();

        foreach ([
            '{"schema":"dev","schema":"dev","entries":[]}',
            '{"schema":"dev","entries":[],"entries":[]}',
        ] as $json) {
            try {
                $codec->decodeActive($json);
                self::fail('Expected duplicate JSON members to be rejected.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }
}
