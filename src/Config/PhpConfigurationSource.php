<?php

declare(strict_types=1);

namespace Yiisoft\Schedule\Config;

use InvalidArgumentException;
use Yiisoft\Schedule\Executor\CommandExecutorRuntimeFactory;

use function hash_file;
use function is_file;
use function realpath;

/** Filesystem-backed PHP configuration source. */
final class PhpConfigurationSource implements RuntimeConfigurationSourceInterface
{
    private readonly string $canonicalPath;

    public function __construct(string $path)
    {
        $canonical = realpath($path);
        if ($canonical === false || !is_file($canonical)) {
            throw new InvalidArgumentException('Configuration source file does not exist.');
        }
        $this->canonicalPath = $canonical;
    }

    public function sourceId(): string
    {
        return $this->canonicalPath;
    }

    public function revision(): string
    {
        $revision = hash_file('sha256', $this->canonicalPath);
        if ($revision === false) {
            throw new InvalidArgumentException('Configuration source cannot be read.');
        }
        return $revision;
    }

    public function prepare(CommandExecutorRuntimeFactory $runtimeFactory): PreparedConfiguration
    {
        $loader = new PhpConfigurationLoader();
        $converter = new ConfigurationConverter();
        $guard = new ConfigurationVersionGuard();
        $prepared = $guard->execute($this, function () use ($loader, $converter, $runtimeFactory): PreparedConfiguration {
            // The loader evaluates the source and its factory once. Conversion
            // remains inside the same revision fence.
            return $converter->convert($loader->loadFile($this->canonicalPath), $runtimeFactory);
        });

        /** @var PreparedConfiguration $result */
        $result = $prepared->result;
        return $result->withSource($prepared->pin->sourceId, $prepared->pin->revision);
    }
}
