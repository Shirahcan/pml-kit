<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\Ast;
use Shirahcan\Pml\Psl\Parser as PslParser;
use Shirahcan\Pml\Psl\PslException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\ValueKinds;

/**
 * A component's rich text, finished once its document is made (component props p10, 2026-11-11).
 *
 * The expander fills a component's attributes, but a rich text's `{...}` only become atoms when the compiler
 * makes its document from the Markdown. So the expander leaves three plain-data marks on the rich-text node and
 * this finishes the atoms with them:
 *
 *   - VALUES: each setting's value as PSL TEXT. Re-read here with PSL's own parser (p01: text is the authority,
 *     a tree is never trusted). `{props.name}` with a plain value becomes real words, so the compliance scan
 *     reads them; anything else has `props.X` filled in its tree.
 *   - KINDS: the declared kinds in scope ({@see ValueKinds}). A rich-text value is formatted: a plain one here,
 *     a value only known on the page (`{item.answer}`) is tagged `kind: rich_text` for the browser.
 *   - INSTANCE: the copy's key and script names, so `{self.count}` reads that copy's own value
 *     ({@see InstanceScope}).
 *
 * A mark a draft smuggles in can do no more than the author could by writing the `{...}` themselves.
 */
final class RichTextFill
{
    public const VALUES = '__rich_values';

    public const KINDS = '__rich_kinds';

    public const INSTANCE = '__rich_instance';

    /** @var array<string, array<string, mixed>> */
    private array $values = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $scope = [];

    public function __construct(private readonly MarkdownToDoc $markdown)
    {
    }

    /**
     * The node's document with its marks applied, and the marks removed from the node.
     *
     * @param  array<string, mixed>  $node  a rich-text node whose `props.doc` was just made
     * @return array<string, mixed>
     */
    public function finish(array $node): array
    {
        $values = is_array($node[self::VALUES] ?? null) ? $node[self::VALUES] : [];
        $scope = is_array($node[self::KINDS] ?? null) ? $node[self::KINDS] : [];
        $instance = is_array($node[self::INSTANCE] ?? null) ? $node[self::INSTANCE] : null;
        unset($node[self::VALUES], $node[self::KINDS], $node[self::INSTANCE]);

        if (($values === [] && $scope === [] && $instance === null) || ! is_array($node['props']['doc'] ?? null)) {
            return $node;
        }

        $this->values = [];
        foreach ($values as $name => $text) {
            try {
                $this->values[(string) $name] = (new PslParser())->parseExpression((string) $text);
            } catch (PslException) {
                $this->values[(string) $name] = Ast::nullLit(1, 1);
            }
        }
        $this->scope = $scope;

        $doc = $this->walk($node['props']['doc']);

        if ($instance !== null) {
            $doc = self::mapAtoms($doc, fn (array $ast) => InstanceScope::rewriteAst($ast, (string) ($instance['key'] ?? 'c'), array_values(array_map('strval', (array) ($instance['names'] ?? [])))));
        }

        $node['props']['doc'] = $doc;

        return $node;
    }

