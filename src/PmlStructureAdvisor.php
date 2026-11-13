<?php

namespace Shirahcan\Pml;

/**
 * Advisory structure + accessibility findings for a PML page (PML v2 S1-03). They WARN and never
 * block (aid-not-enforce): a page with two h1s still saves and publishes, the author is just told.
 *
 * Runs on any node tree: the SOURCE tree in the Code view (paired with spans by
 * {@see PmlDiagnoseService}) and the COMPILED tree in Check (where components are expanded, so a
 * hero's heading counts). A component it cannot see into is given the benefit of the doubt.
 *
 * Rules: one `main`; one h1; heading levels do not skip (h2 -> h4); a section/article carries a
 * heading; an image says what it shows (alt); a button's label says where it goes.
 */
final class PmlStructureAdvisor
{
    /** Labels that say nothing about the destination when read on their own (WCAG 2.4.4). */
    private const VAGUE_LABELS = ['click here', 'here', 'read more', 'more', 'learn more', 'this', 'link', 'go'];

    /**
     * @param  list<array<string, mixed>>  $tree
     * @return list<array{rule: string, message: string, path: list<int|string>}>
     */
    public function findings(array $tree): array
    {
        $findings = [];
        $state = ['mains' => 0, 'h1s' => 0, 'lastLevel' => null];

        $this->walk($tree, [], $state, $findings);

        return $findings;
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  list<int|string>  $prefix
     * @param  array{mains: int, h1s: int, lastLevel: int|null}  $state
     * @param  list<array{rule: string, message: string, path: list<int|string>}>  $out
     */
    private function walk(array $nodes, array $prefix, array &$state, array &$out): void
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            $path = [...$prefix, $i];
            $primitive = $node['primitive'] ?? null;
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];

            if ($primitive === 'box' && ($props['as'] ?? null) === 'main') {
                $state['mains']++;

                if ($state['mains'] > 1) {
                    $out[] = $this->finding('structure.one_main', 'A page has one main content area. This is the second; make it a section instead.', $path);
                }
            }

            if ($primitive === 'box' && in_array($props['as'] ?? null, ['section', 'article'], true)
                && ! $this->hasHeading($node['children'] ?? [])) {
                $what = $props['as'] === 'article' ? 'article' : 'section';
                $out[] = $this->finding("structure.{$what}_heading", "This {$what} has no heading. Screen readers and search engines use headings to know what each part of the page is about.", $path);
            }

            if ($primitive === 'heading' && is_string($props['text'] ?? null)) {
                $level = (int) ($props['level'] ?? 2);

                if ($level === 1) {
                    $state['h1s']++;

                    if ($state['h1s'] > 1) {
                        $out[] = $this->finding('structure.one_h1', 'A page has one main title (level 1). Make this heading level 2.', $path);
                    }
                }

                if ($state['lastLevel'] !== null && $level > $state['lastLevel'] + 1) {
                    $out[] = $this->finding('structure.heading_order', "This heading jumps from level {$state['lastLevel']} to level {$level}. Use level ".($state['lastLevel'] + 1).' so the outline reads in order.', $path);
                }

                $state['lastLevel'] = $level;
            }

            if ($primitive === 'image' && ! $this->hasText($props['alt'] ?? null) && ! $this->isBound($props['alt'] ?? null)) {
                $out[] = $this->finding('accessibility.image_alt', 'Describe this image (its alt text) so people using a screen reader, and search engines, know what it shows.', $path);
            }

            if ($primitive === 'button' && is_string($props['label'] ?? null)
                && in_array(strtolower(trim($props['label'])), self::VAGUE_LABELS, true)) {
                $out[] = $this->finding('accessibility.link_text', "\"{$props['label']}\" does not say where it goes. Name the destination, like \"See the document checklist\".", $path);
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $this->walk($node['children'], [...$path, 'children'], $state, $out);
            }
        }
    }

    /** A heading anywhere inside (or a titled list, or a component that may carry one). */
    private function hasHeading(mixed $children): bool
    {
        if (! is_array($children)) {
            return false;
        }

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (isset($child['component'])
                || ($child['primitive'] ?? null) === 'heading'
                || (($child['primitive'] ?? null) === 'list' && $this->hasText($child['props']['heading'] ?? null))
                || $this->hasHeading($child['children'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    private function hasText(mixed $value): bool
    {
        return is_string($value) && trim($value) !== '';
    }

    private function isBound(mixed $value): bool
    {
        return PrimitiveSchema::isExpression($value);
    }

    /**
     * @param  list<int|string>  $path
     * @return array{rule: string, message: string, path: list<int|string>}
     */
    private function finding(string $rule, string $message, array $path): array
    {
        return ['rule' => $rule, 'message' => $message, 'path' => $path];
    }
}
