<?php

declare(strict_types=1);

$effect = $argv[1] ?? '';
$mode = $argv[2] ?? 'success';
if ($mode === 'timeout') {
    file_put_contents($effect, "started\n", \LOCK_EX);
    while (true) {
        usleep(10_000);
    }
}
$childSecret = 'black-box-child-secret';
$runtimeSecret = $argv[3] ?? '';
fwrite(\STDOUT, $childSecret . ' ' . $runtimeSecret . "\n");
fwrite(\STDERR, $childSecret . ' ' . $runtimeSecret . "\n");
$payload = [
    'argv' => array_map(static fn(string $value): string => hash('sha256', $value), \array_slice($argv, 3)),
    'environment' => getenv('FIXTURE_ENV'),
    'secretPresent' => getenv('FIXTURE_SECRET') !== false,
    'markerSeen' => is_file((string) getenv('BOOTSTRAP_MARKER')),
];
file_put_contents($effect, json_encode($payload, \JSON_THROW_ON_ERROR) . "\n", \LOCK_EX);
exit($mode === 'nonzero' ? 7 : 0);
