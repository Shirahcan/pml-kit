<?php

namespace Shirahcan\Pml\Email;

use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Throwable;

/**
 * One console Build on an email design (blog on growth-service N1h-4), the same for every product: a newsletter on
 * growth-service and an email template in Portify's PML designer. The email goes to the model as PML (the whole email,
 * or one aimed block with the whole email as context), the answer is read back, held to the email profile, given ONE
 * repair when the profile refuses it, and handed back. The product supplies only the model call (ai-service's
 * `shared.email.design` with its own key) and lands the result; NOTHING is stored here.
 *
 * ⚠ NOTES NEVER GO TO THE MODEL. A pinned studio comment or a template's Purpose / VERIFY note is the team's, never the
 * reader's: it is taken out before the ask and put back after it, before the element it is pinned to (`note.for`, the
 * element's uid; a plain note goes back before the element that followed it). One whose element is gone goes back at
 * the end, where the designer shows it as a note to re-pin. The model is taught to keep every uid and part mark
 * ({@see EmailSpec}), so the template's parts and the pins survive.
 *
 * ⚠ THE MODEL NEVER DECIDES WHAT IS KEPT. An answer the profile still refuses after the repair changes nothing and says
 * why, in the profile's own sentence.
 */
final class EmailBuild
{
    /** The line the prompt puts between the email and the reply (ai-service `shared.email.design`). */
    public const REPLY_MARK = '=== PORTER REPLY ===';

    /** The finding lines the prompt allows after the reply, in the console only. */
    public const NOTE_PREFIXES = ['Issue:', 'Gap:', 'Suggestion:'];

    public const UNREADABLE = 'The email that came back could not be read, so nothing changed.';

    public const NOT_ONE_BLOCK = 'Return exactly one block: the one that was aimed at, rewritten.';

    private readonly PmlParser $parser;

    private readonly PmlSerializer $serializer;

    public function __construct(?PmlParser $parser = null, ?PmlSerializer $serializer = null)
    {
        $this->parser = $parser ?? new PmlParser(new PmlScanner, new MarkdownToDoc);
        $this->serializer = $serializer ?? new PmlSerializer(new DocToMarkdown);
    }

    /** The email (or a block) as the model reads it: PML, without its notes. */
    public function source(array $nodes): string
    {
        return $this->serializer->serialize(['nodes' => self::withoutNotes(array_values($nodes))]);
    }

    /**
     * @param  list<array<string, mixed>>  $current  the email's design as it is now, notes included
     * @param  list<int>|null  $path  the block an ask is aimed at, or null for the whole email
     * @param  callable(string, ?string, list<string>): string  $ask  the model call: (the email's source, the aimed
     *                                                                block's source or null, problems to fix) => answer
     * @return array{changed: bool, refused: bool, reason: ?string, mode: string, nodes: list<array<string, mixed>>, node: ?array<string, mixed>, reply: string, notes: list<string>, repaired: bool}
     */
    public function run(array $current, ?array $path, callable $ask): array
    {
        $current = array_values($current);
        $aimed = $path === null ? null : self::nodeAt($current, $path);
        $mode = $aimed === null ? 'page' : 'element';
        $email = $this->source($current);
        $block = $aimed === null ? null : $this->source([$aimed]);

        $attempt = $this->attempt((string) $ask($email, $block, []), $current, $path, $aimed);
        $repaired = false;

        if ($attempt['problems'] !== []) {
            // ONE repair: the problems go back with the attempt (or the email as it was, when the attempt was unreadable).
            $repaired = true;
            $retry = $aimed === null
                ? $ask($attempt['nodes'] !== null ? $this->source($attempt['nodes']) : $email, null, $attempt['problems'])
                : $ask($email, $attempt['node'] !== null ? $this->source([$attempt['node']]) : $block, $attempt['problems']);
            $attempt = $this->attempt((string) $retry, $current, $path, $aimed);
        }

        if ($attempt['problems'] !== []) {
            return ['changed' => false, 'refused' => true, 'reason' => $attempt['problems'][0], 'mode' => $mode, 'nodes' => $current,
                'node' => $aimed, 'reply' => $attempt['reply'], 'notes' => $attempt['notes'], 'repaired' => $repaired];
        }

        $nodes = (array) $attempt['nodes'];
        $changed = self::canonical($nodes) !== self::canonical($current);

        return ['changed' => $changed, 'refused' => false, 'reason' => null, 'mode' => $mode, 'nodes' => $nodes,
            'node' => $attempt['node'], 'reply' => $attempt['reply'], 'notes' => $attempt['notes'], 'repaired' => $repaired];
    }

