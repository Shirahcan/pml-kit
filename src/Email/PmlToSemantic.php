<?php

namespace Shirahcan\Pml\Email;

use Shirahcan\Pml\MarkdownToDoc;

/**
 * A PML email design as a semantic email body (pml-email C2c): the inverse of SemanticToPml. A product that keeps its
 * emails' HTML body as the fallback (the classic editor, or the send path with the PML gate off) writes this beside a
 * design an author saves, so turning the gate off never sends older words than the author's.
 *
 * It emits only the closed semantic vocabulary (p, h1-h3, ul/ol/li, blockquote, hr, br, table/tbody/tr/td/th, img,
 * aside, small; strong, em, code, a[href]; the block and inline tokens) and never `style`, `class`, `div` or `span`,
 * shaped as the WYSIWYG editor serialises it (list items and cells hold a `<p>`, cells carry colspan/rowspan 1, block
 * tokens sit in a paragraph of their own). It loses no words, variables or links: where a design's styling has no
 * semantic home (a box's background, a spacer, a badge's tone) the words are kept and the change is listed in `notes`;
 * anything it cannot express at all goes to `unconverted`, and the caller must not write the body then.
 */
final class PmlToSemantic
{
    private const TONE_TOKEN = ['info' => 'alert_info', 'success' => 'alert_success', 'warning' => 'alert_warning', 'danger' => 'alert_error'];

    /** @var list<string> */
    private array $notes = [];

    /** @var list<string> */
    private array $unconverted = [];

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return array{html: string, notes: list<string>, unconverted: list<string>}
     */
    public function convert(array $nodes): array
    {
        $this->notes = [];
        $this->unconverted = [];

        return ['html' => $this->blocks($nodes), 'notes' => array_values(array_unique($this->notes)), 'unconverted' => $this->unconverted];
    }

    /** @param  list<mixed>  $nodes */
    private function blocks(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $out .= $this->block($node);
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $node */
    private function block(array $node): string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $kids = is_array($node['children'] ?? null) ? array_values($node['children']) : [];

        switch ((string) ($node['primitive'] ?? '')) {
            case 'heading':
                $level = min(3, max(1, (int) ($props['level'] ?? 2)));

                return '<h'.$level.'>'.self::text((string) ($props['text'] ?? '')).'</h'.$level.'>';
            case 'p':
                return '<p>'.$this->inlines($kids).'</p>';
            case 'text':
                // A prepared-HTML variable alone at block level ({{summary_html}}) stays a paragraph of its own.
                return '<p>'.self::text((string) ($props['value'] ?? '')).'</p>';
            case 'button':
                return '<p>'.$this->button($props).'</p>';
            case 'divider':
                return '<hr>';
            case 'callout':
                return $this->callout($props, $kids);
            case 'blockquote':
                return '<blockquote>'.$this->blocks($kids).'</blockquote>';
            case 'list':
                return $this->listBlock($props, $kids);
            case 'table':
                return $this->table($kids);
            case 'image':
                return $this->image($props);
            case 'box':
                if (($code = $this->codePanel($kids)) !== null) {
                    return '<p>'.$code.'</p>';
                }
                $this->notes[] = 'A box keeps its contents, but not its background or spacing.';

                return $this->blocks($kids);
            case 'grid':
            case 'header':
            case 'footer':
                $this->notes[] = 'Columns are written one after another.';

                return $this->blocks($kids);
            case 'spacer':
                $this->notes[] = 'A spacer has no place in the HTML body and is left out.';

                return '';
            case 'pre':
                $this->notes[] = 'A preformatted block is written as code in a paragraph.';

                return '<p><code>'.self::text((string) ($props['text'] ?? '')).'</code></p>';
            case 'rich_text':
                $this->notes[] = 'A rich text block is written as plain paragraphs, headings and lists.';
                $doc = is_array($props['doc'] ?? null) ? $props['doc'] : (new MarkdownToDoc())->convert((string) ($props['markdown'] ?? ''));

                return $this->docBlocks((array) ($doc['content'] ?? []));
            default:
                $this->unconverted[] = 'The "'.($node['primitive'] ?? '?').'" element has no place in an HTML email body.';

                return '';
        }
    }

