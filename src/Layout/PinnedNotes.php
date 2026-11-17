<?php

namespace Shirahcan\Pml\Layout;

use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlUids;

/**
 * A template's notes as studio comments PINNED to the element each is about (owner 2026-11-17: "transform them into PML
 * rendered comments (comment element) that are attached to the relevant elements").
 *
 * A layout's skeleton writes its guidance as plain notes (`{comment: "Purpose: ..."}`) right before the part's element,
 * and a prepare adds "VERIFY: ..." notes beside them. Plain notes have no element, so a designer can only draw them as
 * lines between parts. Pinned, each is the SAME first-class comment node with a `note` (D1: `<comment for=uid>`), drawn
 * as a pin on its element and never on a page. The note is pinned to the next element after it in the same list; a
 * note with no element after it stays plain. A note already pinned is left exactly as it is, so running this twice
 * changes nothing (the backfill relies on that).
 *
 * Purpose notes read as the template's (blue); a "VERIFY" note is a check a person must settle (yellow), and settling it
 * is resolving or deleting that one comment (see {@see LayoutChecks}).
 */
final class PinnedNotes
{
    public const TEMPLATE_AUTHOR = 'Template';

    public const CHECK_AUTHOR = 'Porter';

    public const TEMPLATE_COLOR = 'blue';

    public const CHECK_COLOR = 'yellow';

    public const PLACEMENT = 'top-right';

    /**
     * Every plain note pinned to the element after it; elements get their permanent id first ({@see PmlUids::ensure}),
     * since a pin needs one.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function pin(array $nodes): array
    {
        return self::walk(PmlUids::ensure(array_values($nodes)));
    }

    /** True when the design still holds a plain note an element follows (what the backfill looks for). */
    public static function hasLoose(array $nodes): bool
    {
        $list = array_values($nodes);

        foreach ($list as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            if (PmlParser::isCommentNode($node)) {
                if (! isset($node['note']) && self::nextElement($list, $i) !== null) {
                    return true;
                }

                continue;
            }
            if (is_array($node['children'] ?? null) && self::hasLoose($node['children'])) {
                return true;
            }
        }

        return false;
    }

    /** A note a person must settle: it starts with "VERIFY" (any case). */
    public static function isCheck(string $text): bool
    {
        return stripos(ltrim($text), 'VERIFY') === 0;
    }

    /** True when a comment node is settled: pinned and marked resolved. */
    public static function isResolved(array $node): bool
    {
        return is_array($node['note'] ?? null) && ($node['note']['resolved'] ?? false) === true;
    }

    /**
     * @param  list<mixed>  $list
     * @return list<mixed>
     */
    private static function walk(array $list): array
    {
        $list = array_values($list);

        foreach ($list as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            if (PmlParser::isCommentNode($node)) {
                $next = isset($node['note']) || ! is_string($node['comment']) ? null : self::nextElement($list, $i);
                $for = is_array($next) && is_string($next['uid'] ?? null) ? $next['uid'] : null;

                if ($for !== null) {
                    $check = self::isCheck($node['comment']);
                    $list[$i]['note'] = [
                        'for' => $for,
                        'placement' => self::PLACEMENT,
                        'color' => $check ? self::CHECK_COLOR : self::TEMPLATE_COLOR,
                        'author' => $check ? self::CHECK_AUTHOR : self::TEMPLATE_AUTHOR,
                    ];
                }

                continue;
            }

            if (is_array($node['children'] ?? null)) {
                $list[$i]['children'] = self::walk($node['children']);
            }
        }

        return $list;
    }

    /**
     * The first element after position $i in the same list (notes between are skipped), or null.
     *
     * @param  list<mixed>  $list
     * @return array<string, mixed>|null
     */
    private static function nextElement(array $list, int $i): ?array
    {
        for ($j = $i + 1; $j < count($list); $j++) {
            if (is_array($list[$j]) && ! PmlParser::isCommentNode($list[$j])) {
                return $list[$j];
            }
        }

        return null;
    }
}
