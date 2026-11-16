<?php

namespace Shirahcan\Pml\Email;

/**
 * A rich text field's document (the editor's JSON: paragraphs, headings, lists, code blocks, words with bold, italic,
 * code and link marks) as email HTML (pml-email A3a). Anything else in the document is read for its words only.
 */
final class EmailRichText
{
    public function __construct(private readonly EmailStyle $style, private readonly string $font) {}

    /** @param  array<string, mixed>  $doc */
    public function doc(array $doc): string
    {
        return implode('', array_map(fn ($n) => is_array($n) ? $this->block($n) : '', (array) ($doc['content'] ?? [])));
    }

    /** @param  array<string, mixed>  $node */
    private function block(array $node): string
    {
        $text = 'color:'.$this->style->color('text').';'.$this->font;

        return match ((string) ($node['type'] ?? '')) {
            'paragraph' => '<p style="margin:0 0 14px;font-size:16px;line-height:1.6;'.$text.'">'.$this->inline($node).'</p>',
            'heading' => (function () use ($node, $text) {
                $level = min(EmailProfile::MAX_HEADING_LEVEL, max(1, (int) ($node['attrs']['level'] ?? 2)));

                return '<h'.$level.' style="margin:0 0 12px;font-size:'.$this->style->headingPx($level, null).'px;line-height:1.3;font-weight:700;'.$text.'">'.$this->inline($node).'</h'.$level.'>';
            })(),
            'bulletList', 'orderedList' => '<'.($node['type'] === 'orderedList' ? 'ol' : 'ul').' style="margin:0 0 14px;padding-left:22px">'
                .implode('', array_map(fn ($li) => '<li style="margin:0 0 6px;font-size:16px;line-height:1.6;'.$text.'">'.$this->words($li).'</li>', (array) ($node['content'] ?? [])))
                .'</'.($node['type'] === 'orderedList' ? 'ol' : 'ul').'>',
            'codeBlock' => '<pre style="margin:0 0 14px;padding:12px 14px;background-color:'.$this->style->color('surface').';border-radius:8px;font-family:Menlo,Consolas,monospace;font-size:14px;white-space:pre-wrap">'.EmailBlocks::h($this->plain($node)).'</pre>',
            'blockquote' => '<blockquote style="margin:0 0 14px;padding:4px 0 4px 14px;border-left:3px solid '.$this->style->color('border').';'.$text.'">'.$this->doc($node).'</blockquote>',
            default => $this->words($node) !== '' ? '<p style="margin:0 0 14px;font-size:16px;'.$text.'">'.$this->words($node).'</p>' : '',
        };
    }

    /** A block's inline content: text runs with their marks. */
    private function inline(array $node): string
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
            $html = EmailBlocks::h((string) ($child['text'] ?? ''));

            foreach ((array) ($child['marks'] ?? []) as $mark) {
                $html = match ((string) ($mark['type'] ?? '')) {
                    'bold', 'strong' => '<strong>'.$html.'</strong>',
                    'italic', 'em' => '<em>'.$html.'</em>',
                    'code' => '<code style="font-family:Menlo,Consolas,monospace;font-size:14px">'.$html.'</code>',
                    'link' => '<a href="'.EmailBlocks::h((string) ($mark['attrs']['href'] ?? '')).'" style="color:'.$this->style->color('accent').';text-decoration:underline">'.$html.'</a>',
                    default => $html,
                };
            }
            $out .= $html;
        }

        return $out;
    }

    /** A list item's (or any node's) inline words, flattening its paragraphs. */
    private function words(array $node): string
    {
        $parts = [];
        foreach ((array) ($node['content'] ?? []) as $child) {
            if (is_array($child)) {
                $parts[] = isset($child['text']) ? EmailBlocks::h((string) $child['text']) : $this->inline($child);
            }
        }

        return implode(' ', array_filter($parts, fn ($p) => $p !== ''));
    }

    private function plain(array $node): string
    {
        $text = '';
        array_walk_recursive($node, function ($v, $k) use (&$text): void {
            if ($k === 'text' && is_string($v)) {
                $text .= $v;
            }
        });

        return $text;
    }
}
