<?php

namespace Shirahcan\Pml\Html;

use DOMDocument;
use DOMElement;
use DOMNode;
use Shirahcan\Pml\Email\EmailProfile;

/**
 * Sanitised HTML as PML elements (pml-kit 0.24.0, designer-sole-surface P3): the generic core that
 * `Email\SemanticToPml` used to hold alone, so a written blog post and a semantic email body convert the same way.
 *
 * It PLACES WORDS, it never emits HTML. Words, links, headings, lists, tables, quotes, dividers and images become their
 * elements; where styling has no PML home its words are kept and the change is listed in `notes`; an element it cannot
 * place at all is described in `unconverted` and never dropped silently, so a caller converts a body only when
 * `unconverted` is empty and keeps the original otherwise.
 *
 * Two profiles:
 * - PAGE: headings keep levels 1-4 (5 and 6 become 4); `figure` + `figcaption` become an image with its caption; `pre`
 *   becomes a code block; a paragraph holding only an image becomes that image.
 * - EMAIL: headings stop at {@see EmailProfile::MAX_HEADING_LEVEL}, as an email allows.
 *
 * Expects ALREADY-SANITISED HTML. A product's own placeholder syntax plugs in through {@see HtmlTokens}.
 */
final class HtmlToPml
{
    public const PAGE = 'page';

    public const EMAIL = 'email';

    /** The deepest heading a page uses (PML headings are levels 1-4). */
    private const PAGE_MAX_HEADING_LEVEL = 4;

    private const INLINE = ['strong', 'b', 'em', 'i', 'u', 's', 'a', 'code', 'small', 'span', 'br'];

    /** Elements that carry no words of their own, so a paragraph holding only them is still walked. */
    private const WORDLESS = '/<(img|iframe|video|audio|object|embed|svg|canvas|input|select|textarea|button|form)\b/i';

    /** Block-level tags whose edges are word boundaries when a page flattens an element's words. */
    private const WORD_BREAKS = ['p', 'div', 'li', 'ul', 'ol', 'br', 'table', 'tr', 'td', 'th', 'blockquote', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'figcaption', 'pre', 'section', 'article', 'aside', 'hr'];

    private string $profile = self::PAGE;

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $unconverted = [];

    public function __construct(private readonly ?HtmlTokens $tokens = null) {}

    /** @return array{nodes: list<array<string, mixed>>, notes: list<string>, unconverted: list<string>} */
    public function convert(string $html, string $profile = self::PAGE): array
    {
        $this->profile = $profile === self::EMAIL ? self::EMAIL : self::PAGE;
        $this->notes = [];
        $this->unconverted = [];

        $root = $this->fragment($html);
        $nodes = $root === null ? [] : $this->blocks($root);

        return ['nodes' => $nodes, 'notes' => array_values(array_unique($this->notes)), 'unconverted' => $this->unconverted];
    }

    /** @return list<array<string, mixed>> */
    private function blocks(DOMNode $parent): array
    {
        $out = [];
        $inline = '';

        foreach ($parent->childNodes as $child) {
            $isInline = ! $child instanceof DOMElement || in_array(strtolower($child->tagName), self::INLINE, true);

            if ($isInline) {
                $inline .= $child->ownerDocument->saveHTML($child);

                continue;
            }

            $out = [...$out, ...$this->paragraphHtml($inline), ...$this->block($child)];
            $inline = '';
        }

        return [...$out, ...$this->paragraphHtml($inline)];
    }

    /** @return list<array<string, mixed>> */
    private function block(DOMElement $el): array
    {
        $tag = strtolower($el->tagName);

        if ($this->profile === self::PAGE) {
            $page = match ($tag) {
                'figure' => $this->figure($el),
                'figcaption' => $this->paragraphHtml($this->innerHtml($el)),
                'pre' => [['primitive' => 'pre', 'props' => ['text' => rtrim($el->textContent, "\n")]]],
                'article' => $this->blocks($el),
                default => null,
            };
            if ($page !== null) {
                return $page;
            }
        }

        return match ($tag) {
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6' => [$this->heading($el)],
            'p' => $this->paragraph($el),
            'ul', 'ol' => [$this->listBlock($el)],
            'table' => [$this->table($el)],
            'aside' => [['primitive' => 'callout', 'children' => $this->blocks($el)]],
            'blockquote' => [['primitive' => 'blockquote', 'children' => $this->blocks($el)]],
            'hr' => [['primitive' => 'divider']],
            'img' => [$this->image($el)],
            'tbody', 'thead', 'div', 'section' => $this->blocks($el),
            default => $this->unknown($el),
        };
    }

