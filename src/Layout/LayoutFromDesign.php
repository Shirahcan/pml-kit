<?php

namespace Shirahcan\Pml\Layout;

use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PmlParser;

/**
 * A layout read FROM a design (growth templates A1, owner 2026-11-20: "templates could be created from a post").
 *
 * A post or email made from a layout carries each part's mark (`part="<key>"`) on its nodes, so the design can be read
 * back as parts: in the order they first appear, one part per key, a key seen more than once a repeating part, and each
 * part's skeleton its first instance. `$from` (the layout the design started from) supplies what a design cannot say:
 * labels, kinds, counts, stages, rules and where a moving part may go. The same conversion lets an admin edit a template
 * in the visual designer and save it back (`words: keep`).
 *
 * WORDS. `keep` keeps the design's words as the template's example text. `guidance` turns every word into the part's
 * guidance (the text of `$from`'s skeleton for that part, in order, else "[The <label> goes here]") and drops pictures,
 * so a new post starts from the structure and styling only.
 *
 * NOTES. The template's own notes (author "Template", or plain) are kept, unpinned, before the element they were pinned
 * to; a post's own notes (Porter's VERIFY checks, people's comments) are left out: they belong to that post.
 *
 * Unmarked content (a design made without a layout, or a block someone added) becomes `fill` parts, one per run of
 * unmarked nodes, keyed `section-1`, `section-2`.
 */
final class LayoutFromDesign
{
    public const WORDS = ['keep', 'guidance'];

    /**
     * @param  list<array<string, mixed>>  $nodes
     *
     * @throws InvalidPmlException when the design is empty or the result is not a valid layout
     */
    public static function build(array $nodes, string $key, string $name, string $kind, string $words, ?LayoutDefinition $from = null): LayoutDefinition
    {
        if (! in_array($words, self::WORDS, true)) {
            throw new InvalidPmlException('A template keeps the words ("keep") or turns them into guidance ("guidance").', 'layout.words');
        }

        $nodes = array_values(array_filter($nodes, 'is_array'));
        $uids = self::partsByUid($nodes);
        $order = [];
        $instances = [];
        $notes = [];
        $pending = [];
        $run = null;
        $loose = 0;

        foreach ($nodes as $node) {
            if (PmlParser::isCommentNode($node)) {
                $target = is_string($node['note']['for'] ?? null) ? ($uids[$node['note']['for']] ?? null) : null;
                if (self::isTemplateNote($node)) {
                    $target === null ? $pending[] = self::plain($node) : $notes[$target][] = self::plain($node);
                }

                continue;
            }
            $part = is_string($node['part'] ?? null) && $node['part'] !== '' ? $node['part'] : null;
            if ($part === null) {
                if ($run === null) {
                    $run = 'section-'.(++$loose);
                    $order[] = $run;
                }
                $instances[$run][0][] = $node;
                $notes[$run] = [...($notes[$run] ?? []), ...$pending];
                $pending = [];

                continue;
            }
            $run = null;
            if (! isset($instances[$part])) {
                $order[] = $part;
            }
            $instances[$part][] = [$node];
            $notes[$part] = [...($notes[$part] ?? []), ...$pending];
            $pending = [];
        }

        if ($order === []) {
            throw new InvalidPmlException('There is nothing in this design to make a template from.', 'layout.parts');
        }

        $parts = [];
        $emitted = [];
        foreach ($order as $partKey) {
            $known = $from?->part($partKey);
            $first = $instances[$partKey][0];
            $count = count($instances[$partKey]);
            $label = $known['label'] ?? ucfirst(str_replace(['-', '_'], ' ', $partKey));
            // Words are only ever a writer's in a fill or repeating part; the post's own parts keep what they draw.
            $partWords = in_array($known['kind'] ?? 'fill', ['fill', 'repeat'], true) ? $words : 'keep';
            $skeleton = [...self::once($notes[$partKey] ?? []), ...self::clean($first, $partWords, self::guidanceOf($known, $label))];
            $parts[] = self::part($partKey, $label, $known, $count, $skeleton, $instances[$partKey], $instances);

            // A part that lives inside this one is listed after it, as its layout had it: one that says `inside` (an FAQ
            // inside the FAQs) keeps its own skeleton from the design; one drawn within this part's skeleton (a question
            // section's short answer) keeps an empty one, since the parent's skeleton already holds it.
            foreach ($from?->parts ?? [] as $inner) {
                if (in_array($inner['key'], $order, true) || in_array($inner['key'], $emitted, true)) {
                    continue;
                }
                $nested = self::firstNested($first, $inner['key']);
                if (($inner['inside'] ?? null) === $partKey) {
                    // Empty when its layout's was (the parent's skeleton holds the instances), else the design's first one.
                    $innerSkeleton = $inner['skeleton'] === [] ? [] : ($nested !== null ? self::clean([$nested], $words, self::guidanceOf($inner, $inner['label'])) : $inner['skeleton']);
                } elseif ($nested !== null && $inner['skeleton'] === []) {
                    $innerSkeleton = [];
                } else {
                    continue;
                }
                $emitted[] = $inner['key'];
                $parts[] = self::part($inner['key'], $inner['label'], $inner, 1, $innerSkeleton, [], $instances);
            }
        }

        // A part its layout gives no skeleton (a client win picked later) never shows in a fresh design, so its absence
        // says nothing: it is carried over, after the part it followed in its layout.
        $have = array_column($parts, 'key');
        $previous = null;
        foreach ($from?->parts ?? [] as $known) {
            if (! in_array($known['key'], $have, true) && $known['skeleton'] === [] && ! isset($known['inside'])) {
                $at = $previous === null ? 0 : array_search($previous, array_column($parts, 'key'), true) + 1;
                array_splice($parts, $at, 0, [self::part($known['key'], $known['label'], $known, 1, [], [], $instances)]);
                $have[] = $known['key'];
            }
            if (in_array($known['key'], $have, true)) {
                $previous = $known['key'];
            }
        }

        return LayoutDefinition::fromArray([
            'key' => $key, 'name' => $name, 'kind' => $kind,
            'allowed_formats' => $from?->allowedFormats ?? [],
            'parts' => $parts,
        ]);
    }

