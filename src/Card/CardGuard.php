<?php

namespace Shirahcan\Pml\Card;

/**
 * What a card becomes before it is saved or published (shared program E2c):
 *  - anything a card cannot hold is dropped, and named;
 *  - a pinned identity line the person or Porter removed comes back, and the person is told;
 *  - words the product's scan refuses never reach the picture: the card falls back to the automatic card, with the
 *    reason, and the PAGE is never blocked for it (owner 2026-11-13: "a failed card scan falls back").
 */
final class CardGuard
{
    public function __construct(private readonly DefaultCardComposer $composer = new DefaultCardComposer()) {}

    /**
     * @param  list<array<string, mixed>>  $nodes  the person's card, or null for the automatic card
     * @return array{nodes: list<array<string, mixed>>, dropped: list<string>, restored_identity: bool, fell_back: bool, reason: ?string, findings: list<array{code: string, at: string, text: string}>}
     */
    public function guard(?array $nodes, CardFacts $facts, CardPolicy $policy): array
    {
        $facts = $policy->pinsIdentity() && ! $facts->pinIdentity
            ? new CardFacts($facts->title, $facts->subtitle, $facts->kicker, $facts->picture, $facts->logo, $facts->price, true, $facts->alt)
            : $facts;

        $designed = $nodes !== null;
        $nodes ??= $this->composer->compose($facts);
        $dropped = CardFrame::violations($nodes);
        $nodes = CardFrame::nodes($nodes);
        $restored = false;

        if ($policy->pinsIdentity() && ! self::has($nodes, 'credentials')) {
            $nodes = self::pin($nodes);
            $restored = $designed;
        }

        $reason = $policy->scan(CardChecks::allWords($nodes));
        $fellBack = false;

        if ($reason !== null && $designed) {
            $nodes = $this->composer->compose($facts);
            $fellBack = true;
        }

        return [
            'nodes' => $nodes,
            'dropped' => $dropped,
            'restored_identity' => $restored,
            'fell_back' => $fellBack,
            'reason' => $reason,
            'findings' => CardChecks::findings($nodes),
        ];
    }

    private static function has(array $nodes, string $element): bool
    {
        foreach ($nodes as $node) {
            if (($node['primitive'] ?? null) === $element || (is_array($node['children'] ?? null) && self::has($node['children'], $element))) {
                return true;
            }
        }

        return false;
    }

    /** The identity line goes last in the card's first box, or last on the card when it has no box. */
    private static function pin(array $nodes): array
    {
        $line = ['primitive' => 'credentials', 'props' => ['heading' => '']];

        foreach ($nodes as $i => $node) {
            if (($node['primitive'] ?? null) === 'box') {
                $nodes[$i]['children'] = [...(is_array($node['children'] ?? null) ? $node['children'] : []), $line];

                return $nodes;
            }
        }

        return [...$nodes, $line];
    }
}
