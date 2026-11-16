<?php

namespace Shirahcan\Pml\Email;

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
     * @return list<array{code: string, at: string, text: string}>
     */
    public static function violations(array $nodes, string $at = ''): array
    {
        $found = [];

        foreach (array_values($nodes) as $i => $node) {
            $where = $at === '' ? (string) ($i + 1) : $at.'.'.($i + 1);

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

            if (is_array($node['children'] ?? null)) {
                $found = [...$found, ...self::violations($node['children'], $where)];
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
        array_walk_recursive($nodes, function ($value) use (&$names): void {
            if (is_string($value) && preg_match_all('/\{\{\s*([a-z0-9_]+)\s*\}\}/i', $value, $m) > 0) {
                foreach ($m[1] as $name) {
                    $names[strtolower($name)] = true;
                }
            }
        });

        return array_keys($names);
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
    private static function finding(string $code, string $at, string $text): array
    {
        return ['code' => $code, 'at' => $at, 'text' => $text];
    }
}
