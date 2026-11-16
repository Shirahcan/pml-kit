<?php

namespace Shirahcan\Pml;

/**
 * Serializes a PML AST back to tag + Markdown source. The inverse of {@see PmlParser}.
 *
 * This is what makes the visual builder and the PML pane two editors over one canonical AST:
 * the canvas edits the AST, the pane shows serialized PML, and back. Output is canonical
 * (deterministic attribute order, two-space indent, pure-prose text primitives rendered as
 * bare Markdown), so serialize is a fixed point after one pass and `parse(serialize(ast))`
 * returns the same AST.
 */
class PmlSerializer
{
    public function __construct(private readonly DocToMarkdown $docToMarkdown)
    {
    }

    /**
     * @param  array{imports?: list<array{name: string, from: string}>, nodes?: list<array<string, mixed>>}  $document
     */
    public function serialize(array $document): string
    {
        $importLines = [];

        foreach (($document['imports'] ?? []) as $import) {
            $importLines[] = 'import '.$import['name'].' from "'.$import['from'].'"';
        }

        $importBlock = implode("\n", $importLines);

        $nodeBlocks = [];

        foreach (($document['nodes'] ?? []) as $node) {
            $nodeBlocks[] = $this->node($node, 0);
        }

        $body = implode("\n\n", $nodeBlocks);

        if ($importBlock !== '' && $body !== '') {
            return $importBlock."\n\n".$body;
        }

        return $importBlock.$body;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function node(array $node, int $indent): string
    {
        $pad = str_repeat('  ', $indent);

        // A comment node renders back as an HTML-style comment (never a tag), closing the round-trip.
        // The `-->` guard only matters for an AST authored directly (e.g. Porter); the scanner can never
        // produce a body containing `-->` because it stops there.
        if (array_key_exists('comment', $node)) {
            // A studio comment (D1) keeps its note: a `<comment>` tag, visible in Code view like
            // every element. Its body is raw text (the scanner never tag-scans it); only a closing
            // tag typed inside the note is escaped, so it cannot end the comment early.
            if (array_key_exists('note', $node) && is_array($node['note'])) {
                $attrs = '';

                foreach (['for', 'placement', 'color', 'author', 'at', 'resolved'] as $key) {
                    if (array_key_exists($key, $node['note']) && $node['note'][$key] !== null && $node['note'][$key] !== false) {
                        $attrs .= ' '.$this->attr($key, $node['note'][$key]);
                    }
                }

                return $pad.'<comment'.$attrs.'>'.str_ireplace('</comment', '&lt;/comment', (string) $node['comment']).'</comment>';
            }

            $body = str_replace('-->', '--&gt;', (string) $node['comment']);

            return $pad.'<!--'.$body.'-->';
        }

        // Rich text (PML v3 L4): its Markdown, as written, as the raw body of a <rich-text> tag. Always
        // the tag, never bare Markdown: the tag carries the element's look, its uid and any if / each,
        // and Markdown that holds a "<" can never be mistaken for one. (Bare Markdown is still READ.)
        // The compiled `doc` is never written; the compiler makes it again from the Markdown.
        if (($node['primitive'] ?? null) === 'rich_text') {
            return $this->rawBodyNode($node, 'rich-text', 'markdown', $pad, true);
        }

        // Inline code: its words verbatim (braces and "<" are just characters in code).
        if (($node['primitive'] ?? null) === 'code') {
            return $this->rawBodyNode($node, 'code', 'text', $pad, false);
        }

        // A code block (PML v3 L5): verbatim on its own lines, indented to the tag like rich text.
        if (($node['primitive'] ?? null) === 'pre') {
            return $this->rawBodyNode($node, 'pre', 'text', $pad, true);
        }

        // A paragraph or an inline element holds words: written on one line, words and elements
        // side by side, exactly as they read.
        if (is_string($node['primitive'] ?? null) && ElementCatalog::holdsInline($node['primitive'])) {
            return $pad.$this->inlineNode($node);
        }

        // A <script> serialises its PSL source as a raw-text BODY (the inverse of the scanner); the
        // compiled `program` is never written out - the parser recompiles it from the source.
        if (($node['primitive'] ?? null) === 'script') {
            return $this->scriptNode($node, $pad);
        }

        $name = $node['primitive'] ?? $node['component'] ?? '';
        $tag = str_replace('_', '-', $name);
        $attrs = $this->attrs($node['props'] ?? [], $node['style'] ?? [], $node, $node['key'] ?? null, $node['uid'] ?? null);
        $open = $pad.'<'.$tag.($attrs !== '' ? ' '.$attrs : '');

        $children = $node['children'] ?? [];

        if ($children === []) {
            return $open.'/>';
        }

        $childBlocks = [];

        foreach ($children as $child) {
            $childBlocks[] = $this->node($child, $indent + 1);
        }

        return $open.">\n".implode("\n", $childBlocks)."\n".$pad.'</'.$tag.'>';
    }

    /**
     * A raw-body element (rich text, inline code): the body prop as the tag's content, every other prop
     * (except a compiled one) on the open tag. Rich text's Markdown sits on its own lines, indented to
     * the tag; code stays on the tag's line.
     *
     * @param  array<string, mixed>  $node
     */
    private function rawBodyNode(array $node, string $tag, string $bodyProp, string $pad, bool $block): string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $body = is_string($props[$bodyProp] ?? null) ? $props[$bodyProp] : '';
        $primitive = (string) $node['primitive'];

        unset($props[$bodyProp]);
        $props = array_filter($props, fn ($key) => ! ElementCatalog::isCompiled($primitive, (string) $key), ARRAY_FILTER_USE_KEY);
        $attrs = $this->attrs($props, $node['style'] ?? [], $node, $node['key'] ?? null, $node['uid'] ?? null);
        $open = '<'.$tag.($attrs !== '' ? ' '.$attrs : '').'>';

        if (! $block) {
            return $pad.$open.$body.'</'.$tag.'>';
        }

        return $pad.$open."\n".$this->indentBlock($body, $pad.'  ')."\n".$pad.'</'.$tag.'>';
    }

