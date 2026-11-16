<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\Parser as PslParser;
use Shirahcan\Pml\Psl\Psl;
use Shirahcan\Pml\Psl\PslException;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlSyntaxException;
use Shirahcan\Pml\RichTextCompiler;

/**
 * The full PML parser: the tag scanner and the Markdown converter, assembled.
 *
 * {@see PmlScanner} produces the tag tree with Markdown left as `{"#text": "..."}` markers;
 * this class replaces each marker with a `text` primitive carrying the parsed rich-text doc
 * (via {@see MarkdownToDoc}). It then NORMALISES each node (see normalize()): `{...}` values compile
 * with PSL's own parser (PML v3 L3: an expression over the PSL natives, evaluated only in the browser),
 * the control attributes (if / else-if / else / each / on-*) and `key` / `uid` move to node-level
 * fields, quoted values take their declared type, and a `<script>`'s source compiles to its program.
 *
 * The result is a pure primitive/component AST: no marker, no raw string, nothing the compiler and
 * PrimitiveSchema cannot already validate. This is what lets the Scale-tier Code view round-trip an
 * interactive page instead of corrupting it.
 *
 * Deferred (documented, not yet built): the Markdown link-with-variant sugar
 * `[label](href){.primary}` -> a `button` primitive, and `<slot/>` filling, both of which are
 * component-level concerns handled in Stage 1 / Stage 1b. Explicit `<button>` tags work now.
 */
class PmlParser
{
    /**
     * Context roots the page supplies to a running script, so the analyzer does not flag them as
     * undefined: the PSL natives (PML v3 L3), from the one list {@see Natives::roots()}.
    /** The events an element may answer (`on-click={...}`). Mirrors PrimitiveSchema::EVENTS. */
    public const EVENTS = ['click', 'change', 'submit', 'input'];

    /** `each={item in list}`: the item's name, then the list expression. */
    private const EACH = '/^\s*([A-Za-z_][A-Za-z0-9_]*)\s+in\s+(.+)$/s';

    private readonly Psl $psl;

    public function __construct(
        private readonly PmlScanner $scanner,
        private readonly MarkdownToDoc $markdown,
        ?Psl $psl = null,
    ) {
        // Psl is dependency-free, so a bare parser (a unit test constructing one by hand) still
        // compiles scripts; the container-resolved parser gets the shared instance.
        $this->psl = $psl ?? new Psl();
    }

    /**
     * @return array{imports: list<array{name: string, from: string, handle: string}>, nodes: list<array<string, mixed>>}
     *
     * @throws PmlSyntaxException
     */
    public function parse(string $source): array
    {
        $scanned = $this->scanner->scan($source);

        return [
            'imports' => $scanned['imports'],
            'nodes' => $this->mapNodes($scanned['nodes']),
        ];
    }

    /**
     * parse(), plus a `spans` sidecar: a map from a node's POSITIONAL path to its source `{line,
     * col}`, for the Code-view diagnostics ({@see PmlDiagnoseService}). The path convention is the
     * one a walk over the returned `nodes` reproduces exactly: `nodes/{i}` at the top, then
     * `.../children/{j}` descending - so a diagnostic on the node at a given position looks its line
     * up by rebuilding the same path.
     *
     * `nodes` are IDENTICAL to {@see parse}'s output: the scanner's `_span` is read here to build the
     * sidecar and then dropped by the canonical rebuild, so the AST the compiler and serializer see
     * is byte-unchanged (the round-trip identity holds).
     *
     * @return array{imports: list<array{name: string, from: string, handle: string}>, nodes: list<array<string, mixed>>, spans: array<string, array{line: int, col: int}>}
     *
     * @throws PmlSyntaxException
     */
    public function parseWithSpans(string $source): array
    {
        $scanned = $this->scanner->scan($source, true);

        $spans = [];
        $this->collectSpans($scanned['nodes'], 'nodes', $spans);

        return [
            'imports' => $scanned['imports'],
            'nodes' => $this->mapNodes($scanned['nodes']),
            'spans' => $spans,
        ];
    }

