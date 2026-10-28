<?php

declare(strict_types=1);

// A barrier-driven scope fixture: the leader records the authenticated facts,
// then exits while its same-session descendant remains observable.
$metadata = $argv[1] ?? '';
$release = $argv[2] ?? '';
$child = proc_open([
    \PHP_BINARY,
    '-r',
    'file_put_contents($argv[1], json_encode([getmypid(), posix_getpgid(getmypid()), posix_getsid(getmypid())], JSON_THROW_ON_ERROR)); while (!is_file($argv[2])) { usleep(1000); }',
    $metadata,
    $release,
], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!\is_resource($child)) {
    exit(23);
}
file_put_contents($metadata, json_encode([getmypid(), posix_getpgid(getmypid()), posix_getsid(getmypid())], \JSON_THROW_ON_ERROR));
exit(0);
