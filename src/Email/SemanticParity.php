<?php

namespace Shirahcan\Pml\Email;

/**
 * Proves a converted email lost nothing (pml-email B2a): the original semantic body and its PML design carry the same
 * words in the same order, the same variables and the same link addresses. Formatting is not compared (a heading's
 * badge becoming plain words is a listed note, not a loss). A body with any difference keeps its old version.
 */
final class SemanticParity
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string> the differences; empty when nothing was lost
     */
    public static function differences(string $semantic, array $nodes): array
    {
        [$wordsBefore, $varsBefore, $linksBefore] = self::fromSemantic($semantic);
        [$wordsAfter, $varsAfter, $linksAfter] = self::fromDesign($nodes);
        $found = [];

        if ($wordsBefore !== $wordsAfter) {
            $at = strspn($wordsBefore ^ $wordsAfter, "\0");
            $found[] = 'The words differ from "'.mb_substr(substr($wordsBefore, $at), 0, 40).'" (now "'.mb_substr(substr($wordsAfter, $at), 0, 40).'").';
        }
        if (($missing = array_diff($varsBefore, $varsAfter)) !== []) {
            $found[] = 'Variables lost: '.implode(', ', $missing).'.';
        }
        if (($added = array_diff($varsAfter, $varsBefore)) !== []) {
            $found[] = 'Variables added: '.implode(', ', $added).'.';
        }
        if ($linksBefore !== $linksAfter) {
            $found[] = 'Links differ: '.implode(' ', array_diff($linksBefore, $linksAfter) ?: array_diff($linksAfter, $linksBefore) ?: ['(order or count)']).'.';
        }

        return $found;
    }

    /** @return array{0: string, 1: list<string>, 2: list<string>} */
    private static function fromSemantic(string $html): array
    {
        $links = [];
        preg_match_all('/<a\s[^>]*href="([^"]*)"/i', $html, $m);
        foreach ($m[1] as $href) {
            $links[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $vars = [];
        $words = '';

        foreach (SemanticTokens::split($html) as $piece) {
            if ($piece['kind'] === 'html') {
                $words .= self::text($piece['html']);

                continue;
            }

            match (true) {
                $piece['name'] === 'action_button' => array_push($vars, 'action_label', 'action_url'),
                in_array($piece['name'], ['button', 'code'], true) => (function () use ($piece, &$words, &$links): void {
                    [$label, $value] = SemanticTokens::pair($piece['body']);
                    $words .= $label.($piece['name'] === 'code' ? $value : '');
                    if ($piece['name'] === 'button' && [$label, $value] !== ['{{action_label}}', '{{action_url}}']) {
                        $links[] = $value;
                    }
                })(),
                str_starts_with($piece['name'], 'alert_') => $words .= self::text($piece['body']),
                default => null,
            };

            $vars = [...$vars, ...self::variables($piece['body'])];
        }

        $vars = [...$vars, ...self::variables($html)];

        return [self::squash($words), self::unique($vars), self::sorted($links)];
    }

    /** @return array{0: string, 1: list<string>, 2: list<string>} */
    private static function fromDesign(array $nodes): array
    {
        $words = '';
        $vars = [];
        $links = [];

        $walk = function (array $nodes) use (&$walk, &$words, &$vars, &$links): void {
            foreach ($nodes as $node) {
                $props = (array) ($node['props'] ?? []);
                $primitive = (string) ($node['primitive'] ?? '');

                foreach (['title', 'text', 'value', 'label'] as $key) {
                    if (is_string($props[$key] ?? null) && ! ($primitive === 'button' && $key === 'label' && $props[$key] === '{{action_label}}')) {
                        $words .= $props[$key];
                    }
                }
                foreach ($props as $value) {
                    if (is_string($value)) {
                        $vars = [...$vars, ...self::variables($value)];
                    }
                }
                if ($primitive === 'link' || ($primitive === 'button' && [$props['label'] ?? '', $props['href'] ?? ''] !== ['{{action_label}}', '{{action_url}}'])) {
                    $links[] = (string) ($props['href'] ?? '');
                }

                $walk((array) ($node['children'] ?? []));
            }
        };
        $walk($nodes);

        return [self::squash($words), self::unique($vars), self::sorted($links)];
    }

    /** A fragment's words: tags removed, entities decoded, inline badges as their words. */
    private static function text(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return (string) preg_replace('/\{\{badge_[a-z]+:([^{}]*)\}\}/', '$1', $text);
    }

    /** Words compared without spacing or variables (variables are compared on their own). */
    private static function squash(string $words): string
    {
        $words = (string) preg_replace('/\{\{[a-zA-Z_][a-zA-Z0-9_]*\}\}/', '', $words);

        return (string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $words);
    }

    /** @return list<string> */
    private static function variables(string $text): array
    {
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $text, $m);

        return array_values(array_diff($m[1], ['action_button', 'divider']));
    }

    /** @return list<string> */
    private static function unique(array $names): array
    {
        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    /** @return list<string> */
    private static function sorted(array $links): array
    {
        sort($links);

        return $links;
    }
}