    /**
     * True when a node is a first-class comment node (`<!-- -->` -> `{comment: text}`). A comment
     * carries no primitive/component, so every tree-walker that validates or renders a node must skip
     * it (diagnose, compiler, binding, snapshot, renderer, canvas). One predicate keeps that consistent.
     *
     * @param  array<string, mixed>  $node
     */
    public static function isCommentNode(array $node): bool
    {
        return array_key_exists('comment', $node);
    }

    /**
     * Walk the SCANNED tree (which still carries `_span`) in the same index order the mapped tree
     * has, recording `spans["{prefix}/{i}"] = _span`. The mapped tree is positionally 1:1 with the
     * scanned tree (mapNodes emits exactly one node per scanned node, in order), so a path built over
     * the mapped nodes resolves to the right source line.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array{line: int, col: int}>  $spans
     */
    private function collectSpans(array $nodes, string $prefix, array &$spans): void
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            $path = "{$prefix}/{$i}";

            if (isset($node['_span']) && is_array($node['_span'])) {
                $spans[$path] = $node['_span'];
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $this->collectSpans($node['children'], "{$path}/children", $spans);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     *
     * @throws PmlSyntaxException
     */
    private function mapNodes(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            // A `<!-- -->` comment marker becomes a first-class, non-rendering comment node - the
            // round-trip carrier (A3 serializes it back). It has no primitive/component, so every
            // tree-walker that validates or renders MUST skip it (see isCommentNode).
            if (array_key_exists('#comment', $node)) {
                $out[] = ['comment' => (string) $node['#comment']];

                continue;
            }

            // A studio comment (D1, owner 2026-11-07): a native `<comment>` element, pinned to an
            // element by its uid. It is the SAME first-class comment node as `<!-- -->`, carrying its
            // note, so every walker that already skips comments (compiler, snapshot, renderer, canvas)
            // keeps it off the preview and the live page with no new code path to forget.
            if (($node['component'] ?? $node['primitive'] ?? null) === 'comment') {
                $out[] = $this->studioComment($node);

                continue;
            }

            // Bare Markdown between block tags is rich text, stored as the author wrote it (PML v3 L4,
            // owner decision 4A). The compiler makes its document when the page is drawn.
            if (array_key_exists('#text', $node)) {
                $out[] = [
                    'primitive' => 'rich_text',
                    'props' => ['markdown' => PmlScanner::dedent((string) $node['#text'])],
                ];

                continue;
            }

            // Words inside `p` or an inline element: a literal run, or a `{...}` run, are both plain
            // `text` (one shape for plain words everywhere).
            if (array_key_exists('#run', $node)) {
                $out[] = ['primitive' => 'text', 'props' => ['value' => (string) $node['#run']]];

                continue;
            }

            if (array_key_exists('#expr', $node)) {
                $out[] = [
                    'primitive' => 'text',
                    'props' => ['value' => $this->compiled(['$expr' => (string) $node['#expr'], '@at' => $node['@at'] ?? null])],
                ];

                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = $this->mapNodes($node['children']);
            }

            $out[] = $this->normalize($node);
        }

        return $out;
    }

    /** Where a studio comment's pin sits on its element, and its colours (the first is the default). */
    public const COMMENT_PLACEMENTS = ['top-right', 'top-left', 'bottom-right', 'bottom-left', 'left', 'right'];

    public const COMMENT_COLORS = ['yellow', 'blue', 'green', 'pink', 'purple', 'grey'];

    /** A comment is a note, not an essay. */
    public const COMMENT_MAX_CHARS = 2000;

    /**
     * `<comment for=uid placement=... color=... author="..." at="..." resolved>text</comment>` as a
     * comment node: `{comment: text, note: {...}}`. Plain text only; an unknown placement or colour
     * falls back to the default rather than failing the page, and an orphan `for` still parses.
     *
     * @param  array<string, mixed>  $node
     * @return array{comment: string, note: array<string, mixed>}
     */
    private function studioComment(array $node): array
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        // A raw-text element (the scanner reads its body verbatim into `source`); the one escape is
        // a closing tag typed inside the note, which the serializer writes as `&lt;/comment`.
        $text = str_ireplace('&lt;/comment', '</comment', trim(is_string($props['source'] ?? null) ? $props['source'] : ''));
        $string = static fn (string $key, int $max): ?string => is_string($props[$key] ?? null) && trim($props[$key]) !== ''
            ? mb_substr(trim($props[$key]), 0, $max)
            : null;

