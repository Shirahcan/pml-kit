<?php

namespace Shirahcan\Pml\Layout;

/**
 * Does a document follow its layout? (post layouts A3, owner 2026-11-16: "lock on structure only".)
 *
 * Deterministic, no AI. BLOCKS publishing only for structure: a required part missing, a repeating part outside its
 * count (the FAQs: exactly the layout's number), the calls to action out of stage order (awareness, consideration,
 * decision), or a `[VERIFY` marker left anywhere. Everything else (word and character counts, a call to action
 * outside the part it may move in) WARNS and never blocks.
 *
 * Findings use the builder's preflight shape (pml-ui `DocumentFinding`: source, severity, rule, message, excerpt,
 * suggestion), plus `part` so the designer can jump to it.
 */
final class LayoutChecks
{
    public const MARKER = '[VERIFY';

    /** Props that hold an address or a setting, never words a reader reads. */
    private const NOT_WORDS = ['href', 'src', 'url', 'icon', 'id', 'variant', 'level', 'target', 'name', 'key', 'color', 'layout', 'align', 'size', 'doc'];

    /**
     * @param  list<array<string, mixed>>  $nodes  the document's PML, its parts marked with `part`
     * @param  array<string, mixed>  $fields  the document's own fields (a post's title, dek...), checked for markers
     * @return array{mayPublish: bool, findings: list<array<string, mixed>>}
     */
    public static function check(LayoutDefinition $layout, array $nodes, array $fields = []): array
    {
        $found = [];
        self::collect($nodes, [], $found);

        $findings = [];
        $order = [];

        foreach ($layout->parts as $part) {
            $at = $found[$part['key']] ?? [];
            $count = count($at);

            if ($part['kind'] === 'repeat') {
                $max = $part['max'];

                if ($count < $part['min'] || ($max !== null && $count > $max)) {
                    $want = $max === $part['min'] ? "exactly {$max}" : ($max === null ? "at least {$part['min']}" : "{$part['min']} to {$max}");
                    $findings[] = self::finding('block', 'layout.repeat_count', $part, "{$part['label']}: this layout takes {$want}, the post has {$count}.", $count < $part['min'] ? "Add {$part['label']} until there are {$want}." : "Remove {$part['label']} until there are {$want}.");
                }

                foreach ($at as $instance) {
                    if (isset($part['inside']) && ! in_array($part['inside'], $instance['ancestors'], true)) {
                        $findings[] = self::finding('block', 'layout.outside_its_part', $part, "{$part['label']} sits outside the part it belongs in.", 'Move it back inside '.(($layout->part($part['inside'])['label'] ?? $part['inside'])).'.');
                    }
                }
            } elseif ($count === 0 && $part['required']) {
                $findings[] = self::finding('block', 'layout.part_missing', $part, "{$part['label']} is missing.", "Add {$part['label']} back in its place.");
            }

            foreach ($at as $instance) {
                foreach (self::ruleMisses($part, $instance['node']) as $miss) {
                    $findings[] = self::finding('warn', 'layout.rule', $part, $miss, null);
                }

                if ($part['kind'] === 'cta') {
                    $order[] = ['part' => $part, 'position' => $instance['position']];
                }
            }
        }

        // The stages first appear in order as the reader goes down the page: awareness, then consideration, then
        // decision. Only each stage's FIRST appearance counts: a small "ask us" box (consideration) after the
        // decision call to action, then a closing decision banner, is the reference layout itself, not a mistake.
        $first = [];

        foreach ($order as $cta) {
            $stage = $cta['part']['stage'];

            if (! isset($first[$stage]) || $cta['position'] < $first[$stage]['position']) {
                $first[$stage] = $cta;
            }
        }

        $previous = null;

        foreach (LayoutDefinition::STAGES as $stage) {
            if (! isset($first[$stage])) {
                continue;
            }

            if ($previous !== null && $first[$stage]['position'] < $previous['position']) {
                $findings[] = self::finding('block', 'layout.cta_order', $first[$stage]['part'], "{$first[$stage]['part']['label']} comes before the {$previous['part']['stage']} call to action.", 'Order the calls to action awareness, then consideration, then decision.');
            } else {
                $previous = $first[$stage];
            }
        }

        foreach ($layout->parts as $part) {
            $within = $part['moves_within'] ?? null;

            foreach ($within !== null ? ($found[$part['key']] ?? []) : [] as $instance) {
                $range = array_column($found[$within] ?? [], 'position');

                if ($range !== [] && (self::before($instance['position'], min($range)) || self::before(max($range), $instance['position']))) {
                    $label = $layout->part($within)['label'] ?? $within;
                    $findings[] = self::finding('warn', 'layout.cta_outside_range', $part, "{$part['label']} sits outside {$label}.", "Move it between the parts of {$label}.");
                }
            }
        }

        foreach (self::markers($nodes, $fields) as $excerpt) {
            $findings[] = ['source' => 'layout', 'severity' => 'block', 'rule' => 'layout.verify_marker', 'message' => 'A figure is still marked [VERIFY]: check it against its official source.', 'excerpt' => $excerpt, 'suggestion' => 'Replace the marker with the checked figure and its source.', 'part' => null];
        }

        $blocked = array_filter($findings, static fn ($f) => $f['severity'] === 'block') !== [];

        return ['mayPublish' => ! $blocked, 'findings' => $findings];
    }

