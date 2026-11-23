<?php

namespace Shirahcan\Pml\Build;

/**
 * An answer to a page edit, split into the page and the assistant's words to the person (shared-page-build S2a,
 * 2026-11-23; moved from Portify's `SitePmlPorterTurn`, which now reads it from here). The `shared.pml.edit` prompts
 * ask for the page, then {@see REPLY_MARKER} on its own line, then one or two sentences.
 */
final class PageReply
{
    /**
     * The assistant's words to the person come AFTER the page, below this line. Before it, the assistant had no
     * voice: a refusal was written into the page as a <!-- --> comment while the box said "Done". The reply comes last
     * so a streamed page arrives first.
     */
    public const REPLY_MARKER = '=== PORTER REPLY ===';

    /**
     * A line that opens a tag (`<box`, `</box>`, `<!--`), raw or HTML-escaped: page source, not words. Where a page's
     * source starts; a component's can start earlier, at its setting lines (its caller passes its own start).
     */
    public const TAG_LINE = '/^[ \t]*(?:<|&lt;)[a-z!\/]/im';

    /**
     * The fault an answer with no page in it is sent back with (and the reason the person reads when the repair
     * cannot fix it). Phrased for both readers: the repair prompt says "fix exactly this".
     */
    public const NO_PAGE_FAULT = 'the answer was only words to the person, with no page source before the reply line';

    // 4,000 since 2026-11-09 (was 600, cut mid-word): the console folds a long reply, it never cuts it.
    private const MAX_REPLY_CHARS = 4000;

    /** Whether this text holds page source at all (any line opening a tag), rather than only words. */
    public static function hasMarkup(string $text): bool
    {
        return preg_match(self::TAG_LINE, $text) === 1;
    }

    /**
     * Split what the model wrote into the page source and its reply to the person (null when it gave none).
     *
     * ⚠ THE PAGE IS WHERE THE MARKUP IS, NOT WHERE THE ORDER SAYS (2026-11-09). The contract is page, reply line,
     * reply; a console Build wrote reply, reply line, the reply again, THEN the page. Trusting the order made one
     * sentence the whole page (PML reads bare words as a paragraph, so it passed every check) and put the page in the
     * reply. When the words come first, the page is what follows the reply line from where its source starts
     * (`$sourceStart`: its first tag, or for a component its first setting line), and the first words are the reply.
     *
     * @return array{pml: string, reply: ?string}
     */
    public static function split(string $out, string $sourceStart = self::TAG_LINE): array
    {
        $at = strrpos($out, self::REPLY_MARKER);

        if ($at === false) {
            return ['pml' => $out, 'reply' => null];
        }

        $before = substr($out, 0, $at);
        $after = substr($out, $at + strlen(self::REPLY_MARKER));

        if (! self::hasMarkup($before) && self::hasMarkup($after)) {
            [$lead, $page] = self::atFirst($after, $sourceStart);

            return ['pml' => rtrim($page), 'reply' => self::plainReply(trim($before) !== '' ? $before : $lead)];
        }

        return ['pml' => rtrim($before), 'reply' => self::plainReply($after)];
    }

    /**
     * The page part of an answer still streaming in, read by a step log and a growing preview: what comes before the
     * reply line, or, when the reply came first, what follows it from where its source starts (the same rule as
     * {@see split}, so the preview and the saved page agree).
     */
    public static function pageSoFar(string $text, string $sourceStart = self::TAG_LINE): string
    {
        $at = strpos($text, self::REPLY_MARKER);

        if ($at === false) {
            return $text;
        }

        $before = substr($text, 0, $at);

        return self::hasMarkup($before) ? $before : self::atFirst(substr($text, $at + strlen(self::REPLY_MARKER)), $sourceStart)[1];
    }

    /**
     * The words before the first line where the source starts, and the source from that line on.
     *
     * @return array{0: string, 1: string}
     */
    private static function atFirst(string $text, string $sourceStart): array
    {
        if (preg_match($sourceStart, $text, $match, PREG_OFFSET_CAPTURE) !== 1) {
            return [$text, ''];
        }

        $at = (int) $match[0][1];

        return [substr($text, 0, $at), substr($text, $at)];
    }

    /** The assistant's words as a box shows them: plain text (Markdown would show as raw asterisks), capped. */
    private static function plainReply(string $words): ?string
    {
        $reply = trim((string) preg_replace(['/\*\*(.+?)\*\*/s', '/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '/`([^`]+)`/'], '$1', $words));

        if ($reply === '') {
            return null;
        }

        if (mb_strlen($reply) <= self::MAX_REPLY_CHARS) {
            return $reply;
        }

        $cut = mb_substr($reply, 0, self::MAX_REPLY_CHARS - 1);
        $space = mb_strrpos($cut, ' ');

        return rtrim($space !== false && $space > self::MAX_REPLY_CHARS * 0.8 ? mb_substr($cut, 0, $space) : $cut).'…';
    }
}
