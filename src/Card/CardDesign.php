<?php

namespace Shirahcan\Pml\Card;

/**
 * Whether a person's (or the console's) card design may be STORED (shared program E4c-2a), the same answer for every
 * product: only the card subset is kept, and a design with nothing a card can show, or far beyond a card's size, is
 * refused in plain words.
 *
 * ⚠ STORING IS NOT TRUSTING. What this keeps is still run through {@see CardGuard} on every read and every publish
 * (pins back, refused words off the picture with the reason). This only decides what is worth keeping at all.
 */
final class CardDesign
{
    /** A card is a few blocks (CardChecks advises at 4): more top-level nodes than this is not a card. */
    public const MAX_TOP_NODES = 12;

    /** The stored design's ceiling, in bytes of JSON. The starters are about 1.5 KB. */
    public const MAX_BYTES = 20000;

    public const EMPTY = 'That card has nothing a card can show. Add a title or a picture.';

    public const TOO_LARGE = 'That card is too large to save. A card holds a few words and a picture.';

    /**
     * @param  array<int|string, mixed>  $nodes
     * @return array{nodes: list<array<string, mixed>>, error: ?string, dropped: list<string>}
     */
    public static function accept(array $nodes): array
    {
        $nodes = array_values(array_filter($nodes, 'is_array'));

        if (count($nodes) > self::MAX_TOP_NODES) {
            return ['nodes' => [], 'error' => self::TOO_LARGE, 'dropped' => []];
        }

        $dropped = CardFrame::violations($nodes);
        // Stored with its comments (a pinned comment survives a save); the picture never draws them.
        $kept = CardFrame::design($nodes);

        if (CardFrame::nodes($kept) === []) {
            return ['nodes' => [], 'error' => self::EMPTY, 'dropped' => $dropped];
        }

        if (strlen((string) json_encode($kept, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) > self::MAX_BYTES) {
            return ['nodes' => [], 'error' => self::TOO_LARGE, 'dropped' => $dropped];
        }

        return ['nodes' => $kept, 'error' => null, 'dropped' => $dropped];
    }
}
