<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Blocks\BlockSchema;

/**
 * Converts the Markdown that lives between PML tags into the constrained rich-text doc AST
 * (the same Tiptap-shaped tree the block system already validates and renders).
 *
 * The supported subset is deliberately small and maps ONE-TO-ONE onto the allowlisted nodes
 * and marks in {@see \Shirahcan\Pml\Blocks\BlockSchema}: headings, paragraphs, bullet and
 * ordered lists, blockquotes, and the inline marks bold / italic / link. Anything else is
 * treated as plain text, never as raw markup: this converter cannot emit a node type the
 * document walker would reject, so it stays inside the security boundary by construction.
 */
class MarkdownToDoc
{
    /**
     * @return array<string, mixed> a `{ type: "doc", content: [...] }` tree
     */
    public function convert(string $markdown): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $n = count($lines);
        $content = [];
        $para = [];
        $i = 0;

        $flushPara = function () use (&$para, &$content): void {
            $text = trim(implode(' ', $para));

            if ($text !== '') {
                $content[] = ['type' => 'paragraph', 'content' => $this->inline($text)];
            }

            $para = [];
        };

        while ($i < $n) {
            $trim = trim($lines[$i]);

            // A fenced code block (S1-05): ``` [language] ... ```. Its lines are kept VERBATIM
            // (indentation, blank lines, Markdown-looking text), up to the closing fence or the end.
            if (preg_match('/^```\s*([A-Za-z0-9_+-]*)\s*$/', $trim, $fence) === 1) {
                $flushPara();
                $indent = strlen($lines[$i]) - strlen(ltrim($lines[$i]));
                [$block, $i] = $this->readFence($lines, $i + 1, strtolower($fence[1]), $indent);
                $content[] = $block;

                continue;
            }

            if ($trim === '') {
                $flushPara();
                $i++;

                continue;
            }

            if (preg_match('/^(#{1,4})\s+(.*)$/', $trim, $m) === 1) {
                $flushPara();
                $content[] = [
                    'type' => 'heading',
                    'attrs' => ['level' => strlen($m[1])],
                    'content' => $this->inline(trim($m[2])),
                ];
                $i++;

                continue;
            }

            if (preg_match('/^[-*]\s+/', $trim) === 1) {
                $flushPara();
                [$list, $i] = $this->readList($lines, $i, 'bullet');
                $content[] = $list;

                continue;
            }

            if (preg_match('/^\d+\.\s+/', $trim) === 1) {
                $flushPara();
                [$list, $i] = $this->readList($lines, $i, 'ordered');
                $content[] = $list;

                continue;
            }

            if (preg_match('/^>\s?(.*)$/', $trim) === 1) {
                $flushPara();
                [$quote, $i] = $this->readBlockquote($lines, $i);
                $content[] = $quote;

                continue;
            }

            $para[] = $trim;
            $i++;
        }

        $flushPara();

        if ($content === []) {
            $content[] = ['type' => 'paragraph', 'content' => []];
        }

