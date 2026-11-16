<?php

namespace Shirahcan\Pml\Email;

/**
 * The plain-text part of an email (pml-email A3b): what clients that show no HTML (and some spam filters) read. Every
 * word of the design, in order; a button and a link keep their address; a table reads as "label: value".
 */
final class EmailPlainText
{
    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $header
     * @param  list<array<string, mixed>>  $footer
     * @param  array<string, scalar|null>  $values
     */
    public function text(array $nodes, array $config = [], array $header = [], array $footer = [], array $values = []): string
    {
        $chrome = ($config['chrome'] ?? 'standard') !== 'none';
        $strip = ($config['missing'] ?? 'strip') !== 'keep';
        [$nodes, $header, $footer] = [EmailValues::fillTree($nodes, $values, $strip), EmailValues::fillTree($header, $values, $strip), EmailValues::fillTree($footer, $values, $strip)];
        $parts = [...($chrome ? [$this->blocks($header)] : []), $this->blocks($nodes), ...($chrome ? [$this->blocks($footer)] : [])];
        $text = implode("\n\n", array_filter($parts, fn ($p) => trim($p) !== ''));

        // Prepared-HTML values read as their words here.
        $prepared = [];
        foreach ($values as $name => $value) {
            if ($value !== null && is_scalar($value) && EmailValues::isPrepared((string) $name)) {
                $prepared[(string) $name] = trim(html_entity_decode(strip_tags(str_replace(['</p>', '</li>', '</tr>', '<br>'], ["\n\n", "\n", "\n", "\n"], (string) $value)), ENT_QUOTES, 'UTF-8'));
            }
        }
        $text = EmailValues::fillPrepared($text, $prepared, $strip);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function blocks(array $nodes): string
    {
        return implode("\n\n", array_filter(array_map(fn (array $n) => trim($this->block($n)), $nodes), fn ($t) => $t !== ''));
    }

    private function block(array $node): string
    {
        $props = (array) ($node['props'] ?? []);
        $kids = (array) ($node['children'] ?? []);

        return match ((string) ($node['primitive'] ?? '')) {
            'heading' => (string) ($props['text'] ?? ''),
            'button' => trim((string) ($props['label'] ?? '')).': '.trim((string) ($props['href'] ?? '')),
            'image' => '',
            'divider' => '----------',
            'spacer' => '',
            'pre' => (string) ($props['text'] ?? ''),
            'list' => implode("\n", array_map(fn (array $li) => '- '.trim(((string) ($li['props']['title'] ?? '')).' '.((string) ($li['props']['text'] ?? '')).' '.$this->inline((array) ($li['children'] ?? []))), $kids)),
            'table' => implode("\n", array_map(fn (array $row) => implode(': ', array_map(fn (array $cell) => trim($this->inline((array) ($cell['children'] ?? []))), (array) ($row['children'] ?? []))), $kids)),
            'callout' => trim(((string) ($props['title'] ?? '')).("\n".$this->blocks($kids))),
            'rich_text' => $this->richText($props),
            'box', 'grid', 'header', 'footer', 'blockquote' => $this->blocks($kids),
            default => $this->inline([$node]),
        };
    }

    /** A rich text field's words; a link keeps its address, "words (https://...)", as everywhere in the text part. */
    private function richText(array $props): string
    {
        $doc = is_array($props['doc'] ?? null) ? $props['doc'] : (new \Shirahcan\Pml\MarkdownToDoc())->convert((string) ($props['markdown'] ?? ''));
        $html = (new EmailRichText(new EmailStyle(new NullBrand()), ''))->doc($doc);
        $html = (string) preg_replace('#<a href="([^"]*)"[^>]*>(.*?)</a>#s', '$2 ($1)', $html);
        $html = str_replace(['</p>', '</li>', '<br>'], ["\n\n", "\n", "\n"], $html);

        return trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));
    }

    /** @param  list<array<string, mixed>>  $nodes */
    private function inline(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $props = (array) ($node['props'] ?? []);
            $kids = (array) ($node['children'] ?? []);
            $out .= match ((string) ($node['primitive'] ?? '')) {
                'text' => isset($props['value']) ? (string) $props['value'] : $this->inline($kids),
                'code' => (string) ($props['text'] ?? ''),
                'link' => $this->inline($kids).' ('.((string) ($props['href'] ?? '')).')',
                'br' => "\n",
                default => $this->inline($kids),
            };
        }

        return html_entity_decode($out, ENT_QUOTES, 'UTF-8');
    }
}