    /**
     * One part: what the layout said about it when it knew it, else what the design shows.
     *
     * @param  array<string, mixed>|null  $known
     * @param  list<array<string, mixed>>  $skeleton
     * @param  list<list<array<string, mixed>>>  $seen  this part's instances in the design
     * @param  array<string, list<list<array<string, mixed>>>>  $all  every part's instances, for where a mover starts
     * @return array<string, mixed>
     */
    private static function part(string $key, string $label, ?array $known, int $count, array $skeleton, array $seen, array $all): array
    {
        $kind = $known['kind'] ?? ($count > 1 ? 'repeat' : 'fill');
        $out = ['key' => $key, 'label' => $label, 'kind' => $kind, 'required' => $known['required'] ?? true, 'locked' => $known['locked'] ?? false];

        if ($kind === 'repeat') {
            $out['min'] = $known['min'] ?? 1;
            $out['max'] = $known['max'] ?? null;
            if (isset($known['inside'])) {
                $out['inside'] = $known['inside'];
            }
        }
        foreach (['stage', 'variant', 'moves_within', 'starts_after'] as $field) {
            if (isset($known[$field])) {
                $out[$field] = $known[$field];
            }
        }
        $out['rules'] = $known['rules'] ?? [];
        $out['skeleton'] = $skeleton;

        return $out;
    }

