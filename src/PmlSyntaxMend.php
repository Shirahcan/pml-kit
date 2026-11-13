<?php

namespace Shirahcan\Pml;

use Throwable;
use Shirahcan\Pml\PmlSyntaxException;

/**
 * Mends the MECHANICAL syntax faults of a draft Porter wrote, with no model (owner 2026-11-10: "Porter
 * should report the issue/error but should still proceed automatically to attempt a proper fix").
 *
 * Found 2026-11-09: a screenshot mirror at Normal came back with one stray closing tag ("Unexpected closing
 * tag at the top level, line 51"); two paid repairs kept it, and the whole Build was thrown away. A tag
 * that closes nothing, a missing close before its parent's, and a close missing at the very end are
 * structure, not judgement: they are mended here, each one named, before any repair is paid for.
 *
 * Only these three, and only where the parser points. Anything else is left for the repair, which is told
 * the exact fault. Never more than a handful of mends: a draft that needs more is not a draft to patch.
 */
final class PmlSyntaxMend
{
    private const MAX = 6;

    /**
     * @param  callable(string): mixed  $parse  throws PmlSyntaxException on a syntax fault
     * @return array{pml: string, mended: list<string>}
     */
    public static function mend(string $pml, callable $parse): array
    {
        $mended = [];

        // PML v3 (owner 2026-11-10) takes key="value" only. A model that learnt HTML (which allows
        // unquoted values) slips on dozens at once, far past MAX one-at-a-time mends, so the whole
        // draft is quoted in one pass before anything is parsed.
        ['pml' => $pml, 'count' => $quoted] = self::quoteValues($pml);

        if ($quoted > 0) {
            $mended[] = $quoted === 1 ? '1 attribute value put in double quotes' : "{$quoted} attribute values put in double quotes";
        }

        for ($i = 0; $i <= self::MAX; $i++) {
            try {
                $parse($pml);

                return ['pml' => $pml, 'mended' => $mended];
            } catch (PmlSyntaxException $e) {
                $fixed = $i < self::MAX ? self::fixOne($pml, $e) : null;

                if ($fixed === null) {
                    return ['pml' => $pml, 'mended' => $mended];
                }

                [$pml, $what] = $fixed;
                $mended[] = $what;
            } catch (Throwable) {
                // Not a syntax fault (a gate, a PSL compile): the repair is told the real one.
                return ['pml' => $pml, 'mended' => $mended];
            }
        }

        return ['pml' => $pml, 'mended' => $mended];
    }

