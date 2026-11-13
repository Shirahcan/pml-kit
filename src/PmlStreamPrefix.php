<?php

namespace Shirahcan\Pml;


/**
 * The finished part of a page Porter is still writing (C3, 2026-11-07).
 *
 * Porter writes a page top to bottom, one top-level element after another, separated by a blank line
 * (the serializer's shape, which it copies). Everything before the LAST element start is finished; the
 * last one is still being written. This reads the finished part as nodes, and names the element being
 * written, so the creator can show the page building up in steps. It never decides anything: the final
 * page still goes through the save gate and the merge.
 */
final class PmlStreamPrefix
{
    /** How many element boundaries to step back when the newest finished part does not parse yet. */
    private const TRIES = 3;

    public function __construct(private readonly PmlParser $parser)
    {
    }

    /**
     * @return array{nodes: ?list<array<string, mixed>>, open: ?array<string, mixed>, writing: ?string}
     */
    public function read(string $text): array
    {
        // The caller hands in the page part (a product splits its own reply format first); a code fence
        // the model opened is dropped here.
        $page = ltrim((string) preg_replace('/^\s*```[a-zA-Z]*\s*/', '', $text));
        // Unquoted values in the stream are quoted the way the saved draft will be (PML v3), so the
        // page still grows on screen while Porter writes rather than waiting for the final repair.
        $page = PmlSyntaxMend::quoteValues($page)['pml'];

        // Element starts: the very beginning, and every tag that opens a line after a blank line.
        preg_match_all('/\n[ \t]*\n(?=<[a-z!])/', $page, $matches, PREG_OFFSET_CAPTURE);
        $starts = array_map(static fn (array $m) => $m[1] + strlen($m[0]), $matches[0]);
        $last = $starts === [] ? 0 : end($starts);
        // Only a WHOLE tag name counts: "<h" of "<hero" arriving is not an element called "h".
        $writing = preg_match('/^<([a-z][a-z0-9-]*)[\s\/>]/', substr($page, $last), $tag) === 1 ? str_replace('-', '_', $tag[1]) : null;

        // The element still being written, closed for now so it can be drawn as far as it has got:
        // a big section grows child by child instead of appearing all at once when it is finished.
        $open = $this->openElement(substr($page, $last));

        for ($i = count($starts) - 1, $tries = 0; $i >= 0 && $tries < self::TRIES; $i--, $tries++) {
            try {
                $nodes = array_values($this->parser->parse(substr($page, 0, $starts[$i]))['nodes'] ?? []);
            } catch (\Throwable) {
                continue;
            }

            if ($nodes !== []) {
                // Raw: the caller gives each finished element a STABLE id once (a fresh id per read made
                // every section look new on every update, and the whole draft redraw).
                return ['nodes' => $nodes, 'open' => $open, 'writing' => $writing];
            }
        }

        return ['nodes' => null, 'open' => $open, 'writing' => $writing];
    }

    /**
     * The open element, cut at its last finished line and closed with the tags still open, or null
     * when nothing of it can be drawn yet.
     *
     * @return array<string, mixed>|null
     */
    private function openElement(string $segment): ?array
    {
        $lines = explode("
", $segment);

        for ($cut = count($lines) - 1, $tries = 0; $cut > 0 && $tries < self::TRIES; $cut--) {
            if (! str_ends_with(rtrim($lines[$cut]), '>')) {
                continue;
            }

            $tries++;
            $text = implode("
", array_slice($lines, 0, $cut + 1));
            $stack = $this->openTags($text);

            if ($stack === null || $stack === []) {
                continue;
            }

            try {
                $nodes = array_values($this->parser->parse($text."
".implode("
", array_map(static fn (string $tag) => "</{$tag}>", array_reverse($stack))))['nodes'] ?? []);
            } catch (\Throwable) {
                continue;
            }

            if (count($nodes) === 1) {
                return $nodes[0];
            }
        }

        return null;
    }

    /**
     * The tags still open at the end of `$text`, outermost first; null when the text ends inside a tag
     * or a comment. Quoted attribute values may hold `>`, so quotes are honoured.
     *
     * @return list<string>|null
     */
    private function openTags(string $text): ?array
    {
        $stack = [];
        $length = strlen($text);
        $at = 0;

        while (($lt = strpos($text, '<', $at)) !== false) {
            if (substr($text, $lt, 4) === '<!--') {
                $end = strpos($text, '-->', $lt);
                if ($end === false) {
                    return null;
                }
                $at = $end + 3;

                continue;
            }

            $j = $lt + 1;
            $quote = null;
            for (; $j < $length; $j++) {
                $c = $text[$j];
                if ($quote !== null) {
                    $quote = $c === $quote ? null : $quote;
                } elseif ($c === '"' || $c === "'") {
                    $quote = $c;
                } elseif ($c === '>') {
                    break;
                }
            }

            if ($j >= $length) {
                return null;
            }

            $tag = substr($text, $lt + 1, $j - $lt - 1);

            if (preg_match('/^\/([a-z][a-z0-9-]*)/', $tag, $m) === 1) {
                if (end($stack) === $m[1]) {
                    array_pop($stack);
                }
            } elseif (preg_match('/^([a-z][a-z0-9-]*)/', $tag, $m) === 1 && ! str_ends_with(rtrim($tag), '/') && $m[1] !== 'script') {
                $stack[] = $m[1];
            }

            $at = $j + 1;
        }

        return $stack;
    }
}
