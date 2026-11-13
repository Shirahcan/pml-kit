<?php

namespace Shirahcan\Pml\Card;

/**
 * The social card's frame and what it may hold (shared program E2b, owner 2026-11-13: "proper real-world social
 * cards"). The PHP mirror of `@shirahcan/pml`'s `card/cardFrame.ts`: a card is an ordinary PML node list drawn by
 * the page renderer inside a 1200x630 frame (`PmlCard`), so there is no card element in the grammar; a card is
 * a node list that holds only the card subset.
 *
 * 1200x630 is what LinkedIn, Facebook, X's large card, Slack and iMessage draw. Text belongs inside the 60 px safe
 * margin and out of the bottom-left band where X writes the link's domain.
 */
final class CardFrame
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    public const SAFE_MARGIN = 60;

    /**
     * What `PmlCard` marks once its fonts and pictures have loaded, mirroring `CARD_READY_SELECTOR` in
     * @shirahcan/pml: the snapshot waits for it, so a card is never drawn half-loaded.
     */
    public const READY_SELECTOR = '[data-card-ready="true"]';

    /**
     * The card's type scale, mirroring `CARD_TYPE_SCALE` in @shirahcan/pml: page type sizes times 1.8, so a card
     * still reads when a feed shrinks it to about 500 px wide.
     */
    public const TYPE_SCALE = 1.8;

    /** A heading's size in px on a wide screen, before the card scale (the renderer's `md:` sizes). */
    public const HEADING_PX = ['sm' => 20, 'md' => 30, 'lg' => 36, 'xl' => 60];

    /** A heading's default size by level, as the renderer reads it. */
    public const HEADING_LEVEL_SIZE = [1 => 'lg', 2 => 'md', 3 => 'sm', 4 => 'sm'];

    /** Body text in px, before the card scale. */
    public const BODY_PX = ['sm' => 14, 'md' => 16, 'lg' => 18];

    /**
     * Mirrors `CARD_ELEMENTS` in @shirahcan/pml (Portify's CardFrameParityTest, phase E4d, keeps them equal; the
     * two packages cannot read each other). A picture, words and a few
     * marks; `credentials` is the host's pinned identity line. No forms, embeds, scripts, grids or effects.
     */
    public const ELEMENTS = [
        'box', 'layer', 'heading', 'text', 'p', 'span', 'strong', 'emphasis', 'small', 'mark',
        'image', 'icon', 'spacer', 'divider', 'credentials',
    ];

    /**
     * The nodes with everything outside the card subset removed (a removed node's children go with it).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function nodes(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! in_array($node['primitive'] ?? null, self::ELEMENTS, true)) {
                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::nodes($node['children']);
            }

            $out[] = $node;
        }

        return $out;
    }

    /**
     * What a card holds that it may not, each as a sentence naming the element and where it is. Empty when the card
     * is clean. The validator a parser hook calls (Portify's PmlParser until E2f moves the parser here).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    public static function violations(array $nodes, string $at = ''): array
    {
        $found = [];

        foreach (array_values($nodes) as $i => $node) {
            $where = $at === '' ? (string) ($i + 1) : $at.'.'.($i + 1);
            $name = (string) ($node['primitive'] ?? '');

            if ($name === '') {
                continue; // a source comment: dropped from the picture, not an error
            }

            if (! in_array($name, self::ELEMENTS, true)) {
                $found[] = "<{$name}> (at {$where}) cannot be on a card. A card holds only: ".implode(', ', self::ELEMENTS).'.';

                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $found = [...$found, ...self::violations($node['children'], $where)];
            }
        }

        return $found;
    }
}
