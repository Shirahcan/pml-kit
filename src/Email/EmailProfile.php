<?php

namespace Shirahcan\Pml\Email;

use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\PmlParser;

/**
 * What an EMAIL may hold (shared program, pml-email A1/A2, owner 2026-11-16: "the email should also move to a visual
 * editor + the studio"). An email is an ordinary PML node list, like a page or a card, restricted to what mail clients
 * honour and rendered by the email renderer, never by the web renderer.
 *
 * ⚠ REFUSED, NEVER SILENTLY DROPPED. Unlike a card (CardFrame drops and names), an email is a message a real person
 * receives: a dropped block is a sentence the sender meant to send and nobody sees vanish. So the profile returns
 * violations and the caller refuses the save; it never edits the design.
 *
 * Every element is an existing PML primitive (A1 "Decided"); nothing is invented here. Expressions, events and
 * conditional content are out of the first version (senders pass different variables instead), as are web-only
 * effects (gradients, motion, carousels, placement, per-breakpoint values, web fonts, shadows).
 */
final class EmailProfile
{
    /** The elements an email may hold. `header` / `footer` are the standard chrome partials' roots (A4). */
    public const ELEMENTS = [
        'box', 'grid', 'header', 'footer',
        'heading', 'text', 'p', 'rich_text', 'span', 'strong', 'emphasis', 'small', 'mark', 'link', 'br', 'code',
        'list', 'list_item', 'blockquote', 'table', 'row', 'cell',
        'divider', 'spacer', 'image', 'button', 'callout', 'pre',
    ];

    /** The style keys a mail client honours, as tables and inline styles. */
    public const STYLE_KEYS = [
        'align', 'width', 'padding', 'padding_x', 'margin_y', 'background', 'heading_size', 'body_size',
        'text_color', 'font_weight', 'line_height', 'letter_spacing', 'text_transform', 'border', 'border_color', 'radius',
    ];

    /**
     * Values of an allowed key that mail clients do not honour reliably: gradient and picture backgrounds (Outlook
     * drops them), a custom colour or pixel padding (no token to keep the canvas and the inbox in step).
     */
    private const REFUSED_STYLE_VALUES = [
        'background' => ['gradient', 'image', 'custom'],
        'padding' => ['custom'],
    ];

    /** Columns a mail client can lay out side by side (they stack on a phone). */
    public const MAX_COLUMNS = 2;

    /** Heading levels an email uses: one title and two levels of section headings. */
    public const MAX_HEADING_LEVEL = 3;

    /** The control fields a design may not carry in an email (no expressions, events or loops). */
    private const CONTROL = ['if', 'else_if', 'else', 'each', 'on'];

    /**
     * Everything the design holds that an email may not, each with where it is and a sentence a person reads.
     * Empty when the design is a valid email.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  string|null  $parent  the element these nodes sit in (null: the top of the email)
     * @return list<array{code: string, at: string, text: string}>
     */
    public static function violations(array $nodes, string $at = '', ?string $parent = null): array
    {
        $found = [];

        foreach (array_values($nodes) as $i => $node) {
            $where = $at === '' ? (string) ($i + 1) : $at.'.'.($i + 1);

            // A comment (a studio comment pinned to a block, a template's Purpose or VERIFY note) is for the author,
            // never the reader: the compiler leaves it out, so it is not an element the email holds.
            if (is_array($node) && PmlParser::isCommentNode($node)) {
                continue;
            }

            if (isset($node['component'])) {
                // A component inside an email must itself be an email component; its expanded nodes are checked
                // where they are expanded. Here it is only named, never assumed safe.
                continue;
            }

            $element = (string) ($node['primitive'] ?? '');

            if (! in_array($element, self::ELEMENTS, true)) {
                $found[] = self::finding('element', $where, ($element === '' ? 'An unnamed element' : "A {$element}").' cannot go in an email (mail clients cannot show it).');

                continue;
            }

            foreach (self::CONTROL as $field) {
                if (array_key_exists($field, $node)) {
                    $found[] = self::finding('logic', $where, "The {$element} uses \"{$field}\": an email cannot run logic. Send different words with different variables instead.");
                }
            }

            foreach ((is_array($node['style'] ?? null) ? $node['style'] : []) as $key => $value) {
                if (! in_array((string) $key, self::STYLE_KEYS, true)) {
                    $found[] = self::finding('style', $where, "The {$element}'s \"".str_replace('_', '-', (string) $key).'" setting does not work in email.');
                } elseif (in_array($value, self::REFUSED_STYLE_VALUES[(string) $key] ?? [], true)) {
                    $found[] = self::finding('style', $where, "The {$element}'s ".str_replace('_', '-', (string) $key).' "'.$value.'" does not show reliably in email.');
                }
            }

            foreach ((is_array($node['props'] ?? null) ? $node['props'] : []) as $prop => $value) {
                if (is_array($value) && (isset($value['$expr']) || isset($value['expr']))) {
                    $found[] = self::finding('logic', $where, "The {$element}'s \"{$prop}\" is an expression: an email takes plain words and {{variables}}.");
                }
            }

            $found = [...$found, ...self::elementRules($element, (array) ($node['props'] ?? []), $where)];

            if (($nesting = self::nesting($parent, $element)) !== null) {
                $found[] = self::finding('nesting', $where, $nesting);
            }

            if (is_array($node['children'] ?? null)) {
                $found = [...$found, ...self::violations($node['children'], $where, $element)];
            }
        }

        return $found;
    }