    /**
     * The words of a rich text as a reader sees them, for the compliance scan: text runs in order, a block or a
     * line break as a space, a `{...}` known only on the page as a gap.
     *
     * @param  array<string, mixed>  $doc
     */
    public static function plainText(array $doc): string
    {
        $out = '';
        $walk = static function (array $node) use (&$walk, &$out): void {
            $type = $node['type'] ?? null;

            if ($type === 'text') {
                $out .= (string) ($node['text'] ?? '');

                return;
            }

            if ($type === 'hardBreak' || $type === 'expr') {
                $out .= ' ';

                return;
            }

            foreach ((array) ($node['content'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }

            if ($type !== 'doc') {
                $out .= ' ';
            }
        };
        $walk($doc);

        return trim((string) preg_replace('/\s+/u', ' ', $out));
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function walk(array $node): array
    {
        if (($node['type'] ?? null) === 'codeBlock' || ! is_array($node['content'] ?? null)) {
            return $node;
        }

        $content = [];

        foreach ($node['content'] as $child) {
            if (! is_array($child)) {
                continue;
            }

            // A paragraph that is one rich-text value, alone: that value's own blocks (its lists, its paragraphs).
            if (($child['type'] ?? null) === 'paragraph' && count((array) ($child['content'] ?? [])) === 1 && ($child['content'][0]['type'] ?? null) === 'expr') {
                $blocks = $this->wholeBlocks($child['content'][0]);

                if ($blocks !== null) {
                    array_push($content, ...$blocks);

                    continue;
                }
            }

            if (($child['type'] ?? null) === 'expr') {
                array_push($content, ...$this->atom($child));

                continue;
            }

            $content[] = $this->walk($child);
        }

        if ($content === [] && ($node['type'] ?? null) !== 'doc') {
            unset($node['content']);
        } else {
            $node['content'] = $content;
        }

        return $node;
    }

    /**
     * A lone `{props.X}` holding plain rich text: its blocks. Null for anything else.
     *
     * @param  array<string, mixed>  $atom
     * @return list<array<string, mixed>>|null
     */
    private function wholeBlocks(array $atom): ?array
    {
        $ast = (array) ($atom['attrs']['ast'] ?? []);
        $text = $this->settingText($ast);

        if ($text === null || ! ValueKinds::isRich($ast, $this->scope) || isset($atom['marks'])) {
            return null;
        }

        return $text === '' ? [] : array_values((array) ($this->markdown->convert($text)['content'] ?? []));
    }

    /**
     * One `{...}` in a sentence, finished: words, formatted words, or an atom with its settings filled.
     *
     * @param  array<string, mixed>  $atom
     * @return list<array<string, mixed>>
     */
    private function atom(array $atom): array
    {
        $ast = (array) ($atom['attrs']['ast'] ?? []);
        $rich = ValueKinds::isRich($ast, $this->scope);
        $text = $this->settingText($ast);
        $marks = isset($atom['marks']) && is_array($atom['marks']) ? $atom['marks'] : [];

        if ($text !== null) {
            if ($text === '') {
                return [];
            }

            return $rich ? $this->inline($text, $marks) : [['type' => 'text', 'text' => $text, ...($marks !== [] ? ['marks' => $marks] : [])]];
        }

        if ($this->values !== []) {
            $atom['attrs']['ast'] = $this->fill($ast);
        }

        if ($rich) {
            $atom['attrs']['kind'] = ValueKinds::RICH;
        }

        return [$atom];
    }

    /**
     * The words of a whole `{props.X}` whose value is plain (text, a number, yes/no). Null otherwise.
     *
     * @param  array<string, mixed>  $ast
     */
    private function settingText(array $ast): ?string
    {
        if (($ast['type'] ?? null) !== 'Member' || ($ast['object']['type'] ?? null) !== 'Identifier' || ($ast['object']['name'] ?? null) !== 'props') {
            return null;
        }

        $value = $this->values[(string) ($ast['name'] ?? '')] ?? null;

        return match ($value['type'] ?? null) {
            'StringLit', 'NumberLit' => (string) $value['value'],
            'BoolLit' => $value['value'] ? 'true' : 'false',
            'NullLit' => '',
            default => null,
        };
    }

    /**
     * Rich text inside a sentence: its words with their bold, italic and links, blocks joined by a line break.
     *
     * @param  list<array<string, mixed>>  $marks  the sentence's own marks around it (a bold sentence stays bold)
     * @return list<array<string, mixed>>
     */
    private function inline(string $markdown, array $marks): array
    {
        $out = [];
        $runs = function (array $node) use (&$runs, &$out, $marks): void {
            foreach ((array) ($node['content'] ?? []) as $child) {
                if (! is_array($child)) {
                    continue;
                }

                if (in_array($child['type'] ?? null, ['text', 'hardBreak'], true)) {
                    if ($marks !== [] && ($child['type'] ?? null) === 'text') {
                        $child['marks'] = self::mergeMarks($marks, (array) ($child['marks'] ?? []));
                    }
                    $out[] = $child;

                    continue;
                }

                if ($out !== [] && in_array($child['type'] ?? null, ['paragraph', 'heading', 'codeBlock'], true)) {
                    $out[] = ['type' => 'hardBreak'];
                }

                $runs($child);
            }
        };
        $runs($this->markdown->convert($markdown));

        return $out;
    }

    /**
     * @param  list<mixed>  $outer
     * @param  list<mixed>  $own
     * @return list<mixed>
     */
    private static function mergeMarks(array $outer, array $own): array
    {
        $types = array_map(fn ($mark) => is_array($mark) ? ($mark['type'] ?? null) : null, $own);

        return [...$own, ...array_values(array_filter($outer, fn ($mark) => is_array($mark) && ! in_array($mark['type'] ?? null, $types, true)))];
    }

    /**
     * `props.X` in a tree replaced by that setting's value.
     *
     * @param  array<int|string, mixed>  $ast
     * @return array<int|string, mixed>
     */
    private function fill(array $ast): array
    {
        if (($ast['type'] ?? null) === 'Member' && ($ast['object']['type'] ?? null) === 'Identifier' && ($ast['object']['name'] ?? null) === 'props'
            && array_key_exists((string) ($ast['name'] ?? ''), $this->values)
        ) {
            return $this->values[(string) $ast['name']];
        }

        foreach ($ast as $key => $child) {
            if (is_array($child)) {
                $ast[$key] = $this->fill($child);
            }
        }

        return $ast;
    }

    /**
     * Every atom's tree, changed by $change.
     *
     * @param  array<string, mixed>  $node
     * @param  callable(array<string, mixed>): array<string, mixed>  $change
     * @return array<string, mixed>
     */
    private static function mapAtoms(array $node, callable $change): array
    {
        if (($node['type'] ?? null) === 'expr' && is_array($node['attrs']['ast'] ?? null)) {
            $node['attrs']['ast'] = $change($node['attrs']['ast']);

            return $node;
        }

        foreach ((array) ($node['content'] ?? []) as $i => $child) {
            if (is_array($child)) {
                $node['content'][$i] = self::mapAtoms($child, $change);
            }
        }

        return $node;
    }
}
