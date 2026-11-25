<?php

namespace Shirahcan\Pml\Build;

use Shirahcan\Pml\PmlParser;

/**
 * The team's notes in a design (studio comments, a template's Purpose / VERIFY notes, Porter's own pinned checks): one
 * home for every Build (shared PML Studio P5-1c). A note belongs to an element: its pin (`note.for`, the element's uid),
 * or else the element that followed it in its list.
 *
 * ⚠ A BUILD NEVER LOSES A NOTE. Measured 2026-11-25: one page Build on a post's opening dropped the template's note for
 * the opening (27 notes before, 26 after), the very note that tells the assistant to keep the title and author in the
 * post's fields. The model never decides which notes survive: an email's are taken out before the ask and put back
 * after; a page's go to the model as context (a template's Purpose lines guide it) and any it drops are put back.
 */
final class DesignNotes
{
    public static function isNote(mixed $node): bool
    {
        return is_array($node) && PmlParser::isCommentNode($node);
    }

    /**
     * The design without its notes, at any depth.
     *
     * @param  list<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function without(array $nodes): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || self::isNote($node)) {
                continue;
            }
            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::without($node['children']);
            }
            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * Every note in the design with the uid of the element it belongs to, or null when neither is known.
     *
     * @param  list<mixed>  $nodes
     * @return list<array{note: array<string, mixed>, before: ?string}>
     */
    public static function in(array $nodes): array
    {
        $found = [];
        $list = array_values($nodes);
        foreach ($list as $i => $node) {
            if (self::isNote($node)) {
                $for = is_array($node['note'] ?? null) && is_string($node['note']['for'] ?? null) ? $node['note']['for'] : null;
                $found[] = ['note' => $node, 'before' => $for ?? self::nextUid($list, $i)];

                continue;
            }
            if (is_array($node) && is_array($node['children'] ?? null)) {
                $found = [...$found, ...self::in($node['children'])];
            }
        }

        return $found;
    }

    /**
     * The notes put back: each before the element it belongs to, wherever that element now is; one whose element is
     * gone at the end of the top level, where the designer shows it as a note to re-pin.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{note: array<string, mixed>, before: ?string}>  $notes
     * @return list<array<string, mixed>>
     */
    public static function reattach(array $nodes, array $notes): array
    {
        $orphans = [];
        foreach ($notes as $entry) {
            $placed = false;
            $nodes = $entry['before'] === null ? $nodes : self::insertBefore($nodes, $entry['before'], $entry['note'], $placed);
            if (! $placed) {
                $orphans[] = $entry['note'];
            }
        }

        return [...$nodes, ...$orphans];
    }

    /**
     * The notes of `$before` that `$after` no longer holds (same words, same pin), put back where they belong. A note
     * the answer kept, or added (the assistant's own pinned check), is left as it is.
     *
     * @param  list<mixed>  $before
     * @param  list<array<string, mixed>>  $after
     * @return list<array<string, mixed>>
     */
    public static function restoreMissing(array $before, array $after): array
    {
        $kept = [];
        foreach (self::in($after) as $entry) {
            $kept[self::identity($entry['note'])] = ($kept[self::identity($entry['note'])] ?? 0) + 1;
        }

        $missing = [];
        foreach (self::in($before) as $entry) {
            $id = self::identity($entry['note']);
            if (($kept[$id] ?? 0) > 0) {
                $kept[$id]--;

                continue;
            }
            $missing[] = $entry;
        }

        return $missing === [] ? $after : self::reattach($after, $missing);
    }

    /** A note's words and pin: what makes two notes the same note. */
    private static function identity(array $note): string
    {
        $for = is_array($note['note'] ?? null) ? (string) ($note['note']['for'] ?? '') : '';

        return trim((string) ($note['comment'] ?? ''))."\0".$for;
    }

    /** The uid of the first element after position $i in a list, skipping notes. */
    private static function nextUid(array $list, int $i): ?string
    {
        for ($j = $i + 1; $j < count($list); $j++) {
            if (is_array($list[$j]) && ! self::isNote($list[$j])) {
                return is_string($list[$j]['uid'] ?? null) ? $list[$j]['uid'] : null;
            }
        }

        return null;
    }

    /** @param list<array<string, mixed>> $nodes */
    private static function insertBefore(array $nodes, string $uid, array $note, bool &$placed): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (! $placed && ($node['uid'] ?? null) === $uid) {
                $out[] = $note;
                $placed = true;
            }
            if (! $placed && is_array($node['children'] ?? null)) {
                $node['children'] = self::insertBefore($node['children'], $uid, $note, $placed);
            }
            $out[] = $node;
        }

        return $out;
    }
}
