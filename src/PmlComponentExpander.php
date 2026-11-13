<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\Ast;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PrimitiveDefaultBody;
use Shirahcan\Pml\ValueKinds;

/**
 * Turns a component authored in PML source into an expander the compiler can call.
 *
 * A component (a std-library section like `hero`, or a consultant's partial) is a PML
 * template with two holes: `{param}` bindings that take the values passed to the tag, and a
 * `<slot/>` that takes the tag's children. This is the ONE mechanism behind both the standard
 * library and partials, so "everything above primitives is a component" is literally true.
 *
 * Substitution rule: a `{name}` binding whose first path segment is one of the component's
 * declared params is replaced with that prop's value; any other `{path}` (e.g. `{package.name}`)
 * is a DATA binding and is left untouched for the resolver to fill at publish. The expander
 * never validates: its output re-enters {@see PmlCompiler}, which gates it like any other node.
 */
class PmlComponentExpander
{
    public function __construct(private readonly PmlParser $parser)
    {
    }

    /**
     * Build an expander for a component authored in PML. The returned callable is the shape
     * {@see PmlCompiler}'s `$components` map expects.
     *
     * @param  list<string>  $params  the prop names this component accepts
     * @param  list<array<string, mixed>>  $settings  their declarations (kinds, a list's fields), when the component
     *                                                has them: a rich-text one is drawn formatted ({@see ValueKinds})
     * @return callable(array<string, mixed>, list<array<string, mixed>>): array<string, mixed>
     *
     * @throws InvalidPmlException
     */
    public function make(string $pml, array $params = [], array $settings = []): callable
    {
        $roots = $this->parser->parse($pml)['nodes'] ?? [];

        if (count($roots) !== 1) {
            throw new InvalidPmlException('A component template must have exactly one root element.', 'component');
        }

        $root = $roots[0];
        $slots = self::slotNames($root);
        $scope = ValueKinds::settingsScope($settings);

        return fn (array $props, array $children): array => $this->transform($root, $params, $props, $this->groups($children, $slots), $scope);
    }

    /**
     * The names of the slots a design offers ('' is the unnamed one), in the order they appear.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public static function slotNames(array $node): array
    {
        $names = [];

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (! is_array($child)) {
                continue;
            }

            if (($child['component'] ?? null) === 'slot') {
                $names[] = (string) ($child['props']['name'] ?? '');
            }

            $names = [...$names, ...self::slotNames($child)];
        }

        return array_values(array_unique($names));
    }

    /**
     * A page's children sorted into the design's slots by their `slot` (component props p04): unmarked
     * children go to the unnamed slot. A name the design does not offer is refused, naming the ones it does.
     *
     * @param  list<mixed>  $children
     * @param  list<string>  $slots
     * @return array<string, list<mixed>>
     *
     * @throws InvalidPmlException
     */
    private function groups(array $children, array $slots): array
    {
        $groups = [];

        foreach ($children as $child) {
            $name = is_array($child) && is_string($child['slot'] ?? null) ? $child['slot'] : '';

            if ($name !== '' && ! in_array($name, $slots, true)) {
                $named = array_values(array_filter($slots, fn (string $s) => $s !== ''));

                throw new InvalidPmlException(
                    "This component has no place called \"{$name}\"".($named === [] ? ', and no named places at all.' : '. It has: '.implode(', ', $named).'.'),
                    'slot',
                );
            }

            if (is_array($child)) {
                unset($child['slot']);
            }

            $groups[$name][] = $child;
        }

        return $groups;
    }

    /**
     * Fill every EMPTY self-sustaining primitive with its declared default body, so a bare tag a
     * consultant (or Porter) wrote - <header/>, <nav/>, <footer/> - becomes the full default the
     * renderer draws and the compliance scan reads.
     *
     * This is the primitive analogue of {@see make}: a default body is a PML template
     * ({@see PrimitiveDefaultBody}) filled in at build. It is deliberately GENERIC - nothing here
     * names a primitive; the pass fills whatever {@see PrimitiveDefaultBody::source} declares. An
     * instance that already carries children is a deliberate custom build and is left untouched; the
     * author's own attributes win over the default's. It recurses, so a bare <nav/> inside a filled
     * <header> (or inside a consultant's own header) is populated too.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPmlException  when a declared default body is not a single root of its own primitive
     */
    public function fillDefaultBodies(array $nodes): array
    {
        return array_map(fn ($node) => $this->fillDefaultBody($node), $nodes);
    }

