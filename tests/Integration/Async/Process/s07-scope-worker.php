<?php

declare(strict_types=1);

$mode = $argv[1] ?? '';
$parentPath = $argv[2] ?? '';
$childPath = $argv[3] ?? $parentPath;

if ($mode === 'child') {
    file_put_contents($parentPath, json_encode(['pid' => getmypid(), 'pgid' => posix_getpgid(getmypid())], \JSON_THROW_ON_ERROR));
    while (true) {
        usleep(10000);
    }
}

$command = [\PHP_BINARY, __FILE__, 'child', $childPath];
$process = proc_open($command, [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
if (!\is_resource($process)) {
    exit(23);
}
$childPid = proc_get_status($process)['pid'];
file_put_contents($parentPath, json_encode(['pid' => getmypid(), 'pgid' => posix_getpgid(getmypid())], \JSON_THROW_ON_ERROR));

if ($mode === 'fast') {
    exit(0);
}

while (true) {
    usleep(10000);
}
