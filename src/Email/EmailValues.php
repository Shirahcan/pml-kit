<?php

namespace Shirahcan\Pml\Email;

/**
 * Filling an email's `{{variables}}` (pml-email A3a/B2a). Two kinds of value, two passes:
 *
 *  - An ORDINARY value (a name, a date, words a person typed) is filled into the design BEFORE it is drawn, so the
 *    drawing escapes it like every other word. A client who types `<a href=...>` into a message sees those
 *    characters; nothing is injected into the email.
 *  - A PREPARED-HTML value (`{{body}}`, `{{*_html}}`, `{{*_block}}`, `{{*_line}}`, `{{*_list}}`: the naming rule the
 *    semantic compiler already uses) is markup the product built itself. It is filled AFTER drawing, unescaped, and
 *    only where it stands alone as a block (a lone `text` element), so it never lands inside a paragraph.
 *
 * Filling the design first is also what keeps today's sending behaviour: a detail row whose value came out empty is
 * dropped (no bare "Timezone:"), and a button whose label or address came out empty is left out (the main button when
 * a send has no `action_url`). `missing: strip` (sending) removes a variable with no value; `keep` (a preview) shows it.
 */
final class EmailValues
{
    private const PREPARED = '/^(?:body|[a-zA-Z_][a-zA-Z0-9_]*(?:_html|_block|_line|_list))$/';

    public static function isPrepared(string $name): bool
    {
        return preg_match(self::PREPARED, $name) === 1;
    }

    /** The prepared-HTML variable a string is, alone; null otherwise. */
    public static function preparedPlaceholder(string $text): ?string
    {
        return preg_match('/^\s*\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}\s*$/', $text, $m) === 1 && self::isPrepared($m[1]) ? $m[1] : null;
    }

    /**
     * Fill ordinary values into every word and setting of the design; prepared placeholders stay for fillPrepared().
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, scalar|null>  $values
     * @return list<array<string, mixed>>
     */
    public static function fillTree(array $nodes, array $values, bool $strip): array
    {
        return self::fillChildren($nodes, self::map($values, prepared: false), $strip);
    }

    /**
     * Fill prepared-HTML values into the drawn email, unescaped; with $strip, any variable still unfilled is removed.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function fillPrepared(string $html, array $values, bool $strip): string
    {
        $map = self::map($values, prepared: true);

        $html = (string) preg_replace_callback('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', fn ($m) => $map[$m[0]] ?? ($strip ? '' : $m[0]), $html);

        return str_replace(self::SHIELD, '', $html);
    }

    /**
     * Braces inside an ORDINARY value are shielded until the very end, so words a person typed (`{{x}}`) are shown as
     * typed: never filled by the second pass, never stripped as a missing variable.
     */
    private const SHIELD = "\u{E000}";

    /** @return array<string, string> */
    private static function map(array $values, bool $prepared): array
    {
        $map = [];

        foreach ($values as $name => $value) {
            if ($value !== null && is_scalar($value) && self::isPrepared((string) $name) === $prepared) {
                $map['{{'.$name.'}}'] = $prepared ? (string) $value : str_replace('{{', '{'.self::SHIELD.'{', (string) $value);
            }
        }

        return $map;
    }

    /** @param  array<string, mixed>  $node */
    private static function fillNode(array $node, array $map, bool $strip): ?array
    {
        $primitive = (string) ($node['primitive'] ?? '');

        foreach (['props', 'style'] as $bag) {
            if (is_array($node[$bag] ?? null)) {
                foreach ($node[$bag] as $key => $value) {
                    if (is_string($value)) {
                        $node[$bag][$key] = self::fillString($value, $map, $strip);
                    }
                }
            }
        }

        // An address is checked AFTER filling: a value can be anything, so a button or picture whose address came out
        // empty or unsafe (javascript:, a bare word) is left out, and such a link keeps its words without the link.
        if ($primitive === 'button' && (trim((string) ($node['props']['label'] ?? '')) === '' || ! self::safeAddress((string) ($node['props']['href'] ?? '')))) {
            return null;
        }
        if ($primitive === 'image' && ! self::safeAddress((string) ($node['props']['src'] ?? ''), web: true)) {
            return null;
        }
        if ($primitive === 'link' && ! self::safeAddress((string) ($node['props']['href'] ?? ''))) {
            $node = ['primitive' => 'span', 'children' => $node['children'] ?? []];
        }

        if (is_array($node['children'] ?? null)) {
            $before = $node['children'];
            $node['children'] = self::fillChildren($before, $map, $strip);

            if ($primitive === 'row' && self::emptiedDetailRow($before, $node['children'])) {
                return null;
            }
        }

        return $node;
    }

    /** @return list<array<string, mixed>> */
    private static function fillChildren(array $children, array $map, bool $strip): array
    {
        $out = [];
        foreach ($children as $child) {
            if (is_array($child) && ($filled = self::fillNode($child, $map, $strip)) !== null) {
                $out[] = $filled;
            }
        }

        return $out;
    }

    /** A line of words outside the design (the preheader), filled like the design's words. */
    public static function fillWords(string $text, array $values, bool $strip): string
    {
        return self::fillString($text, self::map($values, prepared: false), $strip);
    }

    /** One pass: a variable becomes its value, a prepared one waits for fillPrepared(), a missing one is stripped or kept. */
    private static function fillString(string $text, array $map, bool $strip): string
    {
        return (string) preg_replace_callback(
            '/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/',
            fn ($m) => $map[$m[0]] ?? (self::isPrepared($m[1]) || ! $strip ? $m[0] : ''),
            $text,
        );
    }

    /** http(s), mailto: or tel: (a picture: http(s) only), or a variable still waiting for a preview's value. */
    private static function safeAddress(string $url, bool $web = false): bool
    {
        $url = trim($url);

        if ($url === '') {
            return false;
        }

        return str_starts_with($url, '{{') || preg_match($web ? '#^https?://#i' : '#^(?:https?://|mailto:|tel:)#i', $url) === 1;
    }

    /** A two-cell detail row whose value cell held a variable and now holds no words. */
    private static function emptiedDetailRow(array $before, array $after): bool
    {
        if (count($before) !== 2 || count($after) !== 2) {
            return false;
        }

        return str_contains(json_encode($before[1]) ?: '', '{{') && trim(self::words($after[1])) === '';
    }

    private static function words(array $node): string
    {
        $text = '';
        array_walk_recursive($node, function ($v, $k) use (&$text): void {
            if (in_array($k, ['value', 'text', 'label', 'title'], true) && is_string($v)) {
                $text .= $v;
            }
        });

        return $text;
    }
}