    private function heading(DOMElement $el): array
    {
        $text = $this->flatten($el);
        $level = max(1, (int) substr(strtolower($el->tagName), 1));

        if ($el->getElementsByTagName('*')->length > 0 || $this->tokens?->hasInlineToken($el->textContent)) {
            $this->notes[] = $this->profile === self::EMAIL
                ? 'A heading keeps its words, but not its badge or emphasis ("'.mb_substr($text, 0, 60).'").'
                : 'A heading keeps its words, but not its emphasis or links ("'.mb_substr($text, 0, 60).'").';
        }

        if ($this->profile === self::PAGE && $level > self::PAGE_MAX_HEADING_LEVEL) {
            $this->notes[] = 'A level-'.$level.' heading became level '.self::PAGE_MAX_HEADING_LEVEL.', the deepest a page uses.';
        }

        $max = $this->profile === self::EMAIL ? EmailProfile::MAX_HEADING_LEVEL : self::PAGE_MAX_HEADING_LEVEL;

        return ['primitive' => 'heading', 'props' => ['text' => $text, 'level' => min($max, $level)]];
    }

    /** A paragraph: fine print, an image on its own (page), or words and block tokens in order. @return list<array<string, mixed>> */
    private function paragraph(DOMElement $el): array
    {
        $only = $this->onlyChild($el);

        if ($only instanceof DOMElement && strtolower($only->tagName) === 'small') {
            return [['primitive' => 'p', 'children' => [['primitive' => 'small', 'children' => $this->inlines($only)]]]]; // fine print: PML's small sits inside a paragraph
        }

        if ($this->profile === self::PAGE && ($images = $this->onlyImages($el)) !== null) {
            return array_map(fn (DOMElement $img) => $this->image($img), $images);
        }

        return $this->paragraphHtml($this->innerHtml($el));
    }

    /** A paragraph holding nothing but images (and line breaks) is those images on a page. @return list<DOMElement>|null */
    private function onlyImages(DOMElement $el): ?array
    {
        $images = [];
        foreach ($el->childNodes as $n) {
            if ($n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '') {
                continue;
            }
            if (! $n instanceof DOMElement || ! in_array(strtolower($n->tagName), ['img', 'br'], true)) {
                return null;
            }
            if (strtolower($n->tagName) === 'img') {
                $images[] = $n;
            }
        }

        return $images === [] ? null : $images;
    }

    /** @return list<array<string, mixed>> */
    private function paragraphHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (($whole = $this->tokens?->wholeVariable($html)) !== null) {
            return [$whole];
        }

        $out = [];
        foreach ($this->tokens?->split($html) ?? [['kind' => 'html', 'html' => $html]] as $piece) {
            if ($piece['kind'] === 'token') {
                $out = [...$out, ...$this->token($piece['name'], $piece['body'])];
            } elseif (trim(html_entity_decode(strip_tags($piece['html']))) !== '' || str_contains($piece['html'], '<br') || preg_match(self::WORDLESS, $piece['html']) === 1) {
                // A piece with no words can still hold an element (an image, a frame): it is walked so the element is
                // placed or listed as unconverted, never dropped without a trace.
                $root = $this->fragment($piece['html']);
                $inlines = $root === null ? [] : $this->inlines($root);
                if ($inlines !== []) {
                    $out[] = ['primitive' => 'p', 'children' => $inlines];
                }
            }
        }

