<?php

namespace Shirahcan\Pml;

/**
 * Walking a design's element tree by PATH (`[2, 0]`: the third element's first child), shared by every product and every
 * Build (0.32.0). Before, the browser had pml-ui's `updateNodeAtPath`, `EmailBuild` had private copies, and Portify had
 * none, so a worker that saves an aimed result (Portify server-side landing L4) had nothing to call.
 *
 * Elements are arrays with an optional `children` list; comment nodes (`<!-- -->`) are ordinary entries here, so a path
 * counts them as the editor does.
 */
final class PmlTree
{
    /**
     * The element at a path, or null when the path leads nowhere.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<int>  $path
     * @return array<string, mixed>|null
     */
    public static function nodeAt(array $nodes, array $path): ?array
    {
        if ($path === []) {
            return null;
        }

        $node = null;
        $list = $nodes;
        foreach ($path as $i) {
            $node = $list[$i] ?? null;
            if (! is_array($node)) {
                return null;
            }
            $list = is_array($node['children'] ?? null) ? $node['children'] : [];
        }

        return $node;
    }

    /**
     * The same tree with the element at `$path` replaced; everything else is exactly as it was.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<int>  $path
     * @param  array<string, mixed>  $replacement
     * @return list<array<string, mixed>>
     */
    public static function replaceAt(array $nodes, array $path, array $replacement): array
    {
        if ($path === []) {
            return array_values($nodes);
        }

        $i = $path[0];
        if (count($path) === 1) {
            $nodes[$i] = $replacement;

            return array_values($nodes);
        }
        $nodes[$i]['children'] = self::replaceAt(array_values((array) ($nodes[$i]['children'] ?? [])), array_slice($path, 1), $replacement);

        return array_values($nodes);
    }

    /**
     * A revised element keeps the template's PART MARKS (0.31.1 in EmailBuild, shared here since 0.32.0): a rewrite in a
     * vocabulary without `part` can come back without `part="section"` on the block or its short answer, and the design
     * then fails its layout check for a missing part. Every element keeps its id, so each mark is put back by uid, and the
     * block's own mark on the block whatever its uid.
     *
     * @param  array<string, mixed>  $original  the element as it was
     * @param  array<string, mixed>  $revised
     * @return array<string, mixed>
     */
    public static function keepParts(array $original, array $revised): array
    {
        $marks = [];
        $collect = function (array $node) use (&$collect, &$marks): void {
            if (is_string($node['uid'] ?? null) && is_string($node['part'] ?? null) && $node['part'] !== '') {
                $marks[$node['uid']] = $node['part'];
            }
            foreach ((array) ($node['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
        };
        $collect($original);

        $restore = function (array $node) use (&$restore, $marks): array {
            $part = is_string($node['part'] ?? null) && $node['part'] !== '' ? $node['part'] : (is_string($node['uid'] ?? null) ? ($marks[$node['uid']] ?? null) : null);
            if ($part !== null) {
                $node['part'] = $part;
            }
            if (is_array($node['children'] ?? null)) {
                $node['children'] = array_map(fn ($child) => is_array($child) ? $restore($child) : $child, $node['children']);
            }

            return $node;
        };
        $kept = $restore($revised);

        if ((! is_string($kept['part'] ?? null) || $kept['part'] === '') && is_string($original['part'] ?? null) && $original['part'] !== '') {
            $kept['part'] = $original['part'];
        }

        return $kept;
    }
}
