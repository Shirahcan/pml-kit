<?php

namespace Shirahcan\Pml\Build;

use Shirahcan\Pml\PmlParser;

/**
 * Which elements an ask changed (moved from Portify's console, shared-page-build S2b 2026-11-23; first built for
 * Portify's owner 2026-11-09, "What changed": the console's "Changed N elements" opens
 * into the elements themselves, each one clickable on the canvas). Compared by each element's permanent
 * id (B1), so it names what the person sees, never a diff of code.
 *
 * - added / removed: a whole new (or gone) part is listed once, at its top;
 * - changed: the same element, its own words, settings or style differ (its children are compared on
 *   their own, so a new heading inside a section lists the heading, not the section);
 * - an element that only moved is not "changed".
 *
 * Deterministic, no AI: the facts of a change are counted, never written.
 */
final class DesignChanges
{
    public const MAX = 12;

    /** The words that name an element, in the order they say most about it. */
    private const NAMING_PROPS = ['text', 'heading', 'headline', 'title', 'label', 'question', 'name', 'value', 'alt', 'subhead', 'body'];

    /**
     * What a person calls the elements whose PML name is a tag (N1h-4d Loop 1, 2026-11-18: an email's paragraph read
     * "Changed the p"). Every other element keeps its own name ("heading", "button", "callout", and "row", which is a page's
     * layout row as well as an email table's).
     */
    private const PLAIN_NAMES = [
        'p' => 'paragraph', 'rich_text' => 'paragraph', 'small' => 'fine print', 'list_item' => 'list item',
        'blockquote' => 'quote', 'pre' => 'code block', 'hr' => 'divider',
    ];

    /**
     * @param  list<mixed>  $before
     * @param  list<mixed>  $after
     * @param  array<string, string>  $settings  a component's settings by name, in words (see {@see settingsOf()})
     * `counts` are over every change, not only the listed ones: the ONE count (2026-11-10), read by the
     * console's "Changed N elements" and by the reply a page Build writes when the model wrote none.
     *
     * @return array{changes: list<array{uid: string, label: string, how: string}>, total: int, counts: array{added: int, changed: int, removed: int}}
     */
    public static function between(array $before, array $after, array $settings = []): array
    {
        $was = self::index($before, $settings);
        $now = self::index($after, $settings);
        $changes = [];

        foreach ($now as $uid => $entry) {
            if (! isset($was[$uid])) {
                // Only the top of a new part: its children came with it.
                if ($entry['parent'] === null || isset($was[$entry['parent']])) {
                    $changes[] = ['uid' => $uid, 'label' => $entry['label'], 'how' => 'added'];
                }
            } elseif ($was[$uid]['own'] !== $entry['own']) {
                $changes[] = ['uid' => $uid, 'label' => $entry['label'], 'how' => 'changed'];
            }
        }

        foreach ($was as $uid => $entry) {
            if (! isset($now[$uid]) && ($entry['parent'] === null || isset($now[$entry['parent']]))) {
                $changes[] = ['uid' => $uid, 'label' => $entry['label'], 'how' => 'removed'];
            }
        }

        $counts = ['added' => 0, 'changed' => 0, 'removed' => 0];

        foreach ($changes as $change) {
            $counts[$change['how']]++;
        }

        return ['changes' => array_slice($changes, 0, self::MAX), 'total' => count($changes), 'counts' => $counts];
    }

    /**
     * A component's settings as the words an element bound to one starts with (owner 2026-11-10: a heading
     * that shows the Title setting read only "Changed the heading"): its default, or its name when it has
     * none. Later lists win, so pass the settings before an ask, then after it.
     *
     * @param  list<mixed>  ...$lists  each a component's `params` ({name, label, default, ...})
     * @return array<string, string>
     */
    public static function settingsOf(array ...$lists): array
    {
        $settings = [];

        foreach ($lists as $list) {
            foreach ($list as $param) {
                if (! is_array($param) || ! is_string($param['name'] ?? null) || $param['name'] === '') {
                    continue;
                }

                $default = is_string($param['default'] ?? null) ? trim($param['default']) : '';
                $label = is_string($param['label'] ?? null) ? trim($param['label']) : '';
                $words = $default !== '' ? $default : $label;

                if ($words !== '') {
                    $settings[$param['name']] = $words;
                }
            }
        }

        return $settings;
    }