    /**
     * Put every unquoted attribute value in double quotes: `level=2` becomes `level="2"`. Only inside
     * tags; text between tags, fenced code, `<!-- -->` and the bodies of raw-text elements (`<script>`,
     * `<comment>`) are left exactly as written. `key="..."`, `key={...}` and a bare `key` are untouched.
     *
     * @return array{pml: string, count: int}
     */
    public static function quoteValues(string $pml): array
    {
        $n = strlen($pml);
        $out = '';
        $count = 0;
        $i = 0;
        $lineStart = true;

        while ($i < $n) {
            // Fenced code at the start of a line: copied to its closing fence.
            if ($lineStart && preg_match('/\G[ \t]*```/', $pml, $m, 0, $i) === 1) {
                $close = strpos($pml, '```', $i + strlen($m[0]));
                $end = $close === false ? $n : $close + 3;
                $out .= substr($pml, $i, $end - $i);
                $i = $end;
                $lineStart = false;

                continue;
            }

            $c = $pml[$i];

            if ($c === '<' && substr($pml, $i, 4) === '<!--') {
                $close = strpos($pml, '-->', $i + 4);
                $end = $close === false ? $n : $close + 3;
                $out .= substr($pml, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '<' && $i + 1 < $n && ctype_alpha($pml[$i + 1])) {
                [$tag, $name, $selfClosed, $quoted] = self::quoteTag($pml, $i);
                $out .= $tag;
                $count += $quoted;
                $i += strlen(self::rawTag($pml, $i));

                // A raw-text element's body is the author's verbatim text (PSL, a note).
                if (! $selfClosed && in_array(strtolower($name), ['script', 'comment'], true)) {
                    $close = stripos($pml, '</'.$name, $i);
                    $end = $close === false ? $n : $close;
                    $out .= substr($pml, $i, $end - $i);
                    $i = $end;
                }

                $lineStart = false;

                continue;
            }

            $out .= $c;
            $lineStart = $c === "\n";
            $i++;
        }

        return ['pml' => $out, 'count' => $count];
    }

    /**
     * The raw text of the open tag starting at $at, up to and including its `>` (quotes and braces
     * respected, so a `>` inside a value does not end it).
     */
    private static function rawTag(string $pml, int $at): string
    {
        $n = strlen($pml);
        $i = $at + 1;

        while ($i < $n && $pml[$i] !== '>') {
            if ($pml[$i] === '"') {
                $i = self::skipString($pml, $i);

                continue;
            }

            if ($pml[$i] === '{') {
                $i = self::skipBraces($pml, $i);

                continue;
            }

            $i++;
        }

        return substr($pml, $at, min($n, $i + 1) - $at);
    }

    /**
     * The open tag at $at with its unquoted values quoted.
     *
     * @return array{0: string, 1: string, 2: bool, 3: int} tag text, tag name, self-closed, values quoted
     */
    private static function quoteTag(string $pml, int $at): array
    {
        $raw = self::rawTag($pml, $at);
        preg_match('/^<([A-Za-z][\w-]*)/', $raw, $m);
        $name = $m[1] ?? '';
        $len = strlen($raw);
        $i = strlen($m[0] ?? '<');
        $out = substr($raw, 0, $i);
        $count = 0;

        while ($i < $len) {
            $c = $raw[$i];

            if ($c === '"') {
                $end = self::skipString($raw, $i);
                $out .= substr($raw, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '{') {
                $end = self::skipBraces($raw, $i);
                $out .= substr($raw, $i, $end - $i);
                $i = $end;

                continue;
            }

            if ($c === '=') {
                $out .= '=';
                $i++;

                while ($i < $len && ($raw[$i] === ' ' || $raw[$i] === "\t")) {
                    $out .= $raw[$i];
                    $i++;
                }

                $start = $i;

                while ($i < $len && strpos(" \t\r\n>/\"{}=", $raw[$i]) === false) {
                    $i++;
                }

                if ($i > $start) {
                    $out .= '"'.substr($raw, $start, $i - $start).'"';
                    $count++;
                }

                continue;
            }

            $out .= $c;
            $i++;
        }

        return [$out, $name, str_ends_with(rtrim($raw), '/>'), $count];
    }

    /** The offset just past the "..." string starting at $at (backslash escapes respected). */
    private static function skipString(string $s, int $at): int
    {
        $n = strlen($s);
        $i = $at + 1;

        while ($i < $n && $s[$i] !== '"') {
            $i += $s[$i] === '\\' ? 2 : 1;
        }

        return min($n, $i + 1);
    }

    /** The offset just past the {...} starting at $at (nested braces and "strings" inside respected). */
    private static function skipBraces(string $s, int $at): int
    {
        $n = strlen($s);
        $depth = 0;
        $i = $at;

        while ($i < $n) {
            if ($s[$i] === '"') {
                $i = self::skipString($s, $i);

                continue;
            }

            if ($s[$i] === '{') {
                $depth++;
            } elseif ($s[$i] === '}') {
                $depth--;

                if ($depth === 0) {
                    return $i + 1;
                }
            }

            $i++;
        }

        return $n;
    }

    /**
     * A prop the page check refuses on one kind of element (found 2026-11-10: Normal mirrors wrote
     * `<box id=...>` and `<grid as=section>`, and both paid repairs and the rewrite kept them). A prop an
     * element cannot hold changes nothing a visitor would see, so it is dropped from every element of that
     * kind; HTML's `id=` becomes PML's `anchor=` where the element takes one (a `<video>`'s own `id`, its
     * provider's video id, is never touched: it is allowed there). Null when the fault is not this one.
     *
     * @return array{pml: string, mended: string}|null
     */
    public static function refusedProp(string $pml, string $fault): ?array
    {
        if (preg_match('/^The prop "([a-z_]+)" is not allowed on "([a-z_]+)"/', $fault, $m) !== 1) {
            return null;
        }

        [, $prop, $primitive] = $m;
        $allowed = PrimitiveSchema::allowedProps($primitive);

        // An unknown element is not this mend's to judge.
        if ($allowed === null) {
            return null;
        }

        $asAnchor = $prop === 'id' && in_array('anchor', $allowed, true);
        $name = '(?:'.preg_quote($prop, '/').'|'.preg_quote(str_replace('_', '-', $prop), '/').')';
        $value = '(?:"[^"]*"|\'[^\']*\'|\{[^}]*\}|[^\s\/>]+)';
        $tag = '(?:'.preg_quote($primitive, '/').'|'.preg_quote(str_replace('_', '-', $primitive), '/').')';
        $mended = preg_replace_callback(
            '/<'.$tag.'\b[^>]*>/',
            static fn (array $t): string => (string) ($asAnchor
                ? preg_replace('/(\s)'.$name.'=/', '$1anchor=', $t[0])
                : preg_replace('/\s'.$name.'='.$value.'/', '', $t[0])),
            $pml,
        );
        $element = '<'.str_replace('_', '-', $primitive).'>';

        if ($mended === null || $mended === $pml) {
            return null;
        }

        return ['pml' => $mended, 'mended' => $asAnchor ? 'id= written as anchor= on '.$element : str_replace('_', '-', $prop).'= dropped from '.$element.' (it cannot hold it)'];
    }

    /** @return array{0: string, 1: string}|null the mended source and what was mended, in words */
    private static function fixOne(string $pml, PmlSyntaxException $e): ?array
    {
        $message = $e->getMessage();
        $at = self::offset($pml, $e->atLine, $e->atColumn);

        // A closing tag that closes nothing: dropped.
        if (str_starts_with($message, 'Unexpected closing tag at the top level.')) {
            if (preg_match('/<\/\s*([A-Za-z][\w-]*)\s*>/', $pml, $m, PREG_OFFSET_CAPTURE, max(0, $at - 2)) !== 1) {
                return null;
            }

            [$tag, $from] = [$m[0][0], (int) $m[0][1]];
            $before = substr($pml, 0, $from);
            $after = substr($pml, $from + strlen($tag));

            // The line it leaves empty goes too.
            if (trim(substr($before, (int) strrpos("\n".$before, "\n"))) === '' && preg_match('/^[ \t]*\r?\n/', $after, $nl) === 1) {
                $after = substr($after, strlen($nl[0]));
            }

            return [$before.$after, 'a closing tag '.$tag.' that closed nothing (line '.$e->atLine.')'];
        }

        // </a> while <b> is still open inside it: <b> is closed first.
        if (preg_match('/^Closing tag <\/([A-Za-z][\w-]*)> does not match <([A-Za-z][\w-]*)>\./', $message, $m) === 1) {
            $close = '</'.$m[1].'>';
            $where = strrpos(substr($pml, 0, min(strlen($pml), $at + 1)), $close);

            if ($where === false) {
                return null;
            }

            return [substr($pml, 0, $where).'</'.$m[2].'>'.substr($pml, $where), 'a missing </'.$m[2].'> before </'.$m[1].'> (line '.$e->atLine.')'];
        }

        // The draft ended with an element still open: closed at the end.
        if (preg_match('/^Unclosed <([A-Za-z][\w-]*)>\./', $message, $m) === 1 && $at >= strlen(rtrim($pml)) - 1) {
            return [rtrim($pml)."\n</".$m[1].'>'."\n", 'a missing </'.$m[1].'> at the end'];
        }

        return null;
    }

    /** The byte offset of a 1-based line and column. */
    private static function offset(string $pml, int $line, int $column): int
    {
        $offset = 0;

        for ($l = 1; $l < $line; $l++) {
            $next = strpos($pml, "\n", $offset);

            if ($next === false) {
                return strlen($pml);
            }

            $offset = $next + 1;
        }

        return min(strlen($pml), $offset + max(0, $column - 1));
    }
}
