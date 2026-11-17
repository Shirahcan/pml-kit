<?php

namespace Shirahcan\Pml\Html;

use Closure;

/**
 * Placeholder syntax a product's HTML carries on top of plain markup, so {@see HtmlToPml} can place it without knowing
 * any one product's vocabulary. Email bodies are the case today ({@see \Shirahcan\Pml\Email\EmailBodyTokens}: block
 * tokens such as `{{action_button}}`, prepared-HTML variables, inline `{{badge_*:TEXT}}`). A page has none.
 */
interface HtmlTokens
{
    /**
     * A paragraph's HTML in reading order: plain HTML pieces and block-token pieces.
     *
     * @return list<array{kind: 'html', html: string}|array{kind: 'token', name: string, body: string}>
     */
    public function split(string $html): array;

    /**
     * A paragraph that is nothing but one prepared-HTML variable, as its own block (so that HTML never sits inside a
     * paragraph); null when the paragraph is anything else.
     *
     * @return array<string, mixed>|null
     */
    public function wholeVariable(string $html): ?array;

    /**
     * A block token as its elements; null when the token has no element (the caller lists it as unconverted).
     *
     * @param  Closure(string): list<array<string, mixed>>  $inlines  an HTML fragment as inline elements
     * @return list<array<string, mixed>>|null
     */
    public function block(string $name, string $body, Closure $inlines): ?array;

    /**
     * Plain text as inline elements, an inline token as its element.
     *
     * @return list<array<string, mixed>>
     */
    public function inlineText(string $text): array;

    /** Text with every inline token as its words. */
    public function words(string $text): string;

    /** Whether text carries an inline token (a heading keeps its words, not the token). */
    public function hasInlineToken(string $text): bool;
}