        $note = array_filter([
            'for' => $string('for', 32),
            'placement' => in_array($props['placement'] ?? null, self::COMMENT_PLACEMENTS, true) ? $props['placement'] : null,
            'color' => in_array($props['color'] ?? null, self::COMMENT_COLORS, true) ? $props['color'] : null,
            'author' => $string('author', 80),
            'at' => $string('at', 40),
            'resolved' => ($props['resolved'] ?? false) === true || ($props['resolved'] ?? null) === 'true' ? true : null,
        ], static fn ($value) => $value !== null);

        return ['comment' => mb_substr($text, 0, self::COMMENT_MAX_CHARS), 'note' => $note];
    }

    /**
     * Turn the scanner's flat attributes into the node the compiler validates (PML v3 L3):
     *  - `{...}` values compile with PSL's own parser into `{"$expr": source, "ast": ...}` (a PSL syntax
     *    fault is a located PML syntax error; PML never interprets an expression);
     *  - `if`, `else-if`, `else`, `each={item in list}` and `on-<event>={...}` move to node-level fields;
     *  - `key` (the dom.el handle) and `uid` (the permanent id) move to the node, as before;
     *  - quoted values take their declared type; a script's source compiles to its program.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     *
     * @throws PmlSyntaxException
     */
    private function normalize(array $node): array
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $control = [];
        $key = null;
        $isForm = ($node['primitive'] ?? null) === 'form';

        // `key` is a real PROP only on <form> (its submit-route slug); on every other node a `key`
        // attribute is the node-level imperative-handle id a PSL `dom.el(key)` targets. Mirrors
        // PrimitiveSchema, which accepts props.key only for a form.
        if (! $isForm && array_key_exists('key', $props) && is_string($props['key'])) {
            $key = $props['key'];
            unset($props['key']);
        }

        // `uid` is an element's PERMANENT identity (B1, 2026-11-07): written by the editor, kept by
        // Porter, never authored. It lets a change made while Porter works be merged rather than
        // overwritten, and lets a comment stay pinned to its element. Never a prop of any primitive.
        $uid = null;

        if (array_key_exists('uid', $props) && is_string($props['uid'])) {
            $uid = $props['uid'];
            unset($props['uid']);
        }

        // `slot="actions"`: which of a component's named slots this element goes into, when it sits inside
        // the component's tag on a page (component props p04, 2026-11-11). Never a prop of any element.
        $slot = null;

        if (array_key_exists('slot', $props) && is_string($props['slot'])) {
            $slot = $props['slot'];
            unset($props['slot']);
        }

        // `part="faq"`: which part of a layout this element is (post layouts A1a, 2026-11-16), so a layout can
        // lock it, repeat it or check it. Like `slot`, a node-level field, never a prop of any element.
        $part = null;

        if (array_key_exists('part', $props) && is_string($props['part'])) {
            $part = $props['part'];
            unset($props['part']);
        }

        foreach (['if', 'else_if'] as $attr) {
            if (array_key_exists($attr, $props)) {
                $control[$attr] = $this->expression($props[$attr], str_replace('_', '-', $attr));
                unset($props[$attr]);
            }
        }

        if (array_key_exists('else', $props)) {
            if ($props['else'] !== true) {
                throw new PmlSyntaxException('"else" takes no value: write it on its own, like <text value="Free" else/>.');
            }

            $control['else'] = true;
            unset($props['else']);
        }

        if (array_key_exists('each', $props)) {
            $control['each'] = $this->each($props['each']);
            unset($props['each']);
        }

        foreach (self::EVENTS as $event) {
            $attr = 'on_'.$event;

            if (array_key_exists($attr, $props)) {
                $control['on'][$event] = $this->expression($props[$attr], 'on-'.$event);
                unset($props[$attr]);
            }
        }

        // Every other `{...}` value is a PSL expression the renderer evaluates.
        foreach ($props as $prop => $value) {
            if (is_array($value) && isset($value['$expr'])) {
                $props[$prop] = $this->compiled($value);
            }
        }

        // PML v3 (owner 2026-11-10): every value arrives as quoted text; the element's definition says
        // what it is, so level="2" is stored as the number 2 and required="false" as false. A wrong
        // value stays text and the validator names what the prop takes.
        $element = $node['primitive'] ?? null;

        if (is_string($element)) {
            foreach ($props as $prop => $value) {
                // A compiled prop (rich text's `doc`) is made from its source when the page is drawn.
                if (ElementCatalog::isCompiled($element, (string) $prop)) {
                    unset($props[$prop]);

                    continue;
                }

                if (is_string($value)) {
                    $props[$prop] = ElementCatalog::coerce($element, (string) $prop, $value);
                }
            }
        }

        if (is_array($node['style'] ?? null)) {
            $style = $this->typedStyle($node['style']);

            foreach ($style as $styleKey => $value) {
                if (is_array($value) && isset($value['$expr'])) {
                    $style[$styleKey] = $this->compiled($value);
                }
            }

            $node['style'] = $style;
        }

        // Rich text's `{...}` are PSL too: a broken one is a syntax error now, in the Code view, not a
        // surprise when the page is drawn. (The document itself is made when the page is drawn.)
        if (($node['primitive'] ?? null) === 'rich_text' && is_string($props['markdown'] ?? null)) {
            try {
                (new RichTextCompiler($this->markdown))->doc($props['markdown']);
            } catch (InvalidPmlException $e) {
                throw new PmlSyntaxException($e->getMessage());
            }
        }

        // A <script>'s PSL source compiles to its `program`. Aid-not-enforce: advisory diagnostics
        // (an undefined variable, etc.) do not block a hand-author; only a PSL PARSE fault does,
        // surfaced as a located PML syntax error the Code view underlines.
        if (($node['primitive'] ?? null) === 'script'
            && isset($props['source'])
            && is_string($props['source'])
        ) {
            $props['program'] = $this->compileScript($props['source']);
        }

        if ($slot !== null) {
            $control['slot'] = $slot;
        }

        if ($part !== null) {
            $control['part'] = $part;
        }

        return $this->canonical($node, $props, $control, $key, $uid);
    }

    /**
     * A control attribute's value: it must be `{...}` (a quoted value is a word, never a condition).
     *
     * @return array{"$expr": string, ast: array<string, mixed>}
     *
     * @throws PmlSyntaxException
     */
    private function expression(mixed $value, string $attr): array
    {
        if (! is_array($value) || ! isset($value['$expr'])) {
            $example = str_starts_with($attr, 'on-') ? '{state.open = !state.open}' : '{state.open}';

            throw new PmlSyntaxException("\"{$attr}\" takes an expression in braces, like {$attr}={$example}.");
        }

        return $this->compiled($value);
    }

    /**
     * `each={item in list}`: the item's name and the compiled list expression.
     *
     * @return array{item: string, "$expr": string, ast: array<string, mixed>}
     *
     * @throws PmlSyntaxException
     */
    private function each(mixed $value): array
    {
        $source = is_array($value) && is_string($value['$expr'] ?? null) ? $value['$expr'] : null;

        if ($source === null || preg_match(self::EACH, $source, $m) !== 1) {
            throw new PmlSyntaxException('"each" names the item and the list, like each={package in owner.packages}.');
        }

        if (in_array($m[1], Natives::roots(), true)) {
            throw new PmlSyntaxException("\"{$m[1]}\" is a name the page already uses; call the item something else, like each={item in ...}.");
        }

        $list = $this->compiled(['$expr' => trim($m[2]), '@at' => $value['@at'] ?? null]);

        return ['item' => $m[1], '$expr' => $list['$expr'], 'ast' => $list['ast']];
    }

    /**
     * Compile one scanned `{...}` with PSL's parser. A PSL syntax fault is a PML syntax error at the
     * brace that holds it.
     *
     * @param  array<string, mixed>  $value  `{"$expr": source, "@at": [line, col]}`
     * @return array{"$expr": string, ast: array<string, mixed>}
     *
     * @throws PmlSyntaxException
     */
    private function compiled(array $value): array
    {
        $source = (string) $value['$expr'];
        [$line, $col] = is_array($value['@at'] ?? null) ? $value['@at'] : [1, 1];

        try {
            $ast = (new PslParser())->parseExpression($source);
        } catch (PslException $e) {
            $suffix = " (line {$e->atLine}, column {$e->atColumn})";
            $message = str_ends_with($e->getMessage(), $suffix) ? substr($e->getMessage(), 0, -strlen($suffix)) : $e->getMessage();

            throw new PmlSyntaxException("The expression {{$source}} has a PSL error: {$message}", (int) $line, (int) $col);
        }

        return ['$expr' => $source, 'ast' => $ast];
    }

    /**
     * A style's pixel paddings (and their per-screen overrides, `md:padding_top_px`) are whole numbers;
     * every other style token is a word from a closed list and stays text.
     *
     * @param  array<string, mixed>  $style
     * @return array<string, mixed>
     */
    private function typedStyle(array $style): array
    {
        foreach ($style as $key => $value) {
            if (is_string($value) && str_ends_with((string) $key, '_px') && preg_match('/^\d+$/', $value) === 1) {
                $style[$key] = (int) $value;
            }
        }

        return $style;
    }

    /**
     * Rebuild a node with a stable key order so parse(serialize(ast)) is an identity: name, then
     * props, style, if, else_if, else, each, on, key, uid, children.
     *
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $props
     * @param  array<string, mixed>  $control
     * @return array<string, mixed>
     */
    private function canonical(array $node, array $props, array $control, ?string $key, ?string $uid = null): array
    {
        $out = [];

        if (isset($node['component'])) {
            $out['component'] = $node['component'];
        }
        if (isset($node['primitive'])) {
            $out['primitive'] = $node['primitive'];
        }
        if ($props !== []) {
            $out['props'] = $props;
        }
        if (isset($node['style']) && is_array($node['style']) && $node['style'] !== []) {
            $out['style'] = $node['style'];
        }
        foreach (['if', 'else_if', 'else', 'each', 'on'] as $field) {
            if (array_key_exists($field, $control)) {
                $out[$field] = $control[$field];
            }
        }
        if ($key !== null) {
            $out['key'] = $key;
        }
        if ($uid !== null) {
            $out['uid'] = $uid;
        }
        if (is_string($control['slot'] ?? null)) {
            $out['slot'] = $control['slot'];
        }
        if (is_string($control['part'] ?? null)) {
            $out['part'] = $control['part'];
        }
        if (isset($node['children'])) {
            $out['children'] = $node['children'];
        }

        return $out;
    }

    /**
     * Compile a script's PSL source into its Program AST. A PSL parse fault becomes a located PML
     * syntax error (the ONE hard PSL failure); advisory diagnostics are ignored here by design.
     *
     * @return array<string, mixed>
     *
     * @throws PmlSyntaxException
     */
    private function compileScript(string $source): array
    {
        try {
            $result = $this->psl->compile($source, Natives::roots());
        } catch (PslException $e) {
            $suffix = " (line {$e->atLine}, column {$e->atColumn})";
            $message = str_ends_with($e->getMessage(), $suffix)
                ? substr($e->getMessage(), 0, -strlen($suffix))
                : $e->getMessage();

            throw new PmlSyntaxException("This <script> has a PSL error: {$message}", $e->atLine, $e->atColumn);
        }

        // Removed syntax (state.get/set/has) is NOT an advisory hint - the method is gone, so the call
        // would silently no-op at runtime - so it HARD-blocks here. Every PML path that compiles a
        // script (the Code-view Apply, the copilot, the PML composer) then rejects it with a located
        // reason (and the copilot/composer repair or refuse), pushing Porter + hand-authors to the dot
        // forms. Every OTHER diagnostic stays advisory (aid-not-enforce).
        foreach ($result['diagnostics'] as $d) {
            if (is_array($d) && ($d['code'] ?? null) === 'removed-member') {
                throw new PmlSyntaxException((string) ($d['message'] ?? 'This <script> uses removed syntax.'), (int) ($d['line'] ?? 1), (int) ($d['col'] ?? 1));
            }
        }

        return $result['ast'];
    }
}
