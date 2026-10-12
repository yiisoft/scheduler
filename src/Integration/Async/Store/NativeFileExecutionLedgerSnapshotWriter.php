<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Integration\Async\Store;

use InvalidArgumentException;
use Throwable;

use function function_exists;
use function is_resource;
use function strlen;

/** Native local-filesystem snapshot writer. */
final class NativeFileExecutionLedgerSnapshotWriter implements FileExecutionLedgerSnapshotWriter
{
    public function write(string $path, string $data): void
    {
        $temporary = $path . '.' . bin2hex(random_bytes(8)) . '.tmp';
        $handle = @fopen($temporary, 'wb');
        if ($handle === false) {
            throw new InvalidArgumentException('Ledger snapshot cannot be written.');
        }

        try {
            $length = strlen($data);
            $written = 0;
            while ($written < $length) {
                $count = @fwrite($handle, substr($data, $written));
                if ($count === false || $count === 0) {
                    throw new InvalidArgumentException('Ledger snapshot cannot be written.');
                }
                $written += $count;
            }
            if (!@fflush($handle)) {
                throw new InvalidArgumentException('Ledger snapshot cannot be flushed.');
            }
            if (function_exists('fsync') && !@fsync($handle)) {
                throw new InvalidArgumentException('Ledger snapshot cannot be synced.');
            }
            if (!@fclose($handle)) {
                throw new InvalidArgumentException('Ledger snapshot cannot be closed.');
            }
            $handle = null;
            if (!@rename($temporary, $path)) {
                throw new InvalidArgumentException('Ledger snapshot cannot be committed.');
            }
        } catch (Throwable $e) {
            if (is_resource($handle)) {
                @fclose($handle);
            }
            @unlink($temporary);
            if ($e instanceof InvalidArgumentException) {
                throw $e;
            }
            throw new InvalidArgumentException('Ledger snapshot cannot be committed.', 0, $e);
        }
    }
}