    /**
     * The design's nodes as a skeleton: no permanent ids, no post-only notes, and (for `guidance`) no words or pictures.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<string>  $guidance
     * @return list<array<string, mixed>>
     */
    private static function clean(array $nodes, string $words, array $guidance): array
    {
        $at = 0;
        $walk = function (array $list) use (&$walk, $words, $guidance, &$at): array {
            $out = [];
            foreach ($list as $node) {
                if (! is_array($node)) {
                    continue;
                }
                if (PmlParser::isCommentNode($node)) {
                    if (self::isTemplateNote($node)) {
                        $out[] = self::plain($node);
                    }

                    continue;
                }
                unset($node['uid']);
                $primitive = (string) ($node['primitive'] ?? '');
                if ($words === 'guidance' && $primitive !== '') {
                    if ($primitive === 'text' && is_string($node['props']['value'] ?? null) && trim($node['props']['value']) !== '') {
                        $node['props']['value'] = $guidance[min($at++, count($guidance) - 1)];
                    } elseif ($primitive === 'image') {
                        unset($node['props']['src']);
                    }
                    foreach ((array) ($node['props'] ?? []) as $prop => $value) {
                        if ($prop !== 'value' && is_string($value) && trim($value) !== '' && (ElementCatalog::prop($primitive, (string) $prop)['type'] ?? null) === 'prose') {
                            $node['props'][$prop] = '['.ucfirst(str_replace('_', ' ', (string) $prop)).']';
                        }
                    }
                }
                if (is_array($node['children'] ?? null)) {
                    $node['children'] = $walk($node['children']);
                }
                $out[] = $node;
            }

            return $out;
        };

        return $walk($nodes);
    }

    /**
     * The guidance words of a part: its layout skeleton's text, in order, else one line naming it.
     *
     * @param  array<string, mixed>|null  $known
     * @return list<string>
     */
    private static function guidanceOf(?array $known, string $label): array
    {
        $out = [];
        $collect = function (array $nodes) use (&$collect, &$out): void {
            foreach ($nodes as $node) {
                if (! is_array($node) || PmlParser::isCommentNode($node)) {
                    continue;
                }
                if (($node['primitive'] ?? null) === 'text' && is_string($node['props']['value'] ?? null) && trim($node['props']['value']) !== '') {
                    $out[] = $node['props']['value'];
                }
                if (is_array($node['children'] ?? null)) {
                    $collect($node['children']);
                }
            }
        };
        $collect((array) ($known['skeleton'] ?? []));

        return $out !== [] ? $out : ['[The '.mb_strtolower($label).' goes here]'];
    }

    /**
     * Each note once: a repeating part pins its purpose note on every instance, and the skeleton needs it once.
     *
     * @param  list<array{comment: string}>  $notes
     * @return list<array{comment: string}>
     */
    private static function once(array $notes): array
    {
        $seen = [];

        return array_values(array_filter($notes, function (array $n) use (&$seen) {
            if (isset($seen[$n['comment']])) {
                return false;
            }

            return $seen[$n['comment']] = true;
        }));
    }

    /** @param  array<string, mixed>  $node */
    private static function isTemplateNote(array $node): bool
    {
        $author = $node['note']['author'] ?? null;

        return ! isset($node['note']) || $author === PinnedNotes::TEMPLATE_AUTHOR;
    }

    /** A pinned note back as the plain note a skeleton keeps ({@see PinnedNotes} pins it again when a post starts). */
    private static function plain(array $node): array
    {
        return ['comment' => (string) ($node['comment'] ?? '')];
    }

    /**
     * Each permanent id in the design => the part of the top-level node it is in (the element a note is pinned to).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, string>
     */
    private static function partsByUid(array $nodes): array
    {
        $map = [];
        $run = null;
        $loose = 0;
        foreach ($nodes as $node) {
            if (PmlParser::isCommentNode($node)) {
                continue;
            }
            $part = is_string($node['part'] ?? null) && $node['part'] !== '' ? $node['part'] : null;
            if ($part === null) {
                $run ??= 'section-'.(++$loose);
            } else {
                $run = null;
            }
            $owner = $part ?? $run;
            $collect = function (array $n) use (&$collect, &$map, $owner): void {
                if (is_string($n['uid'] ?? null)) {
                    $map[$n['uid']] = $owner;
                }
                foreach ((array) ($n['children'] ?? []) as $child) {
                    if (is_array($child)) {
                        $collect($child);
                    }
                }
            };
            $collect($node);
        }

        return $map;
    }

    /**
     * The first node marked `$key` inside these nodes (a part that sits inside another).
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return array<string, mixed>|null
     */
    private static function firstNested(array $nodes, string $key): ?array
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            foreach ((array) ($node['children'] ?? []) as $child) {
                if (! is_array($child)) {
                    continue;
                }
                if (($child['part'] ?? null) === $key) {
                    return $child;
                }
                if (($found = self::firstNested([$child], $key)) !== null) {
                    return $found;
                }
            }
        }

        return null;
    }
}