    /** @param  array<string, mixed>  $props */
    private function button(array $props): string
    {
        $label = (string) ($props['label'] ?? '');
        $href = (string) ($props['href'] ?? '');

        if (trim($label) === '{{action_label}}' && trim($href) === '{{action_url}}') {
            return '{{action_button}}';
        }

        if (str_contains($label, ' = ')) {
            $this->unconverted[] = 'A button label with " = " in it cannot be written as a button token: '.mb_substr($label, 0, 60);
        }

        return '{{button:'.self::text($label).' = '.$href.'}}';
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<mixed>  $kids
     */
    private function callout(array $props, array $kids): string
    {
        $token = self::TONE_TOKEN[(string) ($props['tone'] ?? '')] ?? null;
        $only = count($kids) === 1 && is_array($kids[0]) && ($kids[0]['primitive'] ?? '') === 'p' ? $kids[0] : null;

        if ($token !== null && $only !== null) {
            return '<p>{{'.$token.':'.$this->inlines(is_array($only['children'] ?? null) ? $only['children'] : []).'}}</p>';
        }

        if ($token !== null) {
            $this->notes[] = 'A coloured note with more than one paragraph is written as a plain note card.';
        }

        return '<aside>'.$this->blocks($kids).'</aside>';
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  list<mixed>  $kids
     */
    private function listBlock(array $props, array $kids): string
    {
        $tag = ($props['type'] ?? 'bullet') === 'numbered' ? 'ol' : 'ul';
        $items = '';

        foreach ($kids as $item) {
            if (! is_array($item)) {
                continue;
            }
            $p = is_array($item['props'] ?? null) ? $item['props'] : [];
            $title = trim((string) ($p['title'] ?? ''));
            // An item with marks or links carries a paragraph's inline nodes (0.25.0); they keep their marks and links
            // here, so the round trip semantic -> PML -> semantic holds. Words only: exactly as before.
            // After the words, at most one nested list (two levels in all), written inside the item after its paragraph.
            [$inline, $sub] = EmailBlocks::itemParts(is_array($item['children'] ?? null) ? $item['children'] : []);
            $words = $inline !== [] ? $this->inlines($inline) : self::text((string) ($p['text'] ?? ''));
            if ($inline !== [] && $title !== '') {
                $words = ltrim($words, ' ');
            }
            $under = $sub !== null ? $this->listBlock(is_array($sub['props'] ?? null) ? $sub['props'] : [], (array) ($sub['children'] ?? [])) : '';
            $items .= '<li><p>'.($title !== '' ? '<strong>'.self::text($title).'</strong> ' : '').$words.'</p>'.$under.'</li>';
        }

        return '<'.$tag.'>'.$items.'</'.$tag.'>';
    }

    /** @param  list<mixed>  $rows */
    private function table(array $rows): string
    {
        $out = '';

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $cells = '';
            foreach ((array) ($row['children'] ?? []) as $cell) {
                if (! is_array($cell)) {
                    continue;
                }
                $tag = ! empty($cell['props']['header']) ? 'th' : 'td';
                $inner = '';
                foreach ((array) ($cell['children'] ?? []) as $block) {
                    $inner .= is_array($block) && ($block['primitive'] ?? '') === 'p'
                        ? $this->inlines((array) ($block['children'] ?? []))
                        : (is_array($block) ? $this->inlines([$block]) : '');
                }
                $cells .= '<'.$tag.' colspan="1" rowspan="1"><p>'.$inner.'</p></'.$tag.'>';
            }
            $out .= '<tr>'.$cells.'</tr>';
        }

        return '<table><tbody>'.$out.'</tbody></table>';
    }

    /** @param  array<string, mixed>  $props */
    private function image(array $props): string
    {
        if (count(array_diff(array_keys($props), ['src', 'alt'])) > 0) {
            $this->notes[] = 'A picture keeps its address and description, but not its size or link.';
        }

        return '<img src="'.self::attr((string) ($props['src'] ?? '')).'" alt="'.self::attr((string) ($props['alt'] ?? '')).'">';
    }

    /** The labelled code panel SemanticToPml makes from `{{code:LABEL = VALUE}}`, or null when the box is something else. */
    private function codePanel(array $kids): ?string
    {
        if (count($kids) !== 2 || ! is_array($kids[0]) || ! is_array($kids[1]) || ($kids[1]['primitive'] ?? '') !== 'pre') {
            return null;
        }

        $small = $kids[0]['children'][0] ?? null;
        $label = is_array($small) && ($small['primitive'] ?? '') === 'small' && ($kids[0]['primitive'] ?? '') === 'p'
            ? implode('', array_map(fn ($t) => is_array($t) ? (string) ($t['props']['value'] ?? '') : '', (array) ($small['children'] ?? [])))
            : null;

        return $label === null ? null : '{{code:'.self::text($label).' = '.(string) ($kids[1]['props']['text'] ?? '').'}}';
    }

    /** @param  list<mixed>  $nodes */
    private function inlines(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $kids = is_array($node['children'] ?? null) ? array_values($node['children']) : [];

            $out .= match ((string) ($node['primitive'] ?? '')) {
                'text' => self::text((string) ($props['value'] ?? '')),
                'strong' => '<strong>'.$this->inlines($kids).'</strong>',
                'emphasis' => '<em>'.$this->inlines($kids).'</em>',
                'small' => '<small>'.$this->inlines($kids).'</small>',
                'code' => '<code>'.self::text((string) ($props['text'] ?? '')).'</code>',
                'link' => '<a href="'.self::attr((string) ($props['href'] ?? '')).'">'.$this->inlines($kids).'</a>',
                'br' => '<br>',
                'span' => $this->inlines($kids),
                'mark' => $this->badge($kids),
                default => $this->unknownInline($node),
            };
        }

        return $out;
    }

