<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Tests\Config;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Yiisoft\Schedule\Config\PreparedConfiguration;

final class PreparedConfigurationAggregateValidationTest extends TestCase
{
    public function testOversizedSourceIdentityIsRejectedByAggregateValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata string is oversized or contains NUL.');

        new PreparedConfiguration(sourceId: str_repeat('x', 65537), sourceRevision: 'revision');
    }

    public function testUnsupportedSourceIdentityValueIsRejectedByAggregateValidation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Metadata string is oversized or contains NUL.');

        new PreparedConfiguration(sourceId: "source\0id", sourceRevision: 'revision');
    }
}