    /** Where every marked part sits: its node, its order in the document, and the parts around it. */
    private static function collect(array $nodes, array $ancestors, array &$found, array &$counter = [0]): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $position = $counter[0]++;
            $part = is_string($node['part'] ?? null) ? $node['part'] : null;

            if ($part !== null) {
                $found[$part][] = ['node' => $node, 'position' => $position, 'ancestors' => $ancestors];
            }

            if (is_array($node['children'] ?? null)) {
                self::collect($node['children'], $part !== null ? [...$ancestors, $part] : $ancestors, $found, $counter);
            }
        }
    }

    private static function before(int $a, int $b): bool
    {
        return $a < $b;
    }

    /** @return list<string> */
    private static function ruleMisses(array $part, array $node): array
    {
        $misses = [];
        $text = trim(implode(' ', self::words($node)));

        foreach ($part['rules'] as $unit => $range) {
            $n = $unit === 'words' ? ($text === '' ? 0 : count(preg_split('/\s+/u', $text))) : mb_strlen($text);
            $label = $unit === 'words' ? 'words' : 'characters';

            if (isset($range['min']) && $n < $range['min']) {
                $misses[] = "{$part['label']}: {$n} {$label}, the layout asks for at least {$range['min']}.";
            } elseif (isset($range['max']) && $n > $range['max']) {
                $misses[] = "{$part['label']}: {$n} {$label}, the layout asks for at most {$range['max']}.";
            }
        }

        return $misses;
    }

    /** @return list<string> the words a reader reads in this node and everything inside it */
    private static function words(array $node): array
    {
        $out = [];

        foreach ((array) ($node['props'] ?? []) as $key => $value) {
            if (is_string($value) && ! in_array($key, self::NOT_WORDS, true)) {
                $out[] = $value;
            }
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                array_push($out, ...self::words($child));
            }
        }

        return $out;
    }

    /** @return list<string> a short excerpt around every marker in the design and the fields */
    private static function markers(array $nodes, array $fields): array
    {
        $strings = [];
        array_walk_recursive($nodes, static function ($value) use (&$strings) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });
        array_walk_recursive($fields, static function ($value) use (&$strings) {
            if (is_string($value)) {
                $strings[] = $value;
            }
        });

        $out = [];

        foreach ($strings as $value) {
            $offset = 0;

            while (($at = mb_stripos($value, self::MARKER, $offset)) !== false) {
                $out[] = trim(mb_substr($value, max(0, $at - 40), 100));
                $offset = $at + 1;
            }
        }

        return $out;
    }

    private static function finding(string $severity, string $rule, array $part, string $message, ?string $suggestion): array
    {
        return ['source' => 'layout', 'severity' => $severity, 'rule' => $rule, 'message' => $message, 'excerpt' => $part['label'], 'suggestion' => $suggestion, 'part' => $part['key']];
    }
}
