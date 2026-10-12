<?php

declare(strict_types=1);

$directory = $argv[1] ?? '';
$task = $argv[2] ?? '';
if ($directory === '' || $task === '') {
    fwrite(\STDERR, "fixture arguments are required\n");
    exit(64);
}

// The external command is deliberately not the worker identity: its parent is the PHP worker.
file_put_contents($directory . '/worker.pid', (string) posix_getppid() . "\n", \LOCK_EX);
file_put_contents($directory . '/external.pid', (string) getmypid() . "\n", \LOCK_EX);
file_put_contents($directory . '/' . $task . '.effect', $task . "\n", \LOCK_EX);
file_put_contents($directory . '/' . $task . '.identity.json', json_encode([
    'source' => realpath((string) getenv('SCHEDULER_ACCEPTANCE_SOURCE')) ?: '',
    'schedule' => 'default',
    'task' => $task === 'external-effect' ? 'external-task' : 'cron-task',
], \JSON_THROW_ON_ERROR));

$ready = (string) getenv('SCHEDULER_ACCEPTANCE_READY');
$release = (string) getenv('SCHEDULER_ACCEPTANCE_RELEASE');
if ($task === 'external-effect' && $ready !== '' && $release !== '') {
    // Open the child->parent readiness writer and the parent->child release
    // reader as separate directional endpoints. The child owns all reads here;
    // the parent never receives a copy of its own release message.
    $output = fopen($ready, 'w');
    if ($output === false) {
        fwrite(\STDERR, "fixture readiness could not be opened\n");
        exit(65);
    }
    // r+ avoids a directional FIFO open deadlock while retaining the child
    // reader endpoint; the parent owns the opposite (write) direction.
    $input = fopen($release, 'r+');
    if ($input === false || !stream_set_blocking($input, false)) {
        fwrite(\STDERR, "fixture release could not be configured\n");
        exit(65);
    }
    fwrite($output, "ready\n");
    fclose($output);
    stream_set_blocking($input, true);
    if (fgets($input) !== "release\n") {
        fwrite(\STDERR, "fixture release was not received\n");
        exit(65);
    }
    file_put_contents($directory . '/external.completed', "succeeded\n", \LOCK_EX);
    fclose($input);
} else {
    file_put_contents($directory . '/' . $task . '.completed', "succeeded\n", \LOCK_EX);
}
