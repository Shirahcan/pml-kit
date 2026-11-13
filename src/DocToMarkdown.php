<?php

namespace Shirahcan\Pml;

/**
 * The inverse of {@see MarkdownToDoc}: renders a constrained rich-text doc AST back to the
 * Markdown that lives between PML tags. This is what lets the serializer round-trip prose, so
 * the visual builder and the PML pane stay two views of one AST.
 *
 * It handles exactly the subset MarkdownToDoc produces (headings, paragraphs, bullet/ordered
 * lists, blockquotes, and the inline marks bold/italic/link), so the pair is a faithful
 * inverse on that subset. An unrecognised node is skipped rather than guessed at.
 */
class DocToMarkdown
{
    /**
     * @param  array<string, mixed>  $doc
     */
    public function convert(array $doc): string
    {
        $blocks = [];

        foreach (($doc['content'] ?? []) as $node) {
            if (! is_array($node)) {
                continue;
            }

            $rendered = $this->block($node);

            if ($rendered !== null) {
                $blocks[] = $rendered;
            }
        }

        return implode("\n\n", $blocks);
    }

    /**
     * The doc's words as one plain line, marks and links dropped: what a reader sees, for a place
     * that holds only plain text (a contrast notice, a styled text element's `value`).
     *
     * @param  array<string, mixed>  $doc
     */
    public static function plain(array $doc): string
    {
        // Runs inside one paragraph join as written ("in [one place](...)." keeps its full stop on
        // the word); a block (paragraph, heading, list item) or a line break ends with a space.
        $out = '';
        $walk = static function (array $n) use (&$walk, &$out): void {
            if (is_string($n['text'] ?? null)) {
                $out .= $n['text'];
            }
            if (($n['type'] ?? null) === 'hardBreak') {
                $out .= ' ';
            }
            if (is_array($n['content'] ?? null)) {
                foreach ($n['content'] as $child) {
                    if (is_array($child)) {
                        $walk($child);
                    }
                }
                $out .= ' ';
            }
        };
        $walk($doc);

        return trim((string) preg_replace('/\s+/', ' ', $out));
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function block(array $node): ?string
    {
        $type = $node['type'] ?? '';

        switch ($type) {
            case 'heading':
                $level = max(1, min(4, (int) ($node['attrs']['level'] ?? 1)));

                return str_repeat('#', $level).' '.$this->inline($node['content'] ?? []);

            case 'paragraph':
                return $this->inline($node['content'] ?? []);

            case 'bulletList':
                $lines = [];
                foreach (($node['content'] ?? []) as $item) {
                    $lines[] = '- '.$this->itemInline($item);
                }

                return implode("\n", $lines);

            case 'orderedList':
                $lines = [];
                $i = 1;
                foreach (($node['content'] ?? []) as $item) {
                    $lines[] = ($i++).'. '.$this->itemInline($item);
                }

                return implode("\n", $lines);

            case 'blockquote':
                return '> '.$this->inline($node['content'][0]['content'] ?? []);

            case 'codeBlock':
                $language = is_string($node['attrs']['language'] ?? null) ? $node['attrs']['language'] : '';
                $code = implode('', array_map(
                    static fn ($t) => is_array($t) ? (string) ($t['text'] ?? '') : '',
                    $node['content'] ?? [],
                ));

                return '```'.$language."\n".$code."\n```";

            default:
                return null;
        }
    }

    /**
     * @param  array<string, mixed>  $listItem
     */
    private function itemInline(array $listItem): string
    {
        return $this->inline($listItem['content'][0]['content'] ?? []);
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function inline(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            $text = (string) ($node['text'] ?? '');
            $markTypes = array_map(
                static fn ($m) => is_array($m) ? ($m['type'] ?? '') : '',
                $node['marks'] ?? [],
            );

            if (in_array('link', $markTypes, true)) {
                $href = '';
                foreach ($node['marks'] as $m) {
                    if (($m['type'] ?? '') === 'link') {
                        $href = $m['attrs']['href'] ?? '';
                    }
                }
                $out .= '['.$text.']('.$href.')';
            } elseif (in_array('code', $markTypes, true)) {
                $out .= '`'.$text.'`';
            } elseif (in_array('bold', $markTypes, true)) {
                $out .= '**'.$text.'**';
            } elseif (in_array('italic', $markTypes, true)) {
                $out .= '*'.$text.'*';
            } else {
                $out .= $text;
            }
        }

        return $out;
    }
}