    /**
     * The model's answer, read: the email (with its notes put back), the reply sentences, the finding lines, and what
     * the email profile still refuses.
     *
     * @param  list<int>|null  $path
     * @return array{nodes: ?list<array<string, mixed>>, node: ?array<string, mixed>, problems: list<string>, reply: string, notes: list<string>}
     */
    private function attempt(string $answer, array $current, ?array $path, ?array $aimed): array
    {
        [$body, $after] = array_pad(explode(self::REPLY_MARK, str_replace("\r\n", "\n", $answer), 2), 2, '');
        $body = trim((string) preg_replace('/\A\s*```[a-z]*\s*|\s*```\s*\z/i', '', $body));
        [$reply, $notes] = self::replyOf($after);

        try {
            $parsed = $body === '' ? [] : array_values($this->parser->parse($body)['nodes'] ?? []);
        } catch (Throwable) {
            return ['nodes' => null, 'node' => null, 'problems' => [self::UNREADABLE], 'reply' => $reply, 'notes' => $notes];
        }

        // An element the answer did not really change keeps its original form, so only real edits read as changes.
        $parsed = self::keepUnchanged(self::withoutNotes($parsed), $aimed !== null ? [$aimed] : $current);
        if ($parsed === []) {
            return ['nodes' => null, 'node' => null, 'problems' => [self::UNREADABLE], 'reply' => $reply, 'notes' => $notes];
        }

        if ($aimed !== null && $path !== null) {
            if (count($parsed) !== 1) {
                return ['nodes' => null, 'node' => null, 'problems' => [self::NOT_ONE_BLOCK], 'reply' => $reply, 'notes' => $notes];
            }
            // The aimed block keeps its own notes and its template part marks; everything outside it is as it was.
            $node = self::keepParts($aimed, self::reattach([$parsed[0]], self::notesIn([$aimed]))[0] ?? $parsed[0]);
            $nodes = self::replaceAt($current, $path, $node);
        } else {
            $nodes = self::reattach($parsed, self::notesIn($current));
            $node = null;
        }

        $problems = array_values(array_unique(array_column(EmailProfile::violations($nodes), 'text')));

        return ['nodes' => $nodes, 'node' => $node, 'problems' => $problems, 'reply' => $reply, 'notes' => $notes];
    }

    /** @return array{0: string, 1: list<string>} the reply sentences and the finding lines */
    private static function replyOf(string $after): array
    {
        $reply = [];
        $notes = [];
        foreach (preg_split('/\n+/', trim($after)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (array_filter(self::NOTE_PREFIXES, fn (string $p) => str_starts_with($line, $p)) !== []) {
                $notes[] = $line;
            } else {
                $reply[] = $line;
            }
        }

        return [implode(' ', $reply), $notes];
    }

    /**
     * A revised block keeps the template's PART MARKS (0.31.1, Portify server-side landing L3): the rewrite runs in a
     * vocabulary with no `part`, so an aimed answer can come back without `part="section"` on the block or its short
     * answer, and the email then fails its layout check for a missing part (Portify Loop 2 on TEST, 2026-11-17). Every
     * element keeps its id, so each mark is put back by uid, and the block's own mark on the block whatever its uid.
     * This used to be the designer's browser-side `keepParts`; here, a worker that saves the result needs no browser.
     *
     * @param  array<string, mixed>  $original  the aimed block as it was
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

    private static function isNote(mixed $node): bool
    {
        return is_array($node) && PmlParser::isCommentNode($node);
    }

    /**
     * The design without its notes, at any depth: what the model may read and rewrite.
     *
     * @param  list<mixed>  $nodes
     * @return list<array<string, mixed>>
     */
    public static function withoutNotes(array $nodes): array
    {
        $kept = [];
        foreach ($nodes as $node) {
            if (! is_array($node) || self::isNote($node)) {
                continue;
            }
            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::withoutNotes($node['children']);
            }
            $kept[] = $node;
        }

        return $kept;
    }