        return $out;
    }

    /** A block token as its elements. @return list<array<string, mixed>> */
    private function token(string $name, string $body): array
    {
        $nodes = $this->tokens?->block($name, $body, function (string $html): array {
            $root = $this->fragment($html);

            return $root === null ? [] : $this->inlines($root);
        });

        if ($nodes === null) {
            $this->unconverted[] = '{{'.$name.':}} is retired and has no PML element.';

            return [];
        }

        return $nodes;
    }

    /** Inline content with marks and links. @return list<array<string, mixed>> */
    private function inlines(DOMNode $parent): array
    {
        $out = [];

        foreach ($parent->childNodes as $child) {
            if (! $child instanceof DOMElement) {
                $out = [...$out, ...$this->inlineText($child->textContent)];

                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, ['u', 's'], true)) {
                $this->notes[] = $this->profile === self::EMAIL
                    ? 'Underline and strike-through are not email elements; their words are kept.'
                    : 'Underline and strike-through are not page elements; their words are kept.';
            }
            if (! in_array($tag, self::INLINE, true)) {
                $this->unconverted[] = $this->profile === self::EMAIL
                    ? '<'.$tag.'> inside a paragraph: '.mb_substr(trim($child->textContent), 0, 80)
                    : $this->describe($child).' inside a paragraph';

                continue;
            }

            $kids = $this->inlines($child);
            $out[] = match ($tag) {
                'strong', 'b' => ['primitive' => 'strong', 'children' => $kids],
                'em', 'i' => ['primitive' => 'emphasis', 'children' => $kids],
                'small' => ['primitive' => 'small', 'children' => $kids],
                'code' => ['primitive' => 'code', 'props' => ['text' => $child->textContent]],
                'a' => ['primitive' => 'link', 'props' => ['href' => self::href($child)], 'children' => $kids],
                'br' => ['primitive' => 'br'],
                default => ['primitive' => 'span', 'children' => $kids],
            };
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function inlineText(string $text): array
    {
        if ($this->tokens !== null) {
            return $this->tokens->inlineText($text);
        }

        return $text === '' ? [] : [['primitive' => 'text', 'props' => ['value' => $text]]];
    }

    /** A list; an item that opens with a bold label keeps it as the item's title ("**Payments** – Accept..."). */
    private function listBlock(DOMElement $el): array
    {
        $items = [];

        foreach ($el->childNodes as $li) {
            if (! $li instanceof DOMElement || strtolower($li->tagName) !== 'li') {
                continue;
            }

            $source = $this->onlyChild($li);
            $source = $source instanceof DOMElement && strtolower($source->tagName) === 'p' ? $source : $li;
            $first = $this->firstMeaningful($source);
            $words = $this->flatten($source);
            $title = '';
            $marks = $source->getElementsByTagName('*')->length;

            if ($first instanceof DOMElement && in_array(strtolower($first->tagName), ['strong', 'b'], true) && $first->getElementsByTagName('*')->length === 0) {
                $title = $this->flatten($first);
                $words = trim(mb_substr($words, mb_strlen($title)));
                $marks--;
            }

            if ($this->profile === self::PAGE && ($source->getElementsByTagName('ul')->length + $source->getElementsByTagName('ol')->length) > 0) {
                $this->notes[] = 'A list inside a list item became part of that item\'s words ("'.mb_substr($words, 0, 60).'").';
            }

            if ($source->getElementsByTagName('a')->length > 0) {
                $this->unconverted[] = 'A link inside a list item (a list item holds words only): '.mb_substr($words, 0, 80);
            } elseif ($marks > 0) {
                $this->notes[] = 'A list item keeps its words, but not bold, italic or links after its opening label.';
            }

            $items[] = ['primitive' => 'list_item', 'props' => array_filter(['title' => $title, 'text' => $words], fn ($v) => $v !== '')];
        }

        return ['primitive' => 'list', 'props' => ['type' => strtolower($el->tagName) === 'ol' ? 'numbered' : 'bullet'], 'children' => $items];
    }

    private function table(DOMElement $el): array
    {
        $rows = [];

        foreach ($el->getElementsByTagName('tr') as $tr) {
            $cells = [];
            foreach ($tr->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $inner = $this->onlyChild($cell);
                    $inlines = $this->inlines($inner instanceof DOMElement && strtolower($inner->tagName) === 'p' ? $inner : $cell);
                    $cells[] = ['primitive' => 'cell', ...(strtolower($cell->tagName) === 'th' ? ['props' => ['header' => true]] : []), 'children' => $inlines === [] ? [] : [['primitive' => 'p', 'children' => $inlines]]];
                }
            }
            $rows[] = ['primitive' => 'row', 'children' => $cells];
        }

        return ['primitive' => 'table', 'children' => $rows];
    }

    private function image(DOMElement $el): array
    {
        $alt = trim($el->getAttribute('alt'));

        if ($alt === '') {
            $this->notes[] = $this->profile === self::EMAIL
                ? 'An image has no description; it needs one before the email can be saved.'
                : 'An image has no description; add one so a screen reader can say what it shows.';
        }

        return ['primitive' => 'image', 'props' => ['src' => $el->getAttribute('src'), 'alt' => $alt]];
    }

    /**
     * A figure (page): an image with its caption. Anything else inside keeps its own elements, and a caption that has
     * no image to sit on becomes a paragraph after them, listed in notes.
     *
     * @return list<array<string, mixed>>
     */
    private function figure(DOMElement $el): array
    {
        $caption = '';
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof DOMElement && strtolower($child->tagName) === 'figcaption') {
                $caption = trim($caption.' '.$this->flatten($child));
                $el->removeChild($child);
            }
        }

        $nodes = $this->blocks($el);
        if ($caption === '') {
            return $nodes;
        }

        if (count($nodes) === 1 && ($nodes[0]['primitive'] ?? null) === 'image') {
            $nodes[0]['props']['caption'] = $caption;

            return $nodes;
        }

        $this->notes[] = 'A caption with no single image to sit under became a paragraph ("'.mb_substr($caption, 0, 60).'").';

        return [...$nodes, ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => $caption]]]]];
    }

    /** @return list<array<string, mixed>> */
    private function unknown(DOMElement $el): array
    {
        $this->unconverted[] = $this->profile === self::EMAIL
            ? '<'.strtolower($el->tagName).'>: '.mb_substr(trim($el->textContent), 0, 80)
            : $this->describe($el);

        return [];
    }

    /** A short, readable name for an element that has no PML home ("an embedded frame (youtube.com)"). */
    private function describe(DOMElement $el): string
    {
        $tag = strtolower($el->tagName);
        $host = (string) preg_replace('/^www\./i', '', (string) parse_url($el->getAttribute('src') ?: $el->getAttribute('data') ?: $el->getAttribute('action'), PHP_URL_HOST));
        $from = $host !== '' ? ' ('.$host.')' : '';
        $words = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $el->textContent)), 0, 60);

        return match ($tag) {
            'iframe' => 'an embedded frame'.$from,
            'video' => 'a video'.$from,
            'audio' => 'an audio clip'.$from,
            'object', 'embed' => 'an embedded object'.$from,
            'script', 'noscript' => 'a script',
            'form' => 'a form'.$from,
            'input', 'select', 'textarea', 'button' => 'a form field',
            'svg', 'canvas' => 'a drawing',
            'img' => 'an image'.$from,
            default => 'a <'.$tag.'> element'.($words !== '' ? ': "'.$words.'"' : ''),
        };
    }

    /**
     * All words of an element, inline tokens as their words, runs of space as one. On a page a block edge inside it
     * (a nested list's item, a paragraph in a list item) is a word boundary: sanitised HTML carries no whitespace
     * between block tags, so "acceptance<ul><li>from" would otherwise read "acceptancefrom". The email profile keeps
     * its original flattening so its output is unchanged.
     */
    private function flatten(DOMNode $node): string
    {
        $raw = $this->profile === self::PAGE ? $this->spacedText($node) : $node->textContent;
        $text = $this->tokens?->words($raw) ?? $raw;

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function spacedText(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            return $node->textContent;
        }

        $text = '';
        foreach ($node->childNodes as $child) {
            $part = $this->spacedText($child);
            $text .= $child instanceof DOMElement && in_array(strtolower($child->tagName), self::WORD_BREAKS, true) ? ' '.$part.' ' : $part;
        }

        return $text;
    }

    /** A link's address; libxml percent-encodes `{{ }}` when it re-serialises a fragment, so a variable is restored. */
    private static function href(DOMElement $a): string
    {
        return str_ireplace(['%7B', '%7D'], ['{', '}'], $a->getAttribute('href'));
    }

    private function fragment(string $html): ?DOMElement
    {
        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><div id="pml-root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        return $doc->getElementById('pml-root');
    }

    private function innerHtml(DOMElement $el): string
    {
        $html = '';
        foreach ($el->childNodes as $child) {
            $html .= $el->ownerDocument->saveHTML($child);
        }

        return $html;
    }

    private function onlyChild(DOMElement $el): ?DOMNode
    {
        $kids = array_values(array_filter(iterator_to_array($el->childNodes), fn (DOMNode $n) => ! ($n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '')));

        return count($kids) === 1 ? $kids[0] : null;
    }

    private function firstMeaningful(DOMElement $el): ?DOMNode
    {
        foreach ($el->childNodes as $n) {
            if (! ($n->nodeType === XML_TEXT_NODE && trim($n->textContent) === '')) {
                return $n;
            }
        }

        return null;
    }
}