    private function badge(array $kids): string
    {
        $this->notes[] = 'A highlighted word is written as a neutral badge.';

        return '{{badge_info:'.self::text($this->plain($kids)).'}}';
    }

    private function unknownInline(array $node): string
    {
        $this->unconverted[] = 'The "'.($node['primitive'] ?? '?').'" element cannot sit inside a paragraph of an HTML email body.';

        return '';
    }

    /** @param  list<mixed>  $blocks a rich text doc's blocks */
    private function docBlocks(array $blocks): string
    {
        $out = '';

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $out .= match ((string) ($block['type'] ?? '')) {
                'heading' => (function () use ($block) {
                    $level = min(3, max(1, (int) ($block['attrs']['level'] ?? 2)));

                    return '<h'.$level.'>'.$this->docInline($block).'</h'.$level.'>';
                })(),
                'bulletList', 'orderedList' => '<'.($block['type'] === 'orderedList' ? 'ol' : 'ul').'>'
                    .implode('', array_map(fn ($li) => '<li><p>'.(is_array($li) ? $this->docWords($li) : '').'</p></li>', (array) ($block['content'] ?? [])))
                    .'</'.($block['type'] === 'orderedList' ? 'ol' : 'ul').'>',
                'codeBlock' => '<p><code>'.self::text($this->docPlain($block)).'</code></p>',
                'blockquote' => '<blockquote>'.$this->docBlocks((array) ($block['content'] ?? [])).'</blockquote>',
                default => '<p>'.$this->docInline($block).'</p>',
            };
        }

        return $out;
    }

    private function docInline(array $node): string
    {
        $out = '';

        foreach ((array) ($node['content'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }
            if (($child['type'] ?? '') === 'hardBreak') {
                $out .= '<br>';

                continue;
            }
            $html = self::text((string) ($child['text'] ?? ''));
            foreach ((array) ($child['marks'] ?? []) as $mark) {
                $html = match ((string) ($mark['type'] ?? '')) {
                    'bold', 'strong' => '<strong>'.$html.'</strong>',
                    'italic', 'em' => '<em>'.$html.'</em>',
                    'code' => '<code>'.$html.'</code>',
                    'link' => '<a href="'.self::attr((string) ($mark['attrs']['href'] ?? '')).'">'.$html.'</a>',
                    default => $html,
                };
            }
            $out .= $html;
        }

        return $out;
    }

    /** A list item's words, its paragraphs joined. */
    private function docWords(array $node): string
    {
        $parts = [];
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $parts[] = isset($child['text']) ? self::text((string) $child['text']) : $this->docInline($child);
            }
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    private function docPlain(array $node): string
    {
        $text = '';
        array_walk_recursive($node, function ($v, $k) use (&$text): void {
            if ($k === 'text' && is_string($v)) {
                $text .= $v;
            }
        });

        return $text;
    }

    /** @param  list<mixed>  $nodes */
    private function plain(array $nodes): string
    {
        $text = '';
        array_walk_recursive($nodes, function ($v, $k) use (&$text): void {
            if ($k === 'value' && is_string($v)) {
                $text .= $v;
            }
        });

        return $text;
    }

    /** Words as HTML text: `&`, `<` and `>` escaped; `{{variables}}` survive (braces are not touched). */
    private static function text(string $value): string
    {
        return htmlspecialchars($value, ENT_NOQUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    private static function attr(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
