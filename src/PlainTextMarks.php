<?php

namespace Shirahcan\Pml;

/**
 * Markdown emphasis that Porter wrote into a PLAIN-text prop, taken out (2026-11-09).
 *
 * A `<text value=...>`, a heading's `text` and a button's `label` print their words exactly as they are:
 * only words BETWEEN tags are Markdown. Mirroring a screenshot, Porter wrote `value="**New** Employer
 * files"` for a bold label, and the page showed the asterisks to visitors. The words stay; the marks
 * (`**bold**`, `__bold__`, `*italic*`) go. A lone asterisk ("5 * 3", "a*b") is not emphasis and stays.
 *
 * Applied to what Porter writes (SitePmlComposerService::parseEdit), never to a person's own edits.
 */
final class PlainTextMarks
{
    /** Each primitive's prop that prints its words as they are. */
    private const PLAIN = ['text' => 'value', 'heading' => 'text', 'button' => 'label'];

    /**
     * @param  array<int, mixed>  $nodes
     * @return array<int, mixed>
     */
    public static function strip(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            $prop = self::PLAIN[(string) ($node['primitive'] ?? '')] ?? null;

            if ($prop !== null && isset($node['props'][$prop]) && is_string($node['props'][$prop])) {
                $nodes[$i]['props'][$prop] = self::plain($node['props'][$prop]);
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $nodes[$i]['children'] = self::strip($node['children']);
            }
        }

        return $nodes;
    }

    /** The words without their Markdown emphasis marks. */
    public static function plain(string $words): string
    {
        $out = preg_replace(
            [
                '/\*\*(?=\S)(.+?)(?<=\S)\*\*/u',
                '/(?<!\w)__(?=\S)(.+?)(?<=\S)__(?!\w)/u',
                '/(?<![\w*])\*(?=[^\s*])([^*\n]*?[^\s*])\*(?![\w*])/u',
            ],
            ['$1', '$1', '$1'],
            $words,
        );

        return is_string($out) ? $out : $words;
    }
}
