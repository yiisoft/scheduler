<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Executor;

use Yiisoft\Scheduler\Exception\LogicException;

use function array_is_list;
use function is_string;
use function sprintf;
use function str_contains;
use function var_export;

use const PHP_BINARY;

/**
 * A PHP script to run in a process of its own.
 *
 * The binary is the one running the scheduler unless another is named, so a console entry point
 * started this way is the same PHP as the one that planned it, with the same extensions and the
 * same configuration. The script and its arguments are passed through as written, exactly as
 * {@see ProcessCommand} passes them: this is a PHP process, not a shell command line.
 */
final class PhpCommand implements CommandInterface
{
    /**
     * @param string $script The script to run, as a path.
     * @param array<array-key, mixed> $arguments The arguments for the script.
     * @param string|null $binary The PHP binary, or null for the one running this process.
     * @param array<array-key, mixed> $options Arguments for PHP itself, such as `-d` settings.
     * @param array<array-key, mixed> $environment Environment variables added to the ones inherited.
     * @param string|null $workingDirectory The directory to run in, or null for the current one.
     */
    public function __construct(
        private readonly string $script,
        private readonly array $arguments = [],
        private readonly ?string $binary = null,
        private readonly array $options = [],
        private readonly array $environment = [],
        private readonly ?string $workingDirectory = null,
    ) {
        if (null !== $binary && ('' === $binary || str_contains($binary, "\0"))) {
            throw new LogicException('The PHP binary cannot be empty or hold a NUL byte.');
        }

        if (!array_is_list($options)) {
            throw new LogicException('The arguments for PHP itself are an ordered list.');
        }

        foreach ($options as $option) {
            if (!is_string($option)) {
                throw new LogicException('The arguments for PHP itself are strings.');
            }
        }

        // Everything else is the same as a program and its arguments, and is checked there once
        // rather than twice here.
        $this->resolve();
    }

    public function __toString(): string
    {
        $description = sprintf('php(%s', var_export($this->script, true));

        foreach ($this->arguments as $argument) {
            $description .= ' ' . var_export($argument, true);
        }

        if (null !== $this->binary) {
            $description .= sprintf(', binary %s', var_export($this->binary, true));
        }

        foreach ($this->options as $option) {
            $description .= sprintf(', option %s', var_export($option, true));
        }

        /** @var array<string, string> $environment The constructor took nothing else. */
        $environment = $this->environment;

        foreach (ProcessCommand::describeEnvironment($environment) as $variable) {
            $description .= ', ' . $variable;
        }

        if (null !== $this->workingDirectory) {
            $description .= sprintf(', in %s', var_export($this->workingDirectory, true));
        }

        return $description . ')';
    }

    /**
     * The same command as a program to run, with the PHP binary in front of the script.
     *
     * The binary is read as the command is resolved rather than as it is declared: a command
     * handed to a worker process is run by the PHP that worker is, not by the one that wrote it
     * down.
     */
    public function resolve(): ProcessCommand
    {
        // "-f" names the script however it is written, so a relative path beginning with a "-"
        // is opened rather than read as an option of PHP's own, and "--" ends PHP's options so
        // that the arguments after it are the script's whatever they look like.
        return new ProcessCommand(
            $this->binary ?? PHP_BINARY,
            [...$this->options, '-f', $this->script, '--', ...$this->arguments],
            $this->environment,
            $this->workingDirectory,
        );
    }
}