    /**
     * Every note in the design with the uid of the element it belongs to: its pin (`note.for`), or else the element that
     * followed it in its list; null when neither is known.
     *
     * @param  list<mixed>  $nodes
     * @return list<array{note: array<string, mixed>, before: ?string}>
     */
    private static function notesIn(array $nodes): array
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
                $found = [...$found, ...self::notesIn($node['children'])];
            }
        }

        return $found;
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

    /**
     * The notes put back: each before the element it belongs to, wherever that element now is; one whose element is
     * gone at the end of the top level.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array{note: array<string, mixed>, before: ?string}>  $notes
     * @return list<array<string, mixed>>
     */
    private static function reattach(array $nodes, array $notes): array
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

    /** @param list<int> $path */
    private static function nodeAt(array $nodes, array $path): ?array
    {
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

    /** @param list<int> $path */
    private static function replaceAt(array $nodes, array $path, array $replacement): array
    {
        $i = $path[0];
        if (count($path) === 1) {
            $nodes[$i] = $replacement;

            return array_values($nodes);
        }
        $nodes[$i]['children'] = self::replaceAt((array) ($nodes[$i]['children'] ?? []), array_slice($path, 1), $replacement);

        return array_values($nodes);
    }

    /**
     * Each element the answer did not really change keeps its ORIGINAL form, found by uid (Loop 1, 2026-11-18: a stored
     * design writes `props: []` and `level: "1"` where parsed PML has no props and `level: 1`, so a one-heading ask read
     * as "Changed 19 elements"). Only the element's own fields are compared; its children are taken from the answer.
     *
     * @param  list<array<string, mixed>>  $nodes  the answer, parsed
     * @param  list<array<string, mixed>>  $original  the design (or the aimed block) as it was
     * @return list<array<string, mixed>>
     */
    private static function keepUnchanged(array $nodes, array $original): array
    {
        $byUid = [];
        $index = function (array $list) use (&$index, &$byUid): void {
            foreach ($list as $node) {
                if (is_array($node) && is_string($node['uid'] ?? null)) {
                    $byUid[$node['uid']] = $node;
                }
                if (is_array($node) && is_array($node['children'] ?? null)) {
                    $index($node['children']);
                }
            }
        };
        $index($original);

        return self::keepFrom($nodes, $byUid);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $byUid
     * @return list<array<string, mixed>>
     */
    private static function keepFrom(array $nodes, array $byUid): array
    {
        $out = [];
        foreach ($nodes as $node) {
            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::keepFrom($node['children'], $byUid);
            }
            $was = is_string($node['uid'] ?? null) ? ($byUid[$node['uid']] ?? null) : null;
            if ($was !== null && self::ownFields($node) == self::ownFields($was)) {
                // The original, in its own key order, with the answer's children (themselves kept where unchanged).
                $kept = [];
                foreach ($was as $key => $value) {
                    $kept[$key] = $key === 'children' ? ($node['children'] ?? $value) : $value;
                }
                if (! array_key_exists('children', $was) && array_key_exists('children', $node)) {
                    $kept['children'] = $node['children'];
                }
                $node = $kept;
            }
            $out[] = $node;
        }

        return $out;
    }

    /** An element's own fields, normalised: no empty props or style, numbers as numbers, keys in order. */
    private static function ownFields(array $node): array
    {
        unset($node['children']);
        foreach (['props', 'style'] as $key) {
            if (array_key_exists($key, $node) && $node[$key] === []) {
                unset($node[$key]);
            }
        }
        array_walk_recursive($node, function (&$value): void {
            if (is_string($value) && is_numeric($value)) {
                $value += 0;
            }
        });

        return self::sorted($node);
    }

    private static function sorted(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(fn ($item) => is_array($item) ? self::sorted($item) : $item, $value);
    }

    private static function canonical(array $nodes): string
    {
        return (string) json_encode($nodes);
    }
}
