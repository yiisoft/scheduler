<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Exception\LogicException;

use function array_is_list;
use function get_debug_type;
use function implode;
use function is_string;
use function ksort;
use function sprintf;
use function str_contains;
use function var_export;

use const SORT_STRING;

/**
 * A program to run in a process of its own, named by its path and its arguments.
 *
 * Every argument is passed to the program as it is written here. Nothing is read as shell
 * syntax, so a quote, a space, a `$` or a `;` in an argument reaches the program as part of
 * that argument and starts nothing else: there is no shell between this and the program.
 */
final class ProcessCommand implements CommandInterface
{
    /**
     * @var list<string>
     */
    private readonly array $arguments;

    /**
     * @var array<string, string>
     */
    private readonly array $environment;

    /**
     * @param string $executable The program to run.
     * @param array<array-key, mixed> $arguments The arguments, each passed through as written.
     * @param array<array-key, mixed> $environment Environment variables added to the ones inherited.
     * @param string|null $workingDirectory The directory to run in, or null for the current one.
     */
    public function __construct(
        private readonly string $executable,
        array $arguments = [],
        array $environment = [],
        private readonly ?string $workingDirectory = null,
    ) {
        self::assertUsable($executable, 'The program to run');

        if (!array_is_list($arguments)) {
            throw new LogicException('The arguments of a program are an ordered list.');
        }

        foreach ($arguments as $position => $argument) {
            if (!is_string($argument)) {
                throw new LogicException(
                    sprintf(
                        'Argument %d is of type "%s"; arguments are passed to the program as strings.',
                        $position,
                        get_debug_type($argument),
                    ),
                );
            }

            // An empty argument is one a program can be given, and some are: a filter set to
            // nothing is not the same as no filter at all.
            self::assertUsable($argument, sprintf('Argument %d', $position), allowEmpty: true);
        }

        foreach ($environment as $name => $value) {
            if (!is_string($name) || !is_string($value)) {
                throw new LogicException('Environment variables are named by strings and hold strings.');
            }

            self::assertUsable($name, 'An environment variable name');

            // The name and the value reach the program as one string with an "=" between them,
            // so a name holding one would arrive as a shorter name and a longer value.
            if (str_contains($name, '=')) {
                throw new LogicException(sprintf('The environment variable name "%s" cannot hold an "=".', $name));
            }
            self::assertUsable($value, sprintf('The value of "%s"', $name), allowEmpty: true);
        }

        if (null !== $workingDirectory) {
            self::assertUsable($workingDirectory, 'The working directory');
        }

        /** @var list<string> $arguments */
        $this->arguments = $arguments;
        /** @var array<string, string> $environment */
        $this->environment = $environment;
    }

    public function __toString(): string
    {
        $parts = [var_export($this->executable, true)];

        foreach ($this->arguments as $argument) {
            $parts[] = var_export($argument, true);
        }

        $description = sprintf('process(%s', implode(' ', $parts));

        foreach (self::describeEnvironment($this->environment) as $variable) {
            $description .= ', ' . $variable;
        }

        if (null !== $this->workingDirectory) {
            $description .= sprintf(', in %s', var_export($this->workingDirectory, true));
        }

        return $description . ')';
    }

    /**
     * The program and its arguments, in the order a process takes them.
     *
     * @return non-empty-list<string>
     */
    public function getCommandLine(): array
    {
        return [$this->executable, ...$this->arguments];
    }

    /**
     * @return array<string, string>
     */
    public function getEnvironment(): array
    {
        return $this->environment;
    }

    public function getWorkingDirectory(): ?string
    {
        return $this->workingDirectory;
    }

    /**
     * The variables as a list of "name=value", by name, so that the same environment written in
     * another order reads the same way here: the program is handed a mapping, and the order the
     * entries were written in is nothing it can see.
     *
     * @param array<string, string> $environment
     *
     * @return list<string>
     */
    public static function describeEnvironment(array $environment): array
    {
        // As strings, for the reason a message payload is sorted that way: comparing the names
        // as PHP does would read "01" and 1 as one name.
        ksort($environment, SORT_STRING);

        $described = [];

        foreach ($environment as $name => $value) {
            $described[] = sprintf('%s=%s', $name, var_export($value, true));
        }

        return $described;
    }

    private static function assertUsable(string $value, string $what, bool $allowEmpty = false): void
    {
        if (!$allowEmpty && '' === $value) {
            throw new LogicException(sprintf('%s cannot be empty.', $what));
        }

        if (str_contains($value, "\0")) {
            throw new LogicException(sprintf('%s cannot hold a NUL byte.', $what));
        }
    }
}
