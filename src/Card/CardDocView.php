<?php

namespace Shirahcan\Pml\Card;

/**
 * The card document a builder loads (shared program E4c), the same shape for every product: what WILL be drawn
 * (composed and guarded on the server, so a builder never composes a copy of its own), the person's choice, and why
 * anything was dropped, restored or refused. The pml-ui `CardDoc` type mirrors it.
 *
 * `$stored` is the product's saved choice: `{source: automatic|picture|designed, nodes?, picture?, alt?}`.
 */
final class CardDocView
{
    public function __construct(private readonly CardGuard $guard = new CardGuard) {}

    /**
     * @param  array<string, mixed>  $stored
     * @return array{source: string, nodes: list<array<string, mixed>>, picture: ?array<string, mixed>, alt: ?string, refused: bool, reason: ?string, dropped: list<string>, findings: list<array{code: string, at: string, text: string}>, fell_back: bool, design: ?list<array<string, mixed>>, restored_identity: bool}
     */
    public function present(array $stored, CardFacts $facts, CardPolicy $policy): array
    {
        $source = in_array($stored['source'] ?? null, ['automatic', 'picture', 'designed'], true) ? $stored['source'] : 'automatic';
        $designed = self::designed($stored);
        $guard = $this->guard->guard($designed, $facts, $policy);
        $alt = is_string($stored['alt'] ?? null) && trim($stored['alt']) !== '' ? trim($stored['alt']) : null;

        return [
            'source' => $source,
            'nodes' => $guard['nodes'],
            'picture' => $source === 'picture' && is_array($stored['picture'] ?? null) ? $stored['picture'] : null,
            'alt' => $alt,
            'refused' => $guard['refused'],
            'reason' => $guard['reason'],
            'dropped' => $guard['dropped'],
            'findings' => $guard['findings'],
            // A designed card whose words the scan refused is drawn as the automatic card (`nodes`), but the person's
            // own design is handed back, so the card canvas reopens what they made, with the reason, instead of
            // silently swapping it for the automatic card.
            'fell_back' => $guard['fell_back'],
            'design' => $guard['fell_back'] ? $designed : null,
            'restored_identity' => $guard['restored_identity'],
        ];
    }

    /**
     * The stored design's nodes, or null when the choice is not a design.
     *
     * @param  array<string, mixed>  $stored
     * @return list<array<string, mixed>>|null
     */
    public static function designed(array $stored): ?array
    {
        return ($stored['source'] ?? null) === 'designed' && is_array($stored['nodes'] ?? null) ? array_values($stored['nodes']) : null;
    }
}
