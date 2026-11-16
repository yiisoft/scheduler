<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Tests\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Yiisoft\Scheduler\Command\ProgramStatus;

use function base64_decode;
use function base64_encode;
use function fclose;
use function fopen;
use function preg_match;
use function rewind;
use function str_repeat;
use function stream_get_contents;
use function strlen;

final class ProgramStatusTest extends TestCase
{
    public function testStateAndMessageAreReported(): void
    {
        $output = $this->stream();

        (new ProgramStatus($output))->working('Running the tasks that are due.');

        $this->assertSame(
            "\033]7501;state=working:app=yii-scheduler:msg="
            . base64_encode('Running the tasks that are due.')
            . "\033\\",
            $this->read($output),
        );
    }

    public function testEveryStateHasItsOwnReport(): void
    {
        $output = $this->stream();
        $status = new ProgramStatus($output);

        $status->done('Ran 2 task(s).');
        $status->error('The checkpoint could not be read.');

        $this->assertSame(
            "\033]7501;state=done:app=yii-scheduler:msg=" . base64_encode('Ran 2 task(s).') . "\033\\"
            . "\033]7501;state=error:app=yii-scheduler:msg="
            . base64_encode('The checkpoint could not be read.') . "\033\\",
            $this->read($output),
        );
    }

    /**
     * A report holds no control characters at all, and the message of a failure often spans
     * several lines.
     */
    public function testMessageIsReportedAsOneLine(): void
    {
        $output = $this->stream();

        (new ProgramStatus($output))->error("Task failed:\n  at line 11\r\n");

        $this->assertSame('Task failed: at line 11', $this->message($output));
    }

    /** A line separator breaks a line as surely as a newline does. */
    public function testUnicodeSeparatorsAreReportedAsOneLine(): void
    {
        $stream = $this->stream();

        (new ProgramStatus($stream))->error("Task failed:\u{2028}at line 11\u{2029}of 40\u{00A0}in all");

        $this->assertSame('Task failed: at line 11 of 40 in all', $this->message($stream));
    }

    /**
     * The message can be whatever a task put in its error, and working on the whole of one
     * of those is how a status report runs a command out of memory.
     */
    public function testAMessageOfAnyLengthIsBounded(): void
    {
        $stream = $this->stream();

        (new ProgramStatus($stream))->error(str_repeat('a', 2_000_000));

        $this->assertSame(str_repeat('a', 512), $this->message($stream));
    }

    /**
     * A terminal discards a report over 4096 bytes whole, so a long message is cut rather
     * than left to take the state with it.
     */
    public function testLongMessageIsCutWithoutBreakingACharacter(): void
    {
        $output = $this->stream();

        (new ProgramStatus($output))->error(str_repeat('ы', 400));

        $reported = $this->message($output);

        $this->assertLessThan(4096, strlen($this->read($output)));
        $this->assertSame(1, preg_match('//u', $reported));
        $this->assertSame(str_repeat('ы', 256), $reported);
    }

    /** The state still reaches the terminal when the message cannot. */
    public function testMessageThatIsNotUtf8IsLeftOut(): void
    {
        $output = $this->stream();

        (new ProgramStatus($output))->error("\xB1\x31 went wrong");

        $this->assertSame("\033]7501;state=error:app=yii-scheduler\033\\", $this->read($output));
    }

    /**
     * The status is told from a signal handler as well, which lands wherever the loop happens
     * to be: a write that failed must not be thrown from there.
     */
    public function testWriteThatFailsIsNotThrown(): void
    {
        $handle = fopen('php://memory', 'wb+');
        $output = new StreamOutput($handle);

        fclose($handle);

        (new ProgramStatus($output))->working('Running.');

        $this->expectNotToPerformAssertions();
    }

    public function testNothingIsReportedWithoutAStream(): void
    {
        $status = new ProgramStatus();

        $status->working('Running.');
        $status->done('Done.');
        $status->error('Failed.');

        $this->expectNotToPerformAssertions();
    }

    public function testNothingIsReportedWhenTheUserAsksForNone(): void
    {
        $output = $this->stream();

        ProgramStatus::of($output, false)->working('Running.');

        $this->assertSame('', $this->read($output));
    }

    /**
     * Redirected to a file or piped on, which is every cron invocation: the sequences would
     * end up in the log instead of on a terminal.
     */
    public function testNothingIsReportedWhenTheOutputIsNotATerminal(): void
    {
        $output = $this->stream();

        ProgramStatus::of($output)->working('Running.');

        $this->assertSame('', $this->read($output));
    }

    public function testNothingIsReportedInQuietMode(): void
    {
        $output = $this->stream(OutputInterface::VERBOSITY_QUIET);

        ProgramStatus::of($output)->working('Running.');

        $this->assertSame('', $this->read($output));
    }

    public function testNothingIsReportedWithoutDecoration(): void
    {
        $output = $this->stream(OutputInterface::VERBOSITY_NORMAL, false);

        ProgramStatus::of($output)->working('Running.');

        $this->assertSame('', $this->read($output));
    }

    private function stream(
        int $verbosity = OutputInterface::VERBOSITY_NORMAL,
        bool $decorated = true,
    ): StreamOutput {
        return new StreamOutput(fopen('php://memory', 'wb+'), $verbosity, $decorated);
    }

    private function read(StreamOutput $output): string
    {
        rewind($output->getStream());

        return (string) stream_get_contents($output->getStream());
    }

    private function message(StreamOutput $output): string
    {
        preg_match('/msg=([^\033]*)/', $this->read($output), $matches);

        return (string) base64_decode($matches[1] ?? '', true);
    }
}
