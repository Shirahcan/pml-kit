<?php

namespace Shirahcan\Pml\Layout;

use Shirahcan\Pml\PmlParser;

/**
 * A layout as a READER would see a finished document made from it (growth templates A2, owner 2026-11-20: a template's
 * thumbnail "must accurately mirror the template", drawn "as a reader sees it").
 *
 * The starter is a form: guidance in brackets ("[A question readers ask]"), the template's notes, pictures with no
 * file. A thumbnail of that reads as a form, not as the page people will publish. The sample is the same structure with
 * every bracketed guidance replaced by neutral sample words of about its length (a heading stays heading-short), every
 * empty picture given the sample picture marker each renderer draws ({@see self::PICTURE}), and no notes. Words a
 * template keeps as examples (a template made from a post with its words kept) are shown as they are.
 */
final class LayoutSample
{
    /** The address an empty picture gets in a sample; a renderer draws its product's sample picture for it. */
    public const PICTURE = 'sample:picture';

    private const SENTENCES = [
        'This part explains what the reader needs to know, in plain words.',
        'Each step is short, so the page is easy to follow.',
        'It names the rule that applies and when it changed.',
        'A short example shows how it works in practice.',
        'The figures come with the date they were checked.',
        'It ends with what to do next.',
    ];

    private const HEADINGS = [
        'A question readers ask',
        'What changed this year',
        'How it works, step by step',
        'What it costs and how long it takes',
        'What to do next',
    ];

    /**
     * @return list<array<string, mixed>>
     */
    public static function nodes(LayoutDefinition $layout): array
    {
        $count = 0;

        return self::walk($layout->starter(), false, $count);
    }

    /** A picture its author has not chosen yet: no address, or one still waiting for its variable (`{{hero_image_url}}`). */
    public static function isUnchosenPicture(mixed $src): bool
    {
        $src = trim(is_string($src) ? $src : '');

        return $src === '' || preg_match('/^\{\{\s*[A-Za-z0-9_.]+\s*\}\}$/', $src) === 1;
    }

    /**
     * Only the pictures of a design, for a canvas: every picture not chosen yet takes `PICTURE` (the renderer draws its
     * product's sample picture), so a designer shows where a picture goes instead of a broken one. Words, notes and
     * everything else are left exactly as they are; this is for drawing, never for saving.
     *
     * @param  list<mixed>  $nodes
     * @return list<mixed>
     */
    public static function pictures(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            if (($node['primitive'] ?? null) === 'image' && self::isUnchosenPicture($node['props']['src'] ?? '')) {
                $nodes[$i]['props']['src'] = self::PICTURE;
            }
            if (is_array($node['children'] ?? null)) {
                $nodes[$i]['children'] = self::pictures($node['children']);
            }
        }

        return $nodes;
    }

    /**
     * @param  list<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    private static function walk(array $nodes, bool $inHeading, int &$count): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }
            $primitive = (string) ($node['primitive'] ?? '');
            $heading = $inHeading || in_array($primitive, ['heading', 'h1', 'h2', 'h3', 'title'], true);

            if ($primitive === 'text' && is_string($node['props']['value'] ?? null) && self::isGuidance($node['props']['value'])) {
                $node['props']['value'] = $heading ? self::heading($count++) : self::paragraph($node['props']['value'], $count++);
            }
            // An empty picture, or one still waiting for its variable ({{hero_image_url}}), draws the sample picture.
            if ($primitive === 'image' && self::isUnchosenPicture($node['props']['src'] ?? '')) {
                $node['props']['src'] = self::PICTURE;
            }
            foreach ((array) ($node['props'] ?? []) as $prop => $value) {
                if ($prop === 'value' || ! is_string($value) || ! self::isGuidance($value)) {
                    continue;
                }
                $node['props'][$prop] = match (true) {
                    $prop === 'alt' => 'A photo that sets the scene',
                    // A heading's own words (`<heading text=...>`) read as a heading, so the contents list is headings too.
                    $heading || in_array($prop, ['heading', 'question', 'title', 'label'], true) => self::heading($count++),
                    default => self::paragraph($value, $count++),
                };
            }
            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::walk($node['children'], $heading, $count);
            }
            $out[] = $node;
        }

        return $out;
    }

    /** Guidance is written in brackets, the whole text: "[The answer, 40 to 60 words.]". */
    private static function isGuidance(string $text): bool
    {
        $text = trim($text);

        return str_starts_with($text, '[') && str_ends_with($text, ']');
    }

    private static function heading(int $at): string
    {
        return self::HEADINGS[$at % count(self::HEADINGS)];
    }

    /** Sample sentences of about the guidance's length (one sentence at least, six at most). */
    private static function paragraph(string $guidance, int $at): string
    {
        $want = max(1, min(6, (int) round(mb_strlen($guidance) / 60)));
        $out = [];
        for ($i = 0; $i < $want; $i++) {
            $out[] = self::SENTENCES[($at + $i) % count(self::SENTENCES)];
        }

        return implode(' ', $out);
    }
}
