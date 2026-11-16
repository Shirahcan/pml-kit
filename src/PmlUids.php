<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\ElementCatalog;

/**
 * Every element's PERMANENT identity (B1, 2026-11-07).
 *
 * Paths (`[2, 0]`) change whenever anything moves, and Porter rewrites a whole page from source, so
 * nothing could tell "this heading" apart from "the heading that is now in its place": a change the
 * person made while Porter worked was overwritten, and a comment could not stay pinned. A `uid` is
 * written once by the editor (or here, for an element that arrives without one) and kept through moves,
 * Porter rewrites and Code view edits.
 *
 * A word run inside a paragraph or an inline element (PML v3 L4) is written bare, with no tag to carry
 * an id, so it gets none (its element's id covers it). Comment nodes (`<!-- -->`) get none either.
 */
final class PmlUids
{
    public const PATTERN = '/^[a-z0-9]{6,16}$/';

    /**
     * Give every element without an id a new one, and a NEW one to the second of any two that share
     * one (a pasted or duplicated element). Existing ids are never changed otherwise.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function ensure(array $nodes): array
    {
        $seen = [];

        return self::walk($nodes, $seen);
    }

    /**
     * The same tree with no ids: for asking "did anything change", where a new id is not a change.
     *
     * @param  list<mixed>  $nodes
     * @return list<mixed>
     */
    public static function strip(array $nodes): array
    {
        return array_map(static function ($node) {
            if (! is_array($node)) {
                return $node;
            }

            unset($node['uid']);

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::strip(array_values($node['children']));
            }

            return $node;
        }, array_values($nodes));
    }

    public static function fresh(): string
    {
        return str_pad(substr(base_convert(bin2hex(random_bytes(6)), 16, 36), 0, 8), 8, '0', STR_PAD_LEFT);
    }

    /** Plain prose: a rich text block holding only its Markdown (no look, no condition). */
    public static function isProse(array $node): bool
    {
        return ($node['primitive'] ?? null) === 'rich_text'
            && is_string($node['props']['markdown'] ?? null)
            && array_keys(array_diff_key((array) $node['props'], ['doc' => true])) === ['markdown']
            && array_intersect_key($node, array_flip(['style', 'children', 'key', 'part', 'if', 'else_if', 'else', 'each', 'on'])) === [];
    }

    /** A word run: plain `text` holding only its value, written bare inside a paragraph. */
    public static function isRun(array $node): bool
    {
        return ($node['primitive'] ?? null) === 'text'
            && array_keys((array) ($node['props'] ?? [])) === ['value']
            && array_intersect_key($node, array_flip(['style', 'children', 'key', 'part', 'if', 'else_if', 'else', 'each', 'on'])) === [];
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  array<string, true>  $seen
     * @param  bool  $words  these are the children of a paragraph or an inline element
     * @return list<mixed>
     */
    private static function walk(array $nodes, array &$seen, bool $words = false): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            if ($words && self::isRun($node)) {
                unset($node['uid']);
            } else {
                $uid = is_string($node['uid'] ?? null) && preg_match(self::PATTERN, $node['uid']) === 1 ? $node['uid'] : null;

                while ($uid === null || isset($seen[$uid])) {
                    $uid = self::fresh();
                }

                $seen[$uid] = true;
                $node['uid'] = $uid;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::walk(array_values($node['children']), $seen, ElementCatalog::holdsInline((string) ($node['primitive'] ?? '')));
            }

            $nodes[$i] = $node;
        }

        return array_values($nodes);
    }
}
