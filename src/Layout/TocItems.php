<?php

namespace Shirahcan\Pml\Layout;

use Normalizer;
use Shirahcan\Pml\PmlParser;

/**
 * "Rebuild from headings" for a table of contents (pml-kit 0.26.0, T1a; owner 2026-11-17: the contents list "uses a
 * container with individual children for easy updates, additions, and removals").
 *
 * A `toc` with `toc_item` children draws exactly those items; a `toc` with none keeps the automatic list it always
 * drew. This builds the items from the document's own headings, so the designer (or Porter, preparing a post) fills
 * the container and an author then edits, adds, removes or reorders items by hand.
 *
 * ⚠ Every target is the id the PAGE gives its heading, computed exactly as pml-ui does (`src/pml/anchors.ts`,
 * `withDerivedAnchors` + `pageOutline`): a heading's own `anchor` when it has a valid one (reserved first), else a slug
 * of its words, de-duplicated in document order ("Fees", "Fees" -> `fees`, `fees-2`), and Markdown headings inside rich
 * text take their turn too. A target computed any other way would link to an id that does not exist. A derived target
 * follows the heading's words, so rebuild after renaming a heading (or give it its own anchor to pin it).
 */
final class TocItems
{
    /** The same shape PrimitiveSchema::ANCHOR and pml-ui's anchorId accept. */
    private const ANCHOR = '/^[a-z][a-z0-9-]{0,63}$/';

    /**
     * @param  list<array<string, mixed>>  $nodes  the document (comments and any depth of containers are fine)
     * @param  int  $depth  the deepest heading level listed: 2 (sections) or 3 (sections and their parts)
     * @return list<array{primitive: 'toc_item', props: array{label: string, target: string}}>
     */
    public static function fromNodes(array $nodes, int $depth = 2): array
    {
        $depth = $depth === 3 ? 3 : 2;
        $items = [];

        foreach (self::outline($nodes) as $entry) {
            if ($entry['level'] >= 2 && $entry['level'] <= $depth) {
                $items[] = ['primitive' => 'toc_item', 'props' => ['label' => $entry['text'], 'target' => $entry['anchor']]];
            }
        }

        return $items;
    }

    /**
     * The page's headings in document order with the anchors the renderer gives them.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{level: int, text: string, anchor: string}>
     */
    public static function outline(array $nodes): array
    {
        $used = [];
        $reserve = function (array $list) use (&$reserve, &$used): void {
            foreach ($list as $node) {
                if (! is_array($node) || PmlParser::isCommentNode($node)) {
                    continue;
                }
                if (($explicit = self::anchor($node['props']['anchor'] ?? null)) !== null) {
                    $used[$explicit] = true;
                }
                if (is_array($node['children'] ?? null)) {
                    $reserve($node['children']);
                }
            }
        };
        $reserve($nodes);

        $unique = function (string $base) use (&$used): string {
            $candidate = $base;
            for ($i = 2; isset($used[$candidate]); $i++) {
                $candidate = "{$base}-{$i}";
            }
            $used[$candidate] = true;

            return $candidate;
        };

        $out = [];
        $walkDoc = function (array $doc) use (&$walkDoc, &$out, $unique): void {
            if (($doc['type'] ?? null) === 'heading') {
                $text = trim(self::docText($doc));
                if ($text !== '') {
                    $out[] = ['level' => (int) ($doc['attrs']['level'] ?? 2), 'text' => $text, 'anchor' => $unique(self::slugify($text))];
                }

                return;
            }
            foreach ((array) ($doc['content'] ?? []) as $child) {
                if (is_array($child)) {
                    $walkDoc($child);
                }
            }
        };

        $walk = function (array $list) use (&$walk, &$out, $walkDoc, $unique): void {
            foreach ($list as $node) {
                if (! is_array($node) || PmlParser::isCommentNode($node)) {
                    continue;
                }
                $props = is_array($node['props'] ?? null) ? $node['props'] : [];
                $primitive = $node['primitive'] ?? null;

                if ($primitive === 'heading' && is_string($props['text'] ?? null) && trim($props['text']) !== '') {
                    $anchor = self::anchor($props['anchor'] ?? null) ?? $unique(self::slugify($props['text']));
                    $out[] = ['level' => (int) ($props['level'] ?? 2), 'text' => trim($props['text']), 'anchor' => $anchor];
                }
                if ($primitive === 'rich_text' && is_array($props['doc'] ?? null)) {
                    $walkDoc($props['doc']);
                }
                if (is_array($node['children'] ?? null)) {
                    $walk($node['children']);
                }
            }
        };
        $walk($nodes);

        return $out;
    }

    /** "Documents you'll need (2026)" -> "documents-you-ll-need-2026", as pml-ui's slugify. Always a valid anchor. */
    public static function slugify(string $text): string
    {
        $base = class_exists(Normalizer::class) ? (Normalizer::normalize($text, Normalizer::FORM_KD) ?: $text) : $text;
        $base = (string) preg_replace('/[\x{0300}-\x{036F}]/u', '', $base);
        $base = mb_strtolower($base);
        $base = (string) preg_replace('/[^a-z0-9]+/', '-', $base);
        $base = (string) preg_replace('/^-+|-+$/', '', $base);
        $base = (string) preg_replace('/-+$/', '', substr($base, 0, 56));

        if ($base === '') {
            return 'section';
        }

        return preg_match('/^[a-z]/', $base) === 1 ? $base : 's-'.$base;
    }

    private static function anchor(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::ANCHOR, $value) === 1 ? $value : null;
    }

    private static function docText(array $node): string
    {
        if (($node['type'] ?? null) === 'text') {
            return (string) ($node['text'] ?? '');
        }

        return implode('', array_map(fn ($child) => is_array($child) ? self::docText($child) : '', (array) ($node['content'] ?? [])));
    }
}
