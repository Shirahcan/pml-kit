<?php

namespace Shirahcan\Pml\Html;

/**
 * Proves a converted page lost nothing (pml-kit 0.24.0): the original HTML and its PML elements carry the same words
 * in the same order and the same link addresses. Formatting is not compared (bold becoming plain words is a listed
 * note, not a loss). A caller converts a body only when this and {@see HtmlToPml}'s `unconverted` are both empty.
 * The email twin, which also compares variables and tokens, is {@see \Shirahcan\Pml\Email\SemanticParity}.
 */
final class HtmlParity
{
    /** The props that hold words a reader sees. */
    private const WORD_PROPS = ['title', 'text', 'value', 'label', 'caption'];

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<string> the differences; empty when nothing was lost
     */
    public static function differences(string $html, array $nodes): array
    {
        $wordsBefore = self::squash(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $linksBefore = self::linksIn($html);

        $words = '';
        $links = [];
        $walk = function (array $nodes) use (&$walk, &$words, &$links): void {
            foreach ($nodes as $node) {
                $props = (array) ($node['props'] ?? []);
                foreach (self::WORD_PROPS as $key) {
                    if (is_string($props[$key] ?? null)) {
                        $words .= $props[$key];
                    }
                }
                if (($node['primitive'] ?? null) === 'link') {
                    $links[] = (string) ($props['href'] ?? '');
                }
                $walk((array) ($node['children'] ?? []));
            }
        };
        $walk($nodes);
        $wordsAfter = self::squash($words);
        sort($links);

        $found = [];
        if ($wordsBefore !== $wordsAfter) {
            $at = strspn($wordsBefore ^ $wordsAfter, "\0");
            $found[] = 'The words differ from "'.mb_substr(substr($wordsBefore, $at), 0, 40).'" (now "'.mb_substr(substr($wordsAfter, $at), 0, 40).'").';
        }
        if ($linksBefore !== $links) {
            $found[] = 'Links differ: '.implode(' ', array_diff($linksBefore, $links) ?: array_diff($links, $linksBefore) ?: ['(order or count)']).'.';
        }

        return $found;
    }

    /** @return list<string> */
    private static function linksIn(string $html): array
    {
        preg_match_all('/<a\s[^>]*href="([^"]*)"/i', $html, $m);
        $links = array_map(fn (string $href) => html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $m[1]);
        sort($links);

        return $links;
    }

    /** Words compared without spacing. */
    private static function squash(string $words): string
    {
        return (string) preg_replace('/[\s\x{00A0}\x{200B}]+/u', '', $words);
    }
}