        return ['type' => 'doc', 'content' => $content];
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function readList(array $lines, int $i, string $kind): array
    {
        $n = count($lines);
        $pattern = $kind === 'bullet' ? '/^[-*]\s+(.*)$/' : '/^\d+\.\s+(.*)$/';
        $items = [];

        while ($i < $n) {
            $trim = trim($lines[$i]);

            if ($trim === '' || preg_match($pattern, $trim, $m) !== 1) {
                break;
            }

            $items[] = [
                'type' => 'listItem',
                'content' => [['type' => 'paragraph', 'content' => $this->inline(trim($m[1]))]],
            ];
            $i++;
        }

        $type = $kind === 'bullet' ? 'bulletList' : 'orderedList';

        return [['type' => $type, 'content' => $items], $i];
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function readFence(array $lines, int $i, string $language, int $indent = 0): array
    {
        $n = count($lines);
        $body = [];

        while ($i < $n && trim($lines[$i]) !== '```') {
            // The fence's own indentation (a fence nested in a PML container is indented by the
            // serializer) is not part of the code: strip up to that much from each line (CommonMark).
            $line = $lines[$i];
            $strip = min($indent, strlen($line) - strlen(ltrim($line, " \t")));
            $body[] = substr($line, $strip);
            $i++;
        }

        $text = implode("\n", $body);
        // An unknown language is not an error in prose: the block just carries no label.
        $known = in_array($language, BlockSchema::codeLanguages(), true) ? $language : null;

        return [
            [
                'type' => 'codeBlock',
                'attrs' => ['language' => $known],
                'content' => $text === '' ? [] : [['type' => 'text', 'text' => $text]],
            ],
            min($n, $i + 1),
        ];
    }

    /**
     * @param  list<string>  $lines
     * @return array{0: array<string, mixed>, 1: int}
     */
    private function readBlockquote(array $lines, int $i): array
    {
        $n = count($lines);
        $quoted = [];

        while ($i < $n && preg_match('/^>\s?(.*)$/', trim($lines[$i]), $m) === 1) {
            $quoted[] = $m[1];
            $i++;
        }

        $quote = [
            'type' => 'blockquote',
            'content' => [['type' => 'paragraph', 'content' => $this->inline(trim(implode(' ', $quoted)))]],
        ];

        return [$quote, $i];
    }

    /**
     * Inline marks: **bold**, *italic* / _italic_, and [label](href). Everything else is text.
     *
     * @return list<array<string, mixed>>
     */
    private function inline(string $text): array
    {
        $nodes = [];
        $len = strlen($text);
        $i = 0;
        $buf = '';

        $flush = function () use (&$buf, &$nodes): void {
            if ($buf !== '') {
                $nodes[] = ['type' => 'text', 'text' => $buf];
                $buf = '';
            }
        };

        while ($i < $len) {
            // `inline code` (S1-05): taken literally, no other marks parsed inside it.
            if ($text[$i] === '`') {
                $close = strpos($text, '`', $i + 1);

                if ($close !== false && $close > $i + 1) {
                    $flush();
                    $nodes[] = [
                        'type' => 'text',
                        'text' => substr($text, $i + 1, $close - ($i + 1)),
                        'marks' => [['type' => 'code']],
                    ];
                    $i = $close + 1;

                    continue;
                }
            }

            // [label](href)
            if ($text[$i] === '[') {
                $rb = strpos($text, ']', $i + 1);

                if ($rb !== false && ($rb + 1) < $len && $text[$rb + 1] === '(') {
                    $rp = strpos($text, ')', $rb + 2);

                    if ($rp !== false) {
                        $flush();
                        $label = substr($text, $i + 1, $rb - ($i + 1));
                        $href = substr($text, $rb + 2, $rp - ($rb + 2));
                        $nodes[] = [
                            'type' => 'text',
                            'text' => $label !== '' ? $label : $href,
                            'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]],
                        ];
                        $i = $rp + 1;

                        continue;
                    }
                }
            }

            // **bold**
            if (substr($text, $i, 2) === '**') {
                $close = strpos($text, '**', $i + 2);

                if ($close !== false) {
                    $flush();
                    $nodes[] = [
                        'type' => 'text',
                        'text' => substr($text, $i + 2, $close - ($i + 2)),
                        'marks' => [['type' => 'bold']],
                    ];
                    $i = $close + 2;

                    continue;
                }
            }

            // *italic* or _italic_
            if ($text[$i] === '*' || $text[$i] === '_') {
                $delim = $text[$i];
                $close = strpos($text, $delim, $i + 1);

                if ($close !== false && $close > $i + 1) {
                    $flush();
                    $nodes[] = [
                        'type' => 'text',
                        'text' => substr($text, $i + 1, $close - ($i + 1)),
                        'marks' => [['type' => 'italic']],
                    ];
                    $i = $close + 1;

                    continue;
                }
            }

            $buf .= $text[$i];
            $i++;
        }

        $flush();

        if ($nodes === []) {
            $nodes[] = ['type' => 'text', 'text' => ''];
        }

        return $nodes;
    }
}
