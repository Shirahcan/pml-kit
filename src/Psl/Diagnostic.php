<?php

namespace Shirahcan\Pml\Psl;

/**
 * One advisory finding from the {@see Analyzer}. Diagnostics ADVISE - they never block compilation
 * (aid-not-enforce). `error` means "this will very likely fault at runtime", `warning` means "this
 * is probably a mistake", `info` is a gentle nudge. Each carries a stable `code` (so the builder /
 * Porter can act on it) and a position (so the PSL pane can underline the exact spot).
 */
class Diagnostic
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const INFO = 'info';

    public function __construct(
        public readonly string $code,
        public readonly string $severity,
        public readonly string $message,
        public readonly int $line,
        public readonly int $col,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'severity' => $this->severity,
            'message' => $this->message,
            'line' => $this->line,
            'col' => $this->col,
        ];
    }
}
