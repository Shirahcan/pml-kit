<?php

namespace Shirahcan\Pml\Email;

/**
 * The product's look, for the email renderer (pml-email A3a): its name, colours and font. The renderer owns the
 * structure every mail client needs; the product owns how it looks. Colours are hex (`#4361EE`).
 *
 * Keys of `colors()`: `accent`, `on_accent` (text on the accent), `text`, `muted`, `surface` (a tinted band),
 * `border`, `page` (behind the email), `card` (the email's own background), `info`, `tip`, `success`, `warning`,
 * `danger`. A missing key falls back to the renderer's neutral default, never to nothing.
 */
interface EmailBrand
{
    public function productName(): string;

    /** @return array<string, string> */
    public function colors(): array;

    /** A system font stack mail clients have (A1: no web fonts). */
    public function fontStack(): string;
}
