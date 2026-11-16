<?php

namespace Shirahcan\Pml\Email;

/**
 * Splits a semantic paragraph's HTML into its words and its BLOCK tokens (pml-email B2a). Real bodies put several block
 * tokens in one paragraph (`{{alert_info:{{next_steps}}}}{{action_button}}{{divider}}`), wrap inline markup inside an
 * alert (`{{alert_info:Expires on <strong>{{expires_at}}</strong>.}}`) and nest variables in a token's value
 * (`{{code:Your code = {{code}}}}`), so the split counts braces rather than trusting a regex.
 *
 * Inline tokens (`{{badge_*:}}`) and plain variables stay in the words.
 */
final class SemanticTokens
{
    /**
     * @return list<array{kind: 'html', html: string}|array{kind: 'token', name: string, body: string}>
     */
    public static function split(string $html): array
    {
        $pieces = [];
        $buffer = '';
        $i = 0;
        $length = strlen($html);

        while ($i < $length) {
            if (substr($html, $i, 2) === '{{' && ($end = self::closing($html, $i)) !== null) {
                $inner = substr($html, $i + 2, $end - $i - 2);
                $name = self::blockName($inner);

                if ($name !== null) {
                    if (trim($buffer) !== '') {
                        $pieces[] = ['kind' => 'html', 'html' => $buffer];
                    }
                    $buffer = '';
                    $pieces[] = ['kind' => 'token', 'name' => $name, 'body' => self::body($inner)];
                    $i = $end + 2;

                    continue;
                }

                $buffer .= substr($html, $i, $end + 2 - $i);
                $i = $end + 2;

                continue;
            }

            $buffer .= $html[$i];
            $i++;
        }

        if (trim($buffer) !== '') {
            $pieces[] = ['kind' => 'html', 'html' => $buffer];
        }

        return $pieces;
    }

    /** The token's own name (`action_button`, `divider`, `alert_warning`, `button`, `code`, `rows`) when it is a block. */
    public static function blockName(string $inner): ?string
    {
        if (in_array($inner, ['action_button', 'divider'], true)) {
            return $inner;
        }

        return preg_match('/^(alert_(?:info|success|warning|error)|button|code|rows):/', $inner, $m) === 1 ? $m[1] : null;
    }

    /** `LABEL = VALUE` of a button or code token, as words (markup removed); the value keeps any `{{variable}}`. */
    public static function pair(string $body): array
    {
        $words = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $at = self::topLevelEquals($words);

        return $at === null ? [$words, ''] : [trim(substr($words, 0, $at)), trim(substr($words, $at + 1))];
    }

    private static function body(string $inner): string
    {
        $colon = strpos($inner, ':');

        return $colon === false ? '' : substr($inner, $colon + 1);
    }

    /** The offset of the `}}` that closes the `{{` at $start, counting nested pairs; null when it never closes. */
    private static function closing(string $html, int $start): ?int
    {
        $depth = 0;
        $length = strlen($html);

        for ($i = $start; $i < $length - 1; $i++) {
            if ($html[$i] === '{' && $html[$i + 1] === '{') {
                $depth++;
                $i++;
            } elseif ($html[$i] === '}' && $html[$i + 1] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
                $i++;
            }
        }

        return null;
    }

    /** The first `=` outside any nested `{{ }}` (a value may itself be `{{variable}}`). */
    private static function topLevelEquals(string $text): ?int
    {
        $depth = 0;

        for ($i = 0, $n = strlen($text); $i < $n; $i++) {
            if (substr($text, $i, 2) === '{{') {
                $depth++;
                $i++;
            } elseif (substr($text, $i, 2) === '}}') {
                $depth = max(0, $depth - 1);
                $i++;
            } elseif ($text[$i] === '=' && $depth === 0) {
                return $i;
            }
        }

        return null;
    }
}