    /**
     * The `{{variable}}` names the design uses, in order of first use, from every text, prop and link.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string>
     */
    public static function variables(array $nodes): array
    {
        $names = [];
        // A comment's words are the author's notes, never sent, so a {{name}} written in one is not a variable to fill.
        $sent = self::withoutComments($nodes);
        array_walk_recursive($sent, function ($value) use (&$names): void {
            if (is_string($value) && preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $value, $m) > 0) {
                foreach ($m[1] as $name) {
                    $names[strtolower($name)] = true;
                }
            }
        });

        return array_keys($names);
    }

    /**
     * The design without its comment nodes, at any depth: what a reader can receive.
     *
     * @param  array<int|string, mixed>  $nodes
     * @return array<int|string, mixed>
     */
    private static function withoutComments(array $nodes): array
    {
        $list = array_is_list($nodes);
        $kept = [];

        foreach ($nodes as $key => $item) {
            if ($list && is_array($item) && PmlParser::isCommentNode($item)) {
                continue;
            }
            $kept[$key] = is_array($item) ? self::withoutComments($item) : $item;
        }

        return $list ? array_values($kept) : $kept;
    }

    /**
     * Whether the design carries the email's main button (a button bound to `{{action_url}}`), the sender's call to
     * action when it has one.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    public static function hasMainButton(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (($node['primitive'] ?? null) === 'button' && str_contains((string) ($node['props']['href'] ?? ''), '{{action_url}}')) {
                return true;
            }
            if (is_array($node['children'] ?? null) && self::hasMainButton($node['children'])) {
                return true;
            }
        }

        return false;
    }

    /** @return list<array{code: string, at: string, text: string}> */
    private static function elementRules(string $element, array $props, string $where): array
    {
        $found = [];

        if ($element === 'grid' && (int) ($props['columns'] ?? 1) > self::MAX_COLUMNS) {
            $found[] = self::finding('columns', $where, 'An email can show at most '.self::MAX_COLUMNS.' columns side by side.');
        }

        if ($element === 'heading' && (int) ($props['level'] ?? 1) > self::MAX_HEADING_LEVEL) {
            $found[] = self::finding('heading', $where, 'An email uses heading levels 1 to '.self::MAX_HEADING_LEVEL.'.');
        }

        if ($element === 'image') {
            if (! self::reachable((string) ($props['src'] ?? ''))) {
                $found[] = self::finding('image', $where, 'An image in an email needs a full web address (https://...) or a {{variable}}.');
            }
            if (trim((string) ($props['alt'] ?? '')) === '') {
                $found[] = self::finding('image', $where, 'An image in an email needs a description, for people whose mail hides pictures.');
            }
        }

        if ($element === 'button') {
            if (trim((string) ($props['label'] ?? '')) === '') {
                $found[] = self::finding('button', $where, 'A button needs words on it.');
            }
            if (! self::reachable((string) ($props['href'] ?? ''))) {
                $found[] = self::finding('button', $where, 'A button in an email needs a full web address (https://...) or a {{variable}}.');
            }
        }

        if ($element === 'link' && ! self::reachable((string) ($props['href'] ?? ''), allowMail: true)) {
            $found[] = self::finding('link', $where, 'A link in an email needs a full web address, a mailto: or a {{variable}}.');
        }

        return $found;
    }

    /** A full https address, a `{{variable}}` (filled at send time) or, for links, a mailto:/tel:. */
    private static function reachable(string $url, bool $allowMail = false): bool
    {
        $url = trim($url);

        return $url !== '' && (
            preg_match('#^https://#i', $url) === 1
            || preg_match('/^\{\{\s*[a-z0-9_]+\s*\}\}/i', $url) === 1
            || ($allowMail && preg_match('#^(mailto|tel):#i', $url) === 1)
        );
    }

    /** @return array{code: string, at: string, text: string} */
    /**
     * PML's own nesting rules (the element data every editor reads), so an email is always one the visual editor
     * accepts: an element with fixed parents sits in one of them, a container with a fixed list takes only those, and
     * an element that holds words (a paragraph) takes only words and inline marks.
     */
    private static function nesting(?string $parent, string $element): ?string
    {
        $parents = ElementCatalog::parentsOf($element);

        if ($parents !== null && ! in_array($parent, $parents, true)) {
            return "A {$element} can only go inside a ".implode(', ', $parents).'.';
        }

        if ($parent === null) {
            return null;
        }

        $children = ElementCatalog::childrenOf($parent);

        if ($children !== null && ! in_array($element, $children, true)) {
            return "A {$parent} can only hold ".implode(', ', $children).", not a {$element}.";
        }

        if (ElementCatalog::holdsInline($parent) && $element !== 'text' && ! in_array($element, ElementCatalog::inline(), true)) {
            return "A {$parent} holds words, not a {$element}.";
        }

        return null;
    }

    private static function finding(string $code, string $at, string $text): array
    {
        return ['code' => $code, 'at' => $at, 'text' => $text];
    }
}
