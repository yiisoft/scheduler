<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Binary;

use InvalidArgumentException;

use function dirname;
use function is_dir;
use function is_string;
use function is_writable;

/** Resolves the shared, source-scoped state root used by planner and probes. */
final class StateDirectoryResolver
{
    public function resolve(?string $explicit = null): string
    {
        $root = $explicit;
        if ($root === null || $root === '') {
            $root = getenv('SCHEDULER_STATE_DIR');
        }
        if (!is_string($root) || $root === '') {
            $xdg = getenv('XDG_STATE_HOME');
            if (is_string($xdg) && $xdg !== '') {
                $root = $xdg . '/scheduler';
            } else {
                $home = getenv('HOME');
                if (!is_string($home) || $home === '') {
                    throw new InvalidArgumentException('No safe writable scheduler state directory is available; use --state-dir.');
                }
                $root = $home . '/.local/state/scheduler';
            }
        }
        if (str_contains($root, "\0") || $root === '-' || str_starts_with($root, '-')) {
            throw new InvalidArgumentException('Scheduler state directory is invalid.');
        }
        $parent = $root;
        while (!is_dir($parent)) {
            $next = dirname($parent);
            if ($next === $parent) {
                throw new InvalidArgumentException('Scheduler state directory is not safely writable.');
            }
            $parent = $next;
        }
        if (!is_writable($parent)) {
            throw new InvalidArgumentException('Scheduler state directory is not safely writable.');
        }
        return $root;
    }
}
