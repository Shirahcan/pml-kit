<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\Parser as PslParser;
use Shirahcan\Pml\Psl\PslException;

/**
 * Rich text's drawable document, made from its Markdown when the page is drawn (PML v3 L4, owner
 * decision 4A: rich text stores the Markdown the author wrote). The Markdown is the ONE authority; the
 * document is never stored in a draft and never written back.
 *
 * A `{...}` in the words is a PSL expression, the same as anywhere else in PML: it becomes an `expr`
 * atom (its source + compiled AST) that the browser evaluates against the PSL natives. The server
 * compiles it and never runs it. `\{` writes a literal brace. Code (an inline `code` span, a fenced
 * block) is verbatim, so braces there are just characters.
 */
class RichTextCompiler
{
    public function __construct(private readonly MarkdownToDoc $markdown)
    {
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPmlException  when a `{...}` is not a PSL expression
     */
    public function doc(string $markdown): array
    {
        return $this->walk($this->markdown->convert($markdown));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function walk(array $node): array
    {
        if (($node['type'] ?? null) === 'codeBlock' || ! isset($node['content']) || ! is_array($node['content'])) {
            return $node;
        }

        $content = [];

        foreach ($node['content'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['type'] ?? null) === 'text' && ! self::isCode($child)) {
                array_push($content, ...$this->split($child));

                continue;
            }

            $content[] = $this->walk($child);
        }

        $node['content'] = $content;

        return $node;
    }

    /** @param  array<string, mixed>  $text */
    private static function isCode(array $text): bool
    {
        foreach ((array) ($text['marks'] ?? []) as $mark) {
            if (is_array($mark) && ($mark['type'] ?? null) === 'code') {
                return true;
            }
        }

        return false;
    }

    /**
     * One text node as text and `expr` atoms, each keeping the text's marks (a bold `{owner.name}` stays
     * bold).
     *
     * @param  array<string, mixed>  $text
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPmlException
     */
    private function split(array $text): array
    {
        $source = (string) ($text['text'] ?? '');

        if (! str_contains($source, '{') && ! str_contains($source, '}')) {
            return [$text];
        }

        $marks = isset($text['marks']) ? ['marks' => $text['marks']] : [];
        $out = [];
        $buf = '';
        $len = strlen($source);
        $i = 0;

        $flush = function () use (&$buf, &$out, $marks): void {
            if ($buf !== '') {
                $out[] = ['type' => 'text', 'text' => $buf, ...$marks];
                $buf = '';
            }
        };

        while ($i < $len) {
            $c = $source[$i];

            if ($c === '\\' && $i + 1 < $len && ($source[$i + 1] === '{' || $source[$i + 1] === '}')) {
                $buf .= $source[$i + 1];
                $i += 2;

                continue;
            }

            // `{{name}}` is a template variable (an email's {{recipient_name}}): literal words, never an expression.
            if ($c === '{' && ($variable = TemplateVariable::at($source, $i)) !== null) {
                $buf .= $variable;
                $i += strlen($variable);

                continue;
            }

            if ($c === '{') {
                $end = self::closing($source, $i);

                if ($end === null) {
                    throw new InvalidPmlException('A "{" in rich text is never closed: end the expression with "}", or write \\{ for a literal brace.', 'props.markdown');
                }

                $expression = trim(substr($source, $i + 1, $end - $i - 1));
                $flush();
                $out[] = ['type' => 'expr', 'attrs' => ['expr' => $expression, 'ast' => $this->compile($expression)], ...$marks];
                $i = $end + 1;

                continue;
            }

            $buf .= $c;
            $i++;
        }

        $flush();

        return $out;
    }

    /** Where the `}` that closes the `{` at $open is (a brace inside a PSL "string" does not count). */
    private static function closing(string $source, int $open): ?int
    {
        $depth = 0;
        $len = strlen($source);

        for ($i = $open; $i < $len; $i++) {
            $c = $source[$i];

            if ($c === '"') {
                for ($i++; $i < $len && $source[$i] !== '"'; $i++) {
                    if ($source[$i] === '\\') {
                        $i++;
                    }
                }

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     *
     * @throws InvalidPmlException
     */
    private function compile(string $expression): array
    {
        if ($expression === '') {
            throw new InvalidPmlException('Empty braces in rich text: write a PSL expression inside {...}, like {owner.name}, or \\{ for a literal brace.', 'props.markdown');
        }

        try {
            return (new PslParser())->parseExpression($expression);
        } catch (PslException $e) {
            throw new InvalidPmlException("The expression {{$expression}} in rich text has a PSL error: {$e->getMessage()}", 'props.markdown');
        }
    }
}