    /**
     * @param  mixed  $node
     * @return mixed
     *
     * @throws InvalidPmlException
     */
    private function fillDefaultBody(mixed $node): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $primitive = $node['primitive'] ?? null;
        $source = is_string($primitive) ? PrimitiveDefaultBody::source($primitive) : null;
        $hasChildren = isset($node['children']) && is_array($node['children']) && $node['children'] !== [];

        if ($source !== null && ! $hasChildren) {
            $roots = $this->parser->parse($source)['nodes'] ?? [];

            if (count($roots) !== 1 || ($roots[0]['primitive'] ?? null) !== $primitive) {
                throw new InvalidPmlException(
                    "The default body for <{$primitive}> must be a single <{$primitive}> element.",
                    (string) $primitive,
                );
            }

            $root = $roots[0];

            // The author's attributes win over the default's: a bare <header/> takes the whole
            // default, while <header gap=lg/> keeps the default body but overrides just the gap.
            $root['props'] = array_merge(
                is_array($root['props'] ?? null) ? $root['props'] : [],
                is_array($node['props'] ?? null) ? $node['props'] : [],
            );
            $root['style'] = array_merge(
                is_array($root['style'] ?? null) ? $root['style'] : [],
                is_array($node['style'] ?? null) ? $node['style'] : [],
            );

            if ($root['props'] === []) {
                unset($root['props']);
            }
            if ($root['style'] === []) {
                unset($root['style']);
            }
            // The tag's own control fields (if / each / on) and identity stay with it.
            foreach (['if', 'else_if', 'else', 'each', 'on', 'key', 'uid'] as $field) {
                if (array_key_exists($field, $node)) {
                    $root[$field] = $node[$field];
                }
            }
            $node = $root;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(fn ($child) => $this->fillDefaultBody($child), $node['children']);
        }