    /**
     * Every element with an id: its own content (everything but its children that have ids), its nearest
     * parent with an id, and its name in words.
     *
     * @param  list<mixed>  $nodes
     * @param  array<string, string>  $settings
     * @return array<string, array{own: string, parent: ?string, label: string}>
     */
    private static function index(array $nodes, array $settings, ?string $parent = null, array &$out = []): array
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            $uid = is_string($node['uid'] ?? null) && $node['uid'] !== '' ? $node['uid'] : null;
            $children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));

            if ($uid !== null) {
                $own = $node;
                unset($own['uid'], $own['children']);
                // No settings is no settings: a stored design writes `props: []` where a parsed one leaves `props` out
                // (Portify TEST 2026-11-23: a post's Build that added one line read "Changed 47 elements").
                if (($own['props'] ?? null) === []) {
                    unset($own['props']);
                }
                // Children without an id (prose, a list's plain items) are part of this element's own content.
                $own['children'] = array_values(array_filter($children, static fn (array $c) => ! is_string($c['uid'] ?? null) && ! PmlParser::isCommentNode($c)));
                $out[$uid] = ['own' => (string) json_encode(self::sorted($own)), 'parent' => $parent, 'label' => self::label($node, $settings)];
            }

            self::index($children, $settings, $uid ?? $parent, $out);
        }

        return $out;
    }

    /** The same content in any key order is the same content. */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }

    /**
     * "heading “Pricing that grows”", "button “Book a call”", and a section by its own heading: "box “What
     * is a draw?”" (the first heading inside it, as the page's own summary always named a section).
     *
     * @param  array<string, string>  $settings  a component's settings in words: an element showing one is
     *                                           named by it ("heading “Pricing plan”")
     */
    public static function label(array $node, array $settings = []): string
    {
        $primitive = (string) ($node['primitive'] ?? '');
        $name = ! isset($node['component']) && isset(self::PLAIN_NAMES[$primitive])
            ? self::PLAIN_NAMES[$primitive]
            : str_replace(['-', '_'], ' ', (string) ($node['component'] ?? $node['primitive'] ?? 'element'));
        // A box drawn as a section, an aside, a header: called what it is drawn as.
        if (($node['primitive'] ?? null) === 'box' && is_string($node['props']['as'] ?? null) && $node['props']['as'] !== '') {
            $name = $node['props']['as'];
        }
        // Prose (a paragraph, a list item) has no naming prop: its words are its text runs, so it is named by them.
        $words = self::words($node, $settings) ?? self::firstHeading((array) ($node['children'] ?? []), $settings) ?? self::prose($node);

        return $words === null ? $name : $name.' “'.(mb_strlen($words) > 40 ? rtrim(mb_substr($words, 0, 39)).'…' : $words).'”';
    }

    /**
     * An element's own naming words, or null (a binding like {site.name} is not a name). A component's
     * setting (`{"$bind": "title"}`) is named by its words in `$settings`.
     *
     * @param  array<string, string>  $settings
     */
    private static function words(array $node, array $settings = []): ?string
    {
        $props = (array) ($node['props'] ?? []);

        foreach (self::NAMING_PROPS as $key) {
            $value = $props[$key] ?? null;

            // An element showing a component setting ({props.title}, PML v3) is named by the setting's words.
            if (is_array($value) && is_string($value['$expr'] ?? null)) {
                $value = preg_match('/^props\.([A-Za-z_][A-Za-z0-9_]*)$/', trim($value['$expr']), $m) === 1 ? ($settings[$m[1]] ?? null) : null;
            }

            $words = is_string($value) ? trim(preg_replace('/\s+/u', ' ', strip_tags($value)) ?? '') : '';

            if ($words !== '' && ! str_starts_with($words, '{')) {
                return $words;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $children
     * @param  array<string, string>  $settings
     */
    /** The first words of an element's own text runs (PML v3 prose), or null when it shows none. */
    private static function prose(array $node): ?string
    {
        $words = '';
        $walk = function (array $nodes) use (&$walk, &$words): void {
            foreach ($nodes as $child) {
                if (mb_strlen($words) > 60) {
                    return;
                }
                if (is_string($child)) {
                    $words .= $child;
                } elseif (is_array($child) && ! PmlParser::isCommentNode($child)) {
                    if (($child['primitive'] ?? null) === 'text' && is_string($child['props']['value'] ?? null)) {
                        $words .= $child['props']['value'];
                    }
                    $walk((array) ($child['children'] ?? []));
                }
            }
        };
        $walk((array) ($node['children'] ?? []));
        $words = trim((string) preg_replace('/\s+/u', ' ', $words));

        return $words === '' ? null : $words;
    }

    private static function firstHeading(array $children, array $settings = []): ?string
    {
        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            $own = ($child['primitive'] ?? null) === 'heading' || isset($child['props']['heading']) || isset($child['props']['headline']) ? self::words($child, $settings) : null;
            $found = $own ?? self::firstHeading((array) ($child['children'] ?? []), $settings);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}
