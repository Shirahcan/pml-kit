<?php

namespace Shirahcan\Pml;

/**
 * `{{name}}` in PML words: a template variable a sender fills (an email's `{{recipient_name}}`), kept as literal text
 * (pml-email; owner 2026-11-16, A1: "variables stay {{name}}").
 *
 * In PML a `{...}` in words is a PSL expression, and `{{name}}` would read as a map literal and fail ("Expected ':'
 * after the map key"), so it was always an error: treating exactly `{{identifier}}` as literal words takes nothing
 * that used to work. The scanner, the rich-text compiler and the source writer all ask THIS class, so the three can
 * never disagree about what a variable is.
 */
final class TemplateVariable
{
    /** Exactly `{{identifier}}`: no spaces, no dots, no colon, so a real PSL expression is never mistaken for one. */
    public const PATTERN = '\{\{[A-Za-z_][A-Za-z0-9_]*\}\}';

    /** The variable starting at $offset in $source, or null when there is none there. */
    public static function at(string $source, int $offset): ?string
    {
        return preg_match('/\G'.self::PATTERN.'/', $source, $m, 0, $offset) === 1 ? $m[0] : null;
    }

    /**
     * Words written back as PML source: braces, backslashes and `<` escaped, except inside a `{{variable}}`, which
     * stays as an author typed it.
     */
    public static function escapeWords(string $words): string
    {
        $parts = preg_split('/('.self::PATTERN.')/', $words, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$words];

        return implode('', array_map(
            fn (string $part, int $i) => $i % 2 === 1 ? $part : str_replace(['\\', '{', '}', '<'], ['\\\\', '\\{', '\\}', '\\<'], $part),
            $parts,
            array_keys($parts),
        ));
    }
}
