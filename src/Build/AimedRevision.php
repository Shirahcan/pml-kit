<?php

namespace Shirahcan\Pml\Build;

use Shirahcan\Pml\PmlTree;

/**
 * An aimed ask ("change only this part") landing in its design (P5-1c 2b, 2026-11-25): the ONE place the rules live,
 * for every product that lets a person aim the assistant at one element.
 *
 *   - the revised block keeps the element's id, whatever the model wrote;
 *   - it keeps the template's part marks ({@see PmlTree::keepParts()});
 *   - it keeps every note it held. A post template pins notes INSIDE its parts (each question section's short answer,
 *     each FAQ item: 10 of a fresh post's 27), the model reads the block with its notes and writes it back, so a note
 *     could come back reworded or not at all. The whole-page Build has restored them since 0.40.1; an aimed one did
 *     not. A note whose element is gone goes at the end of the block, to be re-pinned, never outside it.
 *
 * Nothing else changes: the design outside the block is the design as it was.
 */
final class AimedRevision
{
    /**
     * @param  list<array<string, mixed>>  $nodes  the whole design
     * @param  list<int>  $path  where the aimed block is
     * @param  array<string, mixed>  $revised  the block as the model wrote it
     * @return list<array<string, mixed>>  the design with the block landed; unchanged when the path finds nothing
     */
    public static function land(array $nodes, array $path, array $revised): array
    {
        $original = PmlTree::nodeAt($nodes, $path);

        if ($original === null) {
            return $nodes;
        }

        return PmlTree::replaceAt($nodes, $path, self::block($original, $revised));
    }

    /**
     * The revised block, kept to the original's id, part marks and notes.
     *
     * @param  array<string, mixed>  $original
     * @param  array<string, mixed>  $revised
     * @return array<string, mixed>
     */
    public static function block(array $original, array $revised): array
    {
        $kept = PmlTree::keepParts($original, $revised);

        if (is_string($original['uid'] ?? null) && $original['uid'] !== '') {
            $kept['uid'] = $original['uid'];
        }

        $restored = DesignNotes::restoreMissing([$original], [$kept]);

        // restoreMissing places an orphaned note at the end of the list it was given, which here is BESIDE the block;
        // it belongs at the end of the block, the only place this ask may touch.
        $block = null;
        $orphans = [];
        foreach ($restored as $node) {
            if ($block === null && ! DesignNotes::isNote($node)) {
                $block = $node;
            } else {
                $orphans[] = $node;
            }
        }

        if ($block === null) {
            return $kept;
        }

        if ($orphans !== []) {
            $block['children'] = [...array_values((array) ($block['children'] ?? [])), ...$orphans];
        }

        return $block;
    }
}