        return $node;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $params
     * @param  array<string, mixed>  $props
     * @param  array<string, list<mixed>>  $slotChildren  the page's children by slot name ('' = unnamed)
     * @param  array<string, array<string, mixed>|null>  $scope  the declared kinds in scope ({@see ValueKinds})
     * @return array<string, mixed>
     */
    private function transform(array $node, array $params, array $props, array $slotChildren, array $scope = []): array
    {
        // An `each` item is in scope on the element it repeats and everything inside it: its kind is its list's row.
        if ($scope !== [] && isset($node['each']['item']) && is_array($node['each']['ast'] ?? null)) {
            $scope[(string) $node['each']['item']] = ValueKinds::row(ValueKinds::of($node['each']['ast'], $scope));
        }

        // A rich-text setting fills the whole body BEFORE the doc is compiled, so its bold, links and
        // lists survive (component props p02a). Its own braces are words, never an expression.
        if (($node['primitive'] ?? null) === 'rich_text'
            && is_string($node['props']['markdown'] ?? null)
            && preg_match(self::WHOLE_MARKDOWN, trim($node['props']['markdown']), $m) === 1
            && in_array($m[1], $params, true)
        ) {
            $node['props']['markdown'] = is_string($props[$m[1]] ?? null) ? self::bracesAsWords($props[$m[1]]) : '';
        } elseif (($node['primitive'] ?? null) === 'rich_text' && str_contains((string) ($node['props']['markdown'] ?? ''), '{')) {
            // Any other `{...}` in the words becomes an atom only when the compiler makes the document, so what it
            // needs travels with the node: each setting it reads, as PSL text, and the declared kinds in scope
            // ({@see RichTextFill}, component props p10).
            $node = $this->markRichText($node, $params, $props, $scope);
        }

        // A component reads its settings as the PSL native `props` (PML v3 L3): text={props.title}.
        // Where the page used it, each `props.X` is replaced by the value the page passed: a whole
        // `{props.X}` becomes that value (a word, a number, or the page's own expression), and a
        // `props.X` inside a larger expression has its place in the AST taken by that value. This
        // is substitution, not evaluation: nothing is computed on the server.
        foreach (['props', 'style'] as $bag) {
            if (! isset($node[$bag]) || ! is_array($node[$bag])) {
                continue;
            }

            foreach ($node[$bag] as $key => $value) {
                // A read of a rich-text setting or field (p10): the page formats it, or shows its words.
                $rich = $bag === 'props' && $scope !== [] && PrimitiveSchema::isExpression($value) && is_array($value['ast'] ?? null)
                    && ValueKinds::isRich($value['ast'], $scope)
                    && ! (($node['primitive'] ?? null) === 'rich_text' && $key === 'markdown');
                $filled = $this->fill($value, $params, $props);

                if ($filled === self::ABSENT) {
                    unset($node[$bag][$key]); // an absent optional setting leaves nothing behind
                } elseif ($rich && is_string($filled)) {
                    $node[$bag][$key] = $this->plainWords($filled); // a heading or a label shows the words, not the markup
                } elseif ($rich && PrimitiveSchema::isExpression($filled)) {
                    $node[$bag][$key] = [...$filled, 'kind' => ValueKinds::RICH];
                } else {
                    $node[$bag][$key] = $filled;
                }
            }

            if ($node[$bag] === []) {
                unset($node[$bag]);
            }
        }

        foreach (['if', 'else_if'] as $field) {
            if (isset($node[$field])) {
                $node[$field] = $this->fillExpression($node[$field], $params, $props);
            }
        }

        if (isset($node['each']) && is_array($node['each'])) {
            $node['each'] = $this->fillExpression($node['each'], $params, $props);
        }

        foreach ((array) ($node['on'] ?? []) as $event => $handler) {
            $node['on'][$event] = $this->fillExpression($handler, $params, $props);
        }

        if (isset($node['children']) && is_array($node['children'])) {
            $newChildren = [];

            foreach ($node['children'] as $child) {
                if (is_array($child) && ($child['component'] ?? null) === 'slot') {
                    $placed = $slotChildren[(string) ($child['props']['name'] ?? '')] ?? [];

                    // Nothing placed here: the slot's own content, part of the design (component props p04).
                    if ($placed === []) {
                        foreach ((array) ($child['children'] ?? []) as $fallback) {
                            $newChildren[] = is_array($fallback) ? $this->transform($fallback, $params, $props, $slotChildren, $scope) : $fallback;
                        }

                        continue;
                    }

                    // The page's own content, marked so the copy's `self` scope leaves it alone
                    // (InstanceScope removes the mark).
                    foreach ($placed as $slotChild) {
                        $newChildren[] = is_array($slotChild) ? [...$slotChild, InstanceScope::OUTER => true] : $slotChild;
                    }

                    continue;
                }

                $newChildren[] = $this->transform($child, $params, $props, $slotChildren, $scope);
            }

            if ($newChildren === []) {
                unset($node['children']);
            } else {
                $node['children'] = $newChildren;
            }
        }

        return $node;
    }

    /**
     * A rich text's marks for the compiler: the settings its words read, each as PSL text (the compiler re-reads
     * them, p01), and the declared kinds in scope when any of them is rich text.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $params
     * @param  array<string, mixed>  $props
     * @param  array<string, array<string, mixed>|null>  $scope
     * @return array<string, mixed>
     */
    private function markRichText(array $node, array $params, array $props, array $scope): array
    {
        preg_match_all('/(?<![\w.])props\.([A-Za-z_][A-Za-z0-9_]*)/', (string) $node['props']['markdown'], $reads);
        $values = [];

        foreach (array_unique($reads[1]) as $name) {
            if (in_array($name, $params, true)) {
                $values[$name] = self::pslText($props[$name] ?? null);
            }
        }

        if ($values !== []) {
            $node[RichTextFill::VALUES] = $values;
        }

        if (ValueKinds::anyRich($scope)) {
            $node[RichTextFill::KINDS] = $scope;
        }

        return $node;
    }

    /** A setting's value as the PSL text that reads back as it: words quoted, a number, a list as written. */
    private static function pslText(mixed $value): string
    {
        return match (true) {
            PrimitiveSchema::isExpression($value) => (string) $value['$expr'],
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            is_string($value) => '"'.str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', '\\n'], $value).'"',
            default => 'null',
        };
    }

