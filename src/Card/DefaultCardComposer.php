<?php

namespace Shirahcan\Pml\Card;

use InvalidArgumentException;

/**
 * The automatic social card (shared program E2b, owner 2026-11-13: every page gets a real card with no AI and
 * nothing to do). Picks a starter from the facts and fills it:
 *  - `offer` when the facts carry a price;
 *  - `photo` when there is a picture;
 *  - `headline` otherwise.
 *
 * Starters are DATA (`resources/cards/*.json`), not code, themed only by palette tokens, so a product can add its
 * own by passing its own folder. A starter node may carry a `card` key the composer reads and strips:
 *  - `max`: the longest the slot's words may be (cut at a word, with an ellipsis);
 *  - `requires`: the fact without which the node is dropped (an absent subtitle leaves no empty line);
 *  - `when`: `pin_identity` keeps the node only when the host pins its identity line.
 *
 * Deterministic: the same facts give byte-identical nodes, so a card's hash (E2e) only changes with its facts.
 */
final class DefaultCardComposer
{
    public const STARTERS = ['headline', 'photo', 'offer'];

    public function __construct(private readonly ?string $startersPath = null) {}

    public function starterFor(CardFacts $facts): string
    {
        return $facts->price !== null ? 'offer' : ($facts->picture !== null ? 'photo' : 'headline');
    }

    /** @return list<array<string, mixed>> the card's PML nodes, only the card subset */
    public function compose(CardFacts $facts, ?string $starter = null): array
    {
        $starter ??= $this->starterFor($facts);
        $values = $facts->placeholders();
        $nodes = CardFrame::nodes($this->fill($this->starter($starter)['nodes'] ?? [], $values, $facts));

        // A card is glanced at: past the word budget the subtitle goes, so the automatic card passes its own checks.
        $words = count(preg_split('/\s+/u', CardChecks::allWords($nodes), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        if ($words > CardChecks::MAX_WORDS && $facts->subtitle !== null) {
            return $this->compose(new CardFacts($facts->title, null, $facts->kicker, $facts->picture, $facts->logo, $facts->price, $facts->pinIdentity, $facts->alt), $starter);
        }

        return $nodes;
    }

    /** @return array{name: string, nodes: list<array<string, mixed>>} */
    public function starter(string $name): array
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $name) !== 1) {
            throw new InvalidArgumentException("Unknown card starter [{$name}].");
        }

        $path = ($this->startersPath ?? dirname(__DIR__, 2).'/resources/cards').'/'.$name.'.json';
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        if (! is_array($data) || ! isset($data['nodes']) || ! is_array($data['nodes'])) {
            throw new InvalidArgumentException("Unknown card starter [{$name}].");
        }

        return $data;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, string>  $values
     * @return list<array<string, mixed>>
     */
    private function fill(array $nodes, array $values, CardFacts $facts): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $card = is_array($node['card'] ?? null) ? $node['card'] : [];
            unset($node['card']);

            if (isset($card['requires']) && ($values[(string) $card['requires']] ?? '') === '') {
                continue;
            }

            if (($card['when'] ?? null) === 'pin_identity' && ! $facts->pinIdentity) {
                continue;
            }

            $max = isset($card['max']) ? max(1, (int) $card['max']) : null;

            foreach (['props', 'style'] as $part) {
                if (isset($node[$part]) && is_array($node[$part])) {
                    foreach ($node[$part] as $key => $value) {
                        if (is_string($value) && str_contains($value, '{{')) {
                            $filled = $this->substitute($value, $values);
                            $node[$part][$key] = $max !== null && $part === 'props' ? self::cut($filled, $max) : $filled;
                        }
                    }
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->fill($node['children'], $values, $facts);
            }

            $out[] = $node;
        }

        return $out;
    }

    /** @param array<string, string> $values */
    private function substitute(string $text, array $values): string
    {
        return trim((string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn (array $m): string => $values[$m[1]] ?? '', $text));
    }

    /** At most $max characters, cut at the last whole word with an ellipsis; a single long word is cut hard. */
    public static function cut(string $text, int $max): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        if (mb_strlen($text) <= $max) {
            return $text;
        }

        $head = mb_substr($text, 0, $max - 1);
        $space = mb_strrpos($head, ' ');

        return rtrim($space !== false && $space > 0 ? mb_substr($head, 0, $space) : $head, " ,.;:").'…';
    }
}
