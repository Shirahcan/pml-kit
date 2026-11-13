<?php

namespace Shirahcan\Pml\Card;

/**
 * Whether a card will read as a small picture in a feed (shared program E2c). Advisory findings in plain words, for
 * the console's one repair and the Sharing panel; never a block on publishing.
 *
 * Only what the node tree can tell: text size at the card scale, how many blocks, how many words. What needs the
 * drawn layout (the safe margin, X's domain band, contrast against the theme's colours) is checked where the card is
 * drawn, in the builder's card canvas (E4c).
 */
final class CardChecks
{
    /** Below this, card text is hard to read once a feed shrinks the card (about 12 px at 500 px wide). */
    public const MIN_TEXT_PX = 28;

    /** A label, a title, a price or one line under it: more and nothing gets read. */
    public const MAX_BLOCKS = 4;

    /** A card is glanced at, not read: past this many words, people stop. */
    public const MAX_WORDS = 30;

    private const WORD_ELEMENTS = ['heading', 'text', 'p'];

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{code: string, at: string, text: string}>
     */
    public static function findings(array $nodes): array
    {
        $findings = [];
        $blocks = 0;
        $words = 0;

        foreach (self::walk($nodes) as [$node, $at]) {
            $name = (string) ($node['primitive'] ?? '');

            if (! in_array($name, self::WORD_ELEMENTS, true)) {
                continue;
            }

            $text = self::words($node);

            if ($text === '') {
                continue;
            }

            $blocks++;
            $words += count(preg_split('/\s+/u', $text) ?: []);
            $px = self::px($node);

            if ($px < self::MIN_TEXT_PX) {
                $findings[] = [
                    'code' => 'text_too_small',
                    'at' => $at,
                    'text' => sprintf('"%s" would be %d px on the card, too small to read in a feed. Make it at least body size "md".', self::short($text), $px),
                ];
            }
        }

        if ($blocks > self::MAX_BLOCKS) {
            $findings[] = ['code' => 'too_many_blocks', 'at' => '', 'text' => "The card has {$blocks} blocks of words. Keep it to ".self::MAX_BLOCKS.': a title, one line and a label.'];
        }

        if ($words > self::MAX_WORDS) {
            $findings[] = ['code' => 'too_many_words', 'at' => '', 'text' => "The card has {$words} words. People glance at a card: keep it under ".self::MAX_WORDS.'.'];
        }

        return $findings;
    }

    /** The node's text size in px on the card. */
    public static function px(array $node): int
    {
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];

        if (($node['primitive'] ?? null) === 'heading') {
            $size = is_string($style['heading_size'] ?? null) && isset(CardFrame::HEADING_PX[$style['heading_size']])
                ? $style['heading_size']
                : (CardFrame::HEADING_LEVEL_SIZE[(int) ($node['props']['level'] ?? 1)] ?? 'md');

            return (int) round(CardFrame::HEADING_PX[$size] * CardFrame::TYPE_SCALE);
        }

        $size = is_string($style['body_size'] ?? null) && isset(CardFrame::BODY_PX[$style['body_size']]) ? $style['body_size'] : 'md';

        return (int) round(CardFrame::BODY_PX[$size] * CardFrame::TYPE_SCALE);
    }

    /** All the card's words: what a scan reads. */
    public static function allWords(array $nodes): string
    {
        $out = [];

        foreach (self::walk($nodes) as [$node]) {
            $text = self::words($node);

            if ($text !== '') {
                $out[] = $text;
            }
        }

        return implode("\n", $out);
    }

    private static function words(array $node): string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $text = $props['text'] ?? $props['value'] ?? null;

        return is_string($text) ? trim($text) : '';
    }

    private static function short(string $text): string
    {
        return mb_strlen($text) > 40 ? mb_substr($text, 0, 39).'…' : $text;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return \Generator<array{0: array<string, mixed>, 1: string}>
     */
    private static function walk(array $nodes, string $at = ''): \Generator
    {
        foreach (array_values($nodes) as $i => $node) {
            $where = $at === '' ? (string) ($i + 1) : $at.'.'.($i + 1);

            yield [$node, $where];

            if (isset($node['children']) && is_array($node['children'])) {
                yield from self::walk($node['children'], $where);
            }
        }
    }
}
