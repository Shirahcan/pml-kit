<?php

namespace Shirahcan\Pml\Layout;

use Shirahcan\Pml\PmlParser;

/**
 * Does a document follow its layout? (post layouts A3, owner 2026-11-16: "lock on structure only".)
 *
 * Deterministic, no AI. BLOCKS publishing only for structure: a required part missing, a repeating part outside its
 * count (the FAQs: exactly the layout's number), the calls to action out of stage order (awareness, consideration,
 * decision), a `[VERIFY` marker left anywhere (old posts), a note starting "VERIFY" (`layout.verify_note`, 0.27.3: how
 * a figure to check is written now), or a part still holding its template's bracketed example words
 * (`layout.unfilled`, 0.27.0: a post must never publish reading "[The question the post answers]"). Everything else (word and character counts, a call to action
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

            $examples = self::examples($part);
            $unfilled = [];

            foreach ($at as $instance) {
                foreach (self::leftExamples($examples, $instance['node']) as $example) {
                    $unfilled[$example] = true; // once per example per part, however many instances repeat it
                }

                foreach (self::ruleMisses($part, $instance['node']) as $miss) {
                    $findings[] = self::finding('warn', 'layout.rule', $part, $miss, null);
                }

                if ($part['kind'] === 'cta') {
                    $order[] = ['part' => $part, 'position' => $instance['position']];
                }
            }

            foreach (array_keys($unfilled) as $example) {
                $findings[] = ['excerpt' => $example] + self::finding('block', 'layout.unfilled', $part, "{$part['label']} still has the template's example words: \"{$example}\".", 'Write your own words in place of the bracketed example.');
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
                $range = $found[$within] ?? [];
                if ($range === []) {
                    continue;
                }

                $first = min(array_column($range, 'position'));
                $last = self::rangeEnd($layout, $within, $range, $found);

                if (self::before($instance['position'], $first) || self::before($last, $instance['position'])) {
                    $label = $layout->part($within)['label'] ?? $within;
                    $rule = $part['kind'] === 'cta' ? 'layout.cta_outside_range' : 'layout.outside_range';
                    $findings[] = self::finding('warn', $rule, $part, "{$part['label']} sits outside {$label}.", "Move it between the parts of {$label}, or right after the last one.");
                }
            }
        }

        // A figure to check, written as a note (0.27.3, owner 2026-11-17: "[VERIFY:...] placeholders ... should be surfaced
        // as visible PML comments", so nobody has to dig marks out of the words). Blocks until the note is deleted.
        foreach (self::verifyNotes($nodes) as [$note, $partKey]) {
            $findings[] = [
                'source' => 'layout', 'severity' => 'block', 'rule' => 'layout.verify_note',
                // The message says it once ("VERIFY: the fee" read "...remove the note: VERIFY: the fee"); the excerpt keeps
                // the note's own words, so the designer can find the note it is about.
                'message' => 'Check this, then remove its note: '.(trim((string) preg_replace('/^VERIFY\s*:?\s*/i', '', $note)) ?: $note), 'excerpt' => $note,
                'suggestion' => 'Check the figure against its official source, correct the words if needed, then remove the note.',
                'part' => $partKey,
            ];
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
            // A purpose note is not part of the page: it takes no position, so a part right after a note is right after
            // the part before it (0.27.2: the Classic starter's CTA, its note in front, read "outside Question section").
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            $position = $counter[0]++;
            $part = is_string($node['part'] ?? null) ? $node['part'] : null;

            if ($part !== null) {
                $found[$part][] = ['node' => $node, 'position' => $position, 'ancestors' => $ancestors];
                $index = array_key_last($found[$part]);
            }

            if (is_array($node['children'] ?? null)) {
                self::collect($node['children'], $part !== null ? [...$ancestors, $part] : $ancestors, $found, $counter);
            }

            if ($part !== null) {
                // The last position inside this instance, so "right after it" is the next one (0.27.1).
                $found[$part][$index]['end'] = $counter[0] - 1;
            }
        }
    }

    /**
     * The last position a part moving within `$within` may take: inside the last instance, right after it, or right
     * behind other parts that move within the same range and sit there (a data block, then the call to action). Inclusive
     * end (0.27.1): "after the 4th question section" is inside a post that has exactly four.
     *
     * @param  list<array<string, mixed>>  $range
     * @param  array<string, list<array<string, mixed>>>  $found
     */
    private static function rangeEnd(LayoutDefinition $layout, string $within, array $range, array $found): int
    {
        $last = max(array_column($range, 'end'));
        $movers = [];
        foreach ($layout->parts as $part) {
            if (($part['moves_within'] ?? null) === $within) {
                foreach ($found[$part['key']] ?? [] as $instance) {
                    $movers[$instance['position']] = $instance['end'];
                }
            }
        }

        // Walk the movers sitting one right behind the other after the last instance: each may start there.
        $end = $last;
        $cursor = $last + 1;
        while (isset($movers[$cursor])) {
            $end = $cursor;
            $cursor = $movers[$cursor] + 1;
        }

        return $end;
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

    /**
     * The template's own example words in a part's skeleton: every bracketed run (`[The question the post answers]`) in
     * the words a reader would read. Purpose notes are not words, and a `[VERIFY` mark is its own rule.
     *
     * @return list<string>
     */
    private static function examples(array $part): array
    {
        $found = [];

        foreach (self::withoutNotes((array) ($part['skeleton'] ?? [])) as $node) {
            foreach (is_array($node) ? self::words($node) : [] as $value) {
                preg_match_all('/\[[^\[\]\n]{3,}\]/u', $value, $m);
                foreach ($m[0] as $run) {
                    if (stripos($run, self::MARKER) !== 0) {
                        $found[$run] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * @param  list<string>  $examples
     * @return list<string> the examples still in this instance, word for word (its purpose notes left out)
     */
    private static function leftExamples(array $examples, array $node): array
    {
        if ($examples === []) {
            return [];
        }
        $text = implode("\n", self::words(['children' => self::withoutNotes([$node])]));

        return array_values(array_filter($examples, static fn (string $example) => str_contains($text, $example)));
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

    /**
     * Every note that starts with "VERIFY" (any case, any depth), with the part it is about: the part of the next node
     * that is not a note, else the part it sits inside.
     *
     * @return list<array{0: string, 1: ?string}>
     */
    private static function verifyNotes(array $nodes, ?string $inside = null): array
    {
        $out = [];
        $list = array_values($nodes);

        foreach ($list as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (PmlParser::isCommentNode($node)) {
                $text = trim((string) $node['comment']);
                // A pinned check marked resolved in the designer is settled, as a deleted one is (0.27.5, PinnedNotes).
                if (stripos($text, 'VERIFY') === 0 && ! PinnedNotes::isResolved($node)) {
                    $next = null;
                    for ($j = $i + 1; $j < count($list); $j++) {
                        if (is_array($list[$j]) && ! PmlParser::isCommentNode($list[$j])) {
                            $next = $list[$j];
                            break;
                        }
                    }
                    $part = is_string($next['part'] ?? null) ? $next['part'] : $inside;
                    $out[] = [$text, $part];
                }

                continue;
            }

            if (is_array($node['children'] ?? null)) {
                array_push($out, ...self::verifyNotes($node['children'], is_string($node['part'] ?? null) ? $node['part'] : $inside));
            }
        }

        return $out;
    }

    /** @return list<string> a short excerpt around every marker in the design and the fields */
    private static function markers(array $nodes, array $fields): array
    {
        $strings = [];
        // A purpose note (`<!-- ... -->`) may name a marker as an instruction ("never write a fee: write [check: ...]");
        // no reader sees it, so it never holds a post back.
        $read = self::withoutNotes($nodes);
        array_walk_recursive($read, static function ($value) use (&$strings) {
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

    /** @return list<mixed> the nodes with every comment node removed, at any depth */
    private static function withoutNotes(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (is_array($node) && PmlParser::isCommentNode($node)) {
                continue;
            }
            if (is_array($node) && is_array($node['children'] ?? null)) {
                $node['children'] = self::withoutNotes($node['children']);
            }
            $out[] = $node;
        }

        return $out;
    }

    private static function finding(string $severity, string $rule, array $part, string $message, ?string $suggestion): array
    {
        return ['source' => 'layout', 'severity' => $severity, 'rule' => $rule, 'message' => $message, 'excerpt' => $part['label'], 'suggestion' => $suggestion, 'part' => $part['key']];
    }
}