    /**
     * A paragraph or an inline element on one line: `<p>Book a call with <strong>{owner.name}</strong>.</p>`.
     * A word run is written bare (a backslash before `{`, `}`, `<` and `\` keeps them literal), a `{...}`
     * run as its expression, and a run that carries a look or a condition as its own <text> tag.
     *
     * @param  array<string, mixed>  $node
     */
    private function inlineNode(array $node): string
    {
        if (array_key_exists('comment', $node)) {
            return trim($this->node($node, 0));
        }

        $primitive = $node['primitive'] ?? null;

        if ($primitive === 'text' && $this->isBareRun($node)) {
            $value = $node['props']['value'] ?? '';

            if (is_array($value) && isset($value['$expr'])) {
                return '{'.$value['$expr'].'}';
            }

            return TemplateVariable::escapeWords((string) $value);
        }

        if (! is_string($primitive) || ! ElementCatalog::holdsInline($primitive)) {
            return trim($this->node($node, 0));
        }

        $tag = str_replace('_', '-', $primitive);
        $attrs = $this->attrs($node['props'] ?? [], $node['style'] ?? [], $node, $node['key'] ?? null, $node['uid'] ?? null);
        $inner = implode('', array_map(fn (array $child) => $this->inlineNode($child), $node['children'] ?? []));

        return '<'.$tag.($attrs !== '' ? ' '.$attrs : '').'>'.$inner.'</'.$tag.'>';
    }