    /** Rich text's words without its markup, for a heading or a label. */
    private function plainWords(string $markdown): string
    {
        return RichTextFill::plainText((new MarkdownToDoc())->convert($markdown));
    }

    /** A rich-text value's braces as words: a value is only ever Markdown, never an expression. */
    private static function bracesAsWords(string $markdown): string
    {
        // Code (`...`) keeps its braces as written: they are verbatim there already.
        $parts = preg_split('/(`+[^`]*`+)/', $markdown, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$markdown];

        return implode('', array_map(
            fn (string $part, int $i) => $i % 2 === 1 ? $part : strtr($part, ['{' => '\{', '}' => '\}']),
            $parts,
            array_keys($parts),
        ));
    }

    /** A setting the page did not pass: the prop that read it is left out. */
    private const ABSENT = "\0absent";

    /**
     * One prop value with its `props.X` filled.
     *
     * @param  list<string>  $params
     * @param  array<string, mixed>  $props
     */
    private function fill(mixed $value, array $params, array $props): mixed
    {
        if (! PrimitiveSchema::isExpression($value)) {
            return $value;
        }

        if (preg_match('/^props\.([A-Za-z_][A-Za-z0-9_]*)$/', trim((string) $value['$expr']), $m) === 1 && in_array($m[1], $params, true)) {
            return array_key_exists($m[1], $props) ? $this->whole($props[$m[1]]) : self::ABSENT;
        }

        return $this->fillExpression($value, $params, $props);
    }

    /**
     * A setting's value standing for a whole prop. Words stay words; a number or a yes/no becomes its
     * literal expression (component props p02a), so it reads as words in a heading and as itself in a
     * condition or a count, by the same rule as any other value.
     */
    private function whole(mixed $value): mixed
    {
        if (is_bool($value) || is_int($value) || is_float($value)) {
            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;

            return ['$expr' => $text, 'ast' => $this->literal($value, 1, 1)];
        }

        return $value;
    }

    /** A rich text whose Markdown is exactly `{props.X}` (a rich-text setting): its whole Markdown. */
    private const WHOLE_MARKDOWN = '/^\{\s*props\.([A-Za-z_][A-Za-z0-9_]*)\s*\}$/';

    /**
     * An expression with every `props.X` it reads replaced, in its AST, by the value the page passed.
     *
     * @param  array<string, mixed>  $expr
     * @param  list<string>  $params
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     */
    private function fillExpression(array $expr, array $params, array $props): array
    {
        if (! is_array($expr['ast'] ?? null)) {
            return $expr;
        }

        $expr['ast'] = $this->fillAst($expr['ast'], $params, $props);

        return $expr;
    }

    /**
     * @param  array<int|string, mixed>  $ast
     * @param  list<string>  $params
     * @param  array<string, mixed>  $props
     * @return array<int|string, mixed>
     */
    private function fillAst(array $ast, array $params, array $props): array
    {
        if (($ast['type'] ?? null) === 'Member'
            && ($ast['object']['type'] ?? null) === 'Identifier'
            && ($ast['object']['name'] ?? null) === 'props'
            && in_array($ast['name'] ?? null, $params, true)
        ) {
            return $this->literal($props[$ast['name']] ?? null, (int) ($ast['line'] ?? 1), (int) ($ast['col'] ?? 1));
        }

        foreach ($ast as $key => $child) {
            if (is_array($child)) {
                $ast[$key] = $this->fillAst($child, $params, $props);
            }
        }

        return $ast;
    }

    /**
     * The AST for a value the page passed: its own expression, or a literal.
     *
     * @return array<string, mixed>
     */
    private function literal(mixed $value, int $line, int $col): array
    {
        return match (true) {
            PrimitiveSchema::isExpression($value) && is_array($value['ast'] ?? null) => $value['ast'],
            is_bool($value) => Ast::boolean($value, $line, $col),
            is_int($value), is_float($value) => Ast::number((string) $value, $line, $col),
            $value === null => Ast::nullLit($line, $col),
            default => Ast::str(is_scalar($value) ? (string) $value : '', $line, $col),
        };
    }
}
