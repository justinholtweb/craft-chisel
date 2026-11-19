<?php

declare(strict_types=1);

namespace JustinHoltWeb\Chisel\Runs;

use Closure;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\Output;

/**
 * Collects a command's output and hands the sanitised whole to `$flush` every half second,
 * so the control panel can follow along. Only complete lines are flushed until the end, so
 * a secret is never stored half-written (and half-masked). Output beyond the cap is dropped.
 */
final class StreamingOutput extends Output
{
    private string $buffer = '';

    private bool $truncated = false;

    private float $lastFlush = 0.0;

    /** @param  Closure(string $output, bool $truncated): void  $flush */
    public function __construct(
        private readonly OutputSanitizer $sanitizer,
        private readonly Closure $flush,
        private readonly int $maxBytes,
        private readonly float $interval = 0.5,
    ) {
        // Undecorated: no ANSI colours in stored output.
        parent::__construct(self::VERBOSITY_NORMAL, false, new OutputFormatter(false));
    }

    protected function doWrite(string $message, bool $newline): void
    {
        if ($this->truncated) {
            return;
        }

        $this->buffer .= $message.($newline ? "\n" : '');

        if (strlen($this->buffer) > $this->maxBytes) {
            // Cut at the last whole line, so no secret is kept half-written.
            $cut = mb_strcut($this->buffer, 0, $this->maxBytes);
            $end = strrpos($cut, "\n");
            $this->buffer = $end === false ? '' : substr($cut, 0, $end + 1);
            $this->truncated = true;
            $this->flush(true);

            return;
        }

        if (microtime(true) - $this->lastFlush >= $this->interval) {
            $this->flush(false);
        }
    }

    /** Pushes what's complete so far (everything, when `$final`). */
    public function flush(bool $final = true): void
    {
        $this->lastFlush = microtime(true);
        $text = $this->buffer;

        if (! $final && ! $this->truncated) {
            $end = strrpos($text, "\n");
            $text = $end === false ? '' : substr($text, 0, $end + 1);
        }

        ($this->flush)($this->sanitizer->clean($text), $this->truncated);
    }

    public function text(): string
    {
        return $this->sanitizer->clean($this->buffer);
    }

    public function truncated(): bool
    {
        return $this->truncated;
    }
}
