<?php

namespace Shirahcan\Pml\Email;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * A semantic email body (the WYSIWYG editor's vocabulary and its tokens) as a PML email design (pml-email B2a). The
 * same for every product that authored emails that way. It loses no words, variables or links (SemanticParity proves
 * it per body); where styling has no PML home its words are kept and the change is listed in `notes`, and an element
 * it cannot place at all goes to `unconverted`, so the caller keeps the old body for that email.
 *
 * Block tokens become elements: `{{action_button}}` the main button (bound to `{{action_label}}` / `{{action_url}}`
 * and left out at send time when either is empty, as today), `{{button:LABEL = URL}}` a second button, `{{divider}}`
 * a divider, `{{alert_*:TEXT}}` a toned callout, `{{code:LABEL = VALUE}}` a labelled code panel. A paragraph that is
 * only a prepared-HTML variable (`{{summary_html}}`) becomes a block of its own, so that HTML never sits in a `<p>`.
 * Inline `{{badge_*:TEXT}}` becomes a `mark`.
 */
final class SemanticToPml
{
    private const ALERT_TONE = ['alert_info' => 'info', 'alert_success' => 'success', 'alert_warning' => 'warning', 'alert_error' => 'danger'];

    private const INLINE = ['strong', 'b', 'em', 'i', 'u', 's', 'a', 'code', 'small', 'span', 'br'];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $unconverted = [];

    /** @return array{nodes: list<array<string, mixed>>, notes: list<string>, unconverted: list<string>} */
    public function convert(string $html): array
    {
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
        return match (strtolower($el->tagName)) {
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

        if ($el->getElementsByTagName('*')->length > 0 || str_contains($el->textContent, '{{badge_')) {
            $this->notes[] = 'A heading keeps its words, but not its badge or emphasis ("'.mb_substr($text, 0, 60).'").';
        }

        return ['primitive' => 'heading', 'props' => ['text' => $text, 'level' => min(EmailProfile::MAX_HEADING_LEVEL, max(1, (int) substr(strtolower($el->tagName), 1)))]];
    }

    /** A paragraph: fine print, a lone prepared-HTML variable, or words and block tokens in order. @return list<array<string, mixed>> */
    private function paragraph(DOMElement $el): array
    {
        $only = $this->onlyChild($el);

        if ($only instanceof DOMElement && strtolower($only->tagName) === 'small') {
            return [['primitive' => 'small', 'children' => $this->inlines($only)]];
        }

        return $this->paragraphHtml($this->innerHtml($el));
    }

    /** @return list<array<string, mixed>> */
    private function paragraphHtml(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        if (($name = EmailValues::preparedPlaceholder(trim(strip_tags($html)))) !== null && trim(strip_tags($html)) === trim($html)) {
            return [['primitive' => 'text', 'props' => ['value' => '{{'.$name.'}}']]];
        }

        $out = [];
        foreach (SemanticTokens::split($html) as $piece) {
            if ($piece['kind'] === 'token') {
                $out = [...$out, ...$this->token($piece['name'], $piece['body'])];
            } elseif (trim(html_entity_decode(strip_tags($piece['html']))) !== '' || str_contains($piece['html'], '<br')) {
                $root = $this->fragment($piece['html']);
                $inlines = $root === null ? [] : $this->inlines($root);
                if ($inlines !== []) {
                    $out[] = ['primitive' => 'p', 'children' => $inlines];
                }
            }
        }

        return $out;
    }

    /** A block token as its element. @return list<array<string, mixed>> */
    private function token(string $name, string $body): array
    {
        if ($name === 'action_button') {
            return [['primitive' => 'button', 'props' => ['label' => '{{action_label}}', 'href' => '{{action_url}}', 'variant' => 'primary'], 'style' => ['align' => 'center']]];
        }
        if ($name === 'divider') {
            return [['primitive' => 'divider']];
        }
        if (isset(self::ALERT_TONE[$name])) {
            $root = $this->fragment($body);

            return [['primitive' => 'callout', 'props' => ['tone' => self::ALERT_TONE[$name]], 'children' => [['primitive' => 'p', 'children' => $root === null ? [] : $this->inlines($root)]]]];
        }
        if ($name === 'button') {
            [$label, $href] = SemanticTokens::pair($body);

            return [['primitive' => 'button', 'props' => ['label' => $label, 'href' => $href, 'variant' => 'primary'], 'style' => ['align' => 'center']]];
        }
        if ($name === 'code') {
            [$label, $value] = SemanticTokens::pair($body);

            return [['primitive' => 'box', 'style' => ['background' => 'surface', 'padding' => 'normal', 'radius' => 'md'], 'children' => [
                ['primitive' => 'small', 'children' => [['primitive' => 'text', 'props' => ['value' => $label]]]],
                ['primitive' => 'pre', 'props' => ['text' => $value]],
            ]]];
        }

        $this->unconverted[] = '{{'.$name.':}} is retired and has no PML element.';

        return [];
    }

    /** Inline content with marks, links and inline badges. @return list<array<string, mixed>> */
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
                $this->notes[] = 'Underline and strike-through are not email elements; their words are kept.';
            }
            if (! in_array($tag, self::INLINE, true)) {
                $this->unconverted[] = '<'.$tag.'> inside a paragraph: '.mb_substr(trim($child->textContent), 0, 80);

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

    /** Words, with inline `{{badge_*:TEXT}}` as a mark. @return list<array<string, mixed>> */
    private function inlineText(string $text): array
    {
        $out = [];
        foreach (preg_split('/(\{\{badge_(?:info|success|warning|error):[^{}]*\}\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $out[] = preg_match('/^\{\{badge_[a-z]+:([^{}]*)\}\}$/', $part, $m) === 1
                ? ['primitive' => 'mark', 'children' => [['primitive' => 'text', 'props' => ['value' => $m[1]]]]]
                : ['primitive' => 'text', 'props' => ['value' => $part]];
        }

        return $out;
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
            $this->notes[] = 'An image has no description; it needs one before the email can be saved.';
        }

        return ['primitive' => 'image', 'props' => ['src' => $el->getAttribute('src'), 'alt' => $alt]];
    }

    /** @return list<array<string, mixed>> */
    private function unknown(DOMElement $el): array
    {
        $this->unconverted[] = '<'.strtolower($el->tagName).'>: '.mb_substr(trim($el->textContent), 0, 80);

        return [];
    }

    /** All words of an element, badges as their words, runs of space as one. */
    private function flatten(DOMNode $node): string
    {
        $text = (string) preg_replace('/\{\{badge_[a-z]+:([^{}]*)\}\}/', '$1', $node->textContent);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
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