    /**
     * A word run is just its words: plain `value` and nothing else that would need a tag to carry it.
     * (A run's uid is not written: words inside a paragraph are not pinned or merged one by one.)
     *
     * @param  array<string, mixed>  $node
     */
    private function isBareRun(array $node): bool
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];

        return array_keys($props) === ['value']
            && (is_string($props['value']) || (is_array($props['value']) && isset($props['value']['$expr'])))
            && empty($node['style'])
            && array_intersect_key($node, array_flip(['if', 'else_if', 'else', 'each', 'on', 'key', 'slot', 'part', 'children'])) === [];
    }

    /**
     * The <script> node: PSL source as a raw-text body, program dropped. Any other node-level
     * metadata (rare on a script) still serialises on the open tag.
     *
     * @param  array<string, mixed>  $node
     */
    private function scriptNode(array $node, string $pad): string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $source = is_string($props['source'] ?? null) ? $props['source'] : '';

        unset($props['source'], $props['program']);
        $attrs = $this->attrs($props, $node['style'] ?? [], $node, $node['key'] ?? null, $node['uid'] ?? null);
        $open = $pad.'<script'.($attrs !== '' ? ' '.$attrs : '').'>';

        // The code sits one step inside its tag, like rich text and a code block (the scanner takes that
        // indent off again), so it reads as the script's body and a nested script lines up (2026-11-11).
        return $open."\n".($source === '' ? '' : $this->indentBlock($source, $pad.'  '))."\n".$pad.'</script>';
    }

    /**
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $style
     * @param  array<string, mixed>  $control  the node, read for if / else_if / else / each / on
     */
    private function attrs(array $props, array $style, array $control = [], ?string $key = null, ?string $uid = null): string
    {
        $parts = [];

        // Attribute names are kebab-case in source (heading-size), snake_case internally
        // (heading_size), matching the tag-name convention above.
        foreach ($props as $propKey => $value) {
            $parts[] = $this->attr(str_replace('_', '-', (string) $propKey), $value);
        }

        foreach ($style as $styleKey => $value) {
            $parts[] = $this->attr(str_replace('_', '-', (string) $styleKey), $value);
        }

        // Control attributes (PML v3 L3), the exact inverse of the parser: if / else-if / else /
        // each={item in list} / on-<event>={...}.
        foreach (['if' => 'if', 'else_if' => 'else-if'] as $field => $attr) {
            if (isset($control[$field])) {
                $parts[] = $this->attr($attr, $control[$field]);
            }
        }
        if (($control['else'] ?? false) === true) {
            $parts[] = 'else';
        }
        if (isset($control['each']['item'], $control['each']['$expr'])) {
            $parts[] = 'each={'.$control['each']['item'].' in '.$control['each']['$expr'].'}';
        }
        foreach ((array) ($control['on'] ?? []) as $event => $handler) {
            $parts[] = $this->attr('on-'.str_replace('_', '-', (string) $event), $handler);
        }

        // The node-level imperative-handle key (a non-form node; a form's own `key` is a prop above).
        if ($key !== null) {
            $parts[] = $this->attr('key', $key);
        }

        // Which named slot of the component around it this element goes into (component props p04).
        if (is_string($control['slot'] ?? null)) {
            $parts[] = $this->attr('slot', $control['slot']);
        }

        // Which part of a layout this element is (post layouts A1a).
        if (is_string($control['part'] ?? null)) {
            $parts[] = $this->attr('part', $control['part']);
        }

        // The element's permanent identity (B1), last so the attributes a person reads come first.
        // A bare Markdown paragraph has no tag to carry it, and is written without one (see node()).
        if ($uid !== null) {
            $parts[] = $this->attr('uid', $uid);
        }

        return implode(' ', $parts);
    }

    /**
     * One attribute as PML v3 writes it (owner 2026-11-10): `name="value"` for every literal (a number
     * or a false is written as its text; the parser gives it back its declared type), `name={...}` for a
     * PSL expression, and a bare `name` for true, as HTML writes a yes/no attribute.
     */
    private function attr(string $name, mixed $value): string
    {
        if ($value === true) {
            return $name;
        }

        return $name.'='.$this->value($value);
    }

    private function value(mixed $value): string
    {
        if ($value === false) {
            return '"false"';
        }
        if (is_int($value) || is_float($value)) {
            return '"'.$value.'"';
        }
        if (is_array($value) && isset($value['$expr'])) {
            return '{'.$value['$expr'].'}';
        }

        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value).'"';
    }

    private function indentBlock(string $markdown, string $pad): string
    {
        if ($markdown === '') {
            return $pad;
        }

        $lines = array_map(
            static fn (string $line) => $line === '' ? '' : $pad.$line,
            explode("\n", $markdown),
        );

        return implode("\n", $lines);
    }
}
