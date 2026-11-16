<?php

declare(strict_types=1);

namespace Yiisoft\Scheduler\Command;

use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Throwable;

use function base64_encode;
use function preg_match;
use function preg_replace;
use function stream_isatty;
use function strlen;
use function substr;
use function trim;

/**
 * What the scheduler is doing right now, told to the terminal rather than to the reader of
 * the output: a scheduler waiting for its next run looks exactly like a dead one otherwise.
 *
 * The report is an OSC 7501 sequence, `ESC ] 7501 ; state=working:app=... ESC \`, which a
 * terminal that knows it shows beside the window and one that does not ignores. Only the
 * root record is written here: a record per running task would have to describe processes
 * this command does not own.
 *
 * @see https://www.superlogical.com/rex/docs/build/program-status
 */
final class ProgramStatus
{
    /** The program the records belong to, which the terminal shows beside the message. */
    private const APP = 'yii-scheduler';

    /**
     * The longest message a report carries. A terminal discards a sequence over 4096 bytes
     * whole, and base64 grows what it encodes by a third, so this leaves room to spare.
     */
    private const MESSAGE_LIMIT = 512;

    /**
     * @param OutputInterface|null $stream Where a report is written, or null to write none.
     */
    public function __construct(private readonly ?OutputInterface $stream = null) {}

    /**
     * The reporter for a command's output, which writes nothing unless the terminal is there
     * to read it: not when the output is redirected to a file or a pipe, not in quiet mode,
     * not when the output is undecorated (`--no-ansi`), and not when the user asked for none.
     */
    public static function of(OutputInterface $output, bool $enabled = true): self
    {
        // Errors and status go to the error stream, so the output of a command stays what it
        // was: pipe it on and the escape sequences do not travel with it.
        $stream = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        if (!$enabled || $stream->isQuiet() || !$stream->isDecorated() || !self::isTerminal($stream)) {
            return new self();
        }

        return new self($stream);
    }

    /** The scheduler is running and the terminal may say so until told otherwise. */
    public function working(string $message): void
    {
        $this->report('working', $message);
    }

    /** The work this command was given is over, and this is how it went. */
    public function done(string $message): void
    {
        $this->report('done', $message);
    }

    /** The command failed and stopped. */
    public function error(string $message): void
    {
        $this->report('error', $message);
    }

    private function report(string $state, string $message): void
    {
        if (null === $this->stream) {
            return;
        }

        $pairs = 'state=' . $state . ':app=' . self::APP;
        $message = self::line($message);

        if ('' !== $message) {
            $pairs .= ':msg=' . base64_encode($message);
        }

        try {
            // Raw: the sequence is for the terminal, and the formatter would read its brackets
            // as markup of its own.
            $this->stream->write("\033]7501;" . $pairs . "\033\\", false, OutputInterface::OUTPUT_RAW);
        } catch (Throwable) {
            // A status nobody could be told is not worth a failure of its own, and one of the
            // callers is a signal handler that lands wherever the loop happens to be: thrown
            // from there it would abort a tick or a shutdown halfway through.
        }
    }

    /**
     * One line of plain text, which is all a record holds: control characters are not allowed
     * in a report at all, and the message of a failure can be a stack of lines.
     */
    private static function line(string $message): string
    {
        $message = trim((string) preg_replace('/[\s\x00-\x1F\x7F]+/', ' ', $message));

        if (strlen($message) > self::MESSAGE_LIMIT) {
            $message = substr($message, 0, self::MESSAGE_LIMIT);

            // The cut can land inside a multi-byte character, and half of one is not text.
            while ('' !== $message && 1 !== preg_match('//u', $message)) {
                $message = substr($message, 0, -1);
            }
        }

        // A message that is not valid UTF-8 cannot be carried: the terminal drops the whole
        // report over it, status and all, so the state is reported without it.
        return 1 === preg_match('//u', $message) ? $message : '';
    }

    private static function isTerminal(OutputInterface $stream): bool
    {
        return $stream instanceof StreamOutput && stream_isatty($stream->getStream());
    }
}
