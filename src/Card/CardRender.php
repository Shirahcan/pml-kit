<?php

namespace Shirahcan\Pml\Card;

/**
 * One drawn card picture, or why there is none (shared program E2e). The product's renderer returns it (Portify:
 * the kit's `AiRender::snapshot()` on ai-service's browser). Never thrown: the publisher needs the reason.
 *  - retryable (busy, no browser, unreachable): keep the last good card and try again later;
 *  - not retryable (a host not registered, never ready, too large): a setup or design fault to fix.
 */
final class CardRender
{
    private function __construct(
        public readonly ?string $bytes,
        public readonly ?string $format,
        public readonly int $width,
        public readonly int $height,
        public readonly ?string $error,
        public readonly bool $retryable,
    ) {}

    public static function drawn(string $bytes, string $format, int $width = CardFrame::WIDTH, int $height = CardFrame::HEIGHT): self
    {
        return new self($bytes, $format, $width, $height, null, false);
    }

    public static function failed(string $error, bool $retryable): self
    {
        return new self(null, null, 0, 0, $error, $retryable);
    }

    public function succeeded(): bool
    {
        return $this->bytes !== null && $this->bytes !== '';
    }

    public function extension(): string
    {
        return $this->format === 'jpeg' ? 'jpg' : 'png';
    }

    public function mimeType(): string
    {
        return $this->format === 'jpeg' ? 'image/jpeg' : 'image/png';
    }
}
