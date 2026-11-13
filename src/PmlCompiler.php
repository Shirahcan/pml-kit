<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Blocks\InvalidBlockException;
use Shirahcan\Pml\Compliance\ComplianceResult;
use Shirahcan\Pml\Compliance\ComplianceRulePack;

/**
 * The whole-tree gate: this is where "same two gates for every author" becomes real.
 *
 * {@see compile()} walks a PML node list, expands component nodes to primitives, validates
 * every primitive against {@see PrimitiveSchema}, enforces the container-depth cap, and
 * deep-validates every rich-text doc through the block system's own walker (reused, not
 * copied). {@see scanCompliance()} then runs the deterministic compliance rules on the prose
 * of the COMPILED tree, so a prohibited claim cannot hide inside a component's expansion.
 *
 * Nothing here switches the live render or save path over; that is Stage 1's cutover. This
 * class is the validated pipeline the cutover will call.
 */
class PmlCompiler
{
    /**
     * How deep container primitives (box/grid/list) may nest. This is a STACK-SAFETY / DoS bound,
     * NOT a design limit: the compile walk here and the Next SSR render both recurse over this
     * tree, so an unbounded nest could overflow the PHP recursion limit or OOM the renderer. 16 is
     * effectively unlimited for any real marketing page or god-tier Porter layout (four was the
     * retired block builder's limit, kept low because its visual UI could not show deep nesting).
     *
     * PML owns this constant now: it used to reuse BlockSchema::MAX_CONTAINER_DEPTH (=4), a legacy
     * coupling to the deleted block system. The scanner's tag-depth cap (MAX_DEPTH=32) sits above
     * this, so 16 levels of containers are always expressible in source.
     */
    public const MAX_CONTAINER_DEPTH = 16;

    /**
     * How deep component/partial EXPANSION may chain (a partial using a partial using a partial).
     * A sibling axis to container depth: expansion does not add container nesting, but it recurses,
     * so it needs its own bound. Cycles (a -> b -> a) are caught separately and exactly; this cap
     * only stops a pathologically deep but acyclic chain. 16 matches the container cap - generous
     * for real composition, still a firm stack-safety bound.
     */
    public const MAX_EXPANSION_DEPTH = 16;

    /*
     * Which props are visible words (scanned for compliance) is declared per element in
     * resources/pml/elements.json (`"type": "prose"`), read through ElementCatalog::isProse (PML v3 L1).
     * Everything else (urls, tokens, bindings, names) is machine text a reader never sees as a claim.
     */

    public function __construct(
        private readonly PrimitiveSchema $primitives,
        private readonly BlockSchema $blocks,
        private readonly ComplianceRulePack $compliance,
        private readonly ?RichTextCompiler $richText = null,
    ) {
    }

    /** The copies being expanded right now, outermost first: each copy's `self` key nests in its parent's. */
    private array $scopes = [];

    /** Numbers a copy with no uid (a sample, a fresh Porter draft), in order, within one compile. */
    private int $copies = 0;

    /**
     * The key a component copy's `self` values live under: its uid (or its order), inside the key of the
     * copy it sits in, so a copy inside another copy is its own too.
     *
     * @param  array<string, mixed>  $node
     */
    private function scopeKey(array $node): string
    {
        $own = substr((string) preg_replace('/[^a-z0-9]/', '', strtolower((string) ($node['uid'] ?? ''))), 0, 8);
        $own = $own !== '' ? $own : 'n'.(++$this->copies);
        $parent = end($this->scopes);

        return ($parent !== false ? $parent : 'c').'_'.$own;
    }

    /**
     * Validate + expand a PML node list into a pure primitive tree.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, callable>  $components  name => fn(array $props, array $children): array
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPmlException
     */
    public function compile(array $nodes, array $components = []): array
    {
        $top = $this->stripComments($nodes);
        self::assertElseChains($top, 'nodes');
        $tree = array_map(fn ($node) => $this->compileNode($node, 0, $components, [], true), $top);

        $this->assertUniqueAnchors($tree);

        return $tree;
    }

    /**
     * Anchors become element ids (S1-02), so two on one page would make a link or the table of
     * contents jump to the wrong place. Checked on the compiled page tree, after expansion, so an
     * anchor a component or partial carries is counted too.
     *
     * @param  list<array<string, mixed>>  $tree
     *
     * @throws InvalidPmlException
     */
    private function assertUniqueAnchors(array $tree): void
    {
        $seen = [];
        $walk = function (array $nodes, string $path) use (&$walk, &$seen): void {
            foreach ($nodes as $i => $node) {
                if (! is_array($node)) {
                    continue;
                }

                $here = "{$path}[{$i}]";
                $anchor = $node['props']['anchor'] ?? null;

                if (is_string($anchor) && $anchor !== '') {
                    if (isset($seen[$anchor])) {
                        throw new InvalidPmlException(
                            "Two parts of this page use the anchor \"{$anchor}\". Give each one its own name so links land in the right place.",
                            "{$here}.props.anchor",
                        );
                    }

                    $seen[$anchor] = true;
                }

                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children'], "{$here}.children");
                }
            }
        };

        $walk($tree, 'nodes');
    }

    /**
     * Expand components/partials to a primitive tree WITHOUT validating. The pre-pass before the
     * native header/footer bodies are filled in; the filled tree then goes through the full
     * {@see compile} gate, so nothing skips validation - it just runs once, on the finished copy. The
     * expansion guards (cycle, expansion depth, container depth, caller-style merge) still apply.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, callable>  $components
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPmlException
     */
    public function expand(array $nodes, array $components = []): array
    {
        return array_map(fn ($node) => $this->compileNode($node, 0, $components, [], false), $this->stripComments($nodes));
    }

    /**
     * Drop comment nodes ({comment: text}) from a node list. Comments are non-rendering and carry no
     * primitive/component, so they must never reach validateNode/assertContainment or the render tree.
     * Dropping happens HERE (the render-tree producer), not in the parser, so the stored AST keeps its
     * comments and they still round-trip; only the compiled/rendered tree omits them.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function stripComments(array $nodes): array
    {
        return array_values(array_filter(
            $nodes,
            fn ($n) => ! (is_array($n) && PmlParser::isCommentNode($n)),
        ));
    }

    /**
     * The compliance gate: scan the prose of a COMPILED primitive tree with the deterministic
     * rules (the same rules the block system uses). Runs on the resolved tree by design.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function scanCompliance(array $tree, mixed $owner = null): ComplianceResult
    {
        $result = new ComplianceResult();

        foreach ($this->prose($tree) as [$text, $type, $field]) {
            $result = $result->merge($this->compliance->scanText($text, $type, $field));
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, callable>  $components
     * @param  list<string>  $stack  the chain of component/partial names currently being expanded
     * @return array<string, mixed>
     *
     * @throws InvalidPmlException
     */
    private function compileNode(array $node, int $containerDepth, array $components, array $stack = [], bool $validate = true, ?string $parentPrimitive = null): array
    {
        if (isset($node['component'])) {
            $name = (string) $node['component'];

            if (! isset($components[$name])) {
                throw new InvalidPmlException("Unknown component \"{$name}\".", 'component');
            }

            // A partial that expands (directly or through a chain) back to itself would recurse
            // forever. Reject the exact cycle, naming the chain so the author can see the loop.
            if (in_array($name, $stack, true)) {
                throw new InvalidPmlException(
                    'A partial cannot use itself: '.implode(' -> ', [...$stack, $name]).'.',
                    'component',
                );
            }

            if (count($stack) >= self::MAX_EXPANSION_DEPTH) {
                throw new InvalidPmlException(
                    'Partials can only be nested '.self::MAX_EXPANSION_DEPTH.' levels deep.',
                    'component',
                );
            }

            $expanded = ($components[$name])($node['props'] ?? [], $node['children'] ?? []);

            if (! is_array($expanded)) {
                throw new InvalidPmlException("Component \"{$name}\" did not expand to a node.", 'component');
            }

            // This copy's own `self` values and script names (PML v3, 2026-11-10): two copies of an
            // interactive component on one page never share a value or a function.
            $key = $this->scopeKey($node);
            $expanded = InstanceScope::apply($expanded, $key);

            // The style set on the component TAG (align, background, motion, ...) is merged onto the
            // expanded root, the caller's values winning. Without this it would be silently dropped:
            // <hero align=left motion=reveal-up> from Porter, a partial usage, or the builder rail
            // would have no effect. The merged style re-enters validateNode below like any other.
            if (isset($node['style']) && is_array($node['style']) && $node['style'] !== []) {
                $expanded['style'] = array_merge(
                    is_array($expanded['style'] ?? null) ? $expanded['style'] : [],
                    $node['style'],
                );
            }

            // What the TAG says about when and how often it shows (if / else / each / on, PML v3 L3) and
            // its dom.el key belong to the element the component becomes.
            foreach (['if', 'else_if', 'else', 'each', 'on', 'key'] as $field) {
                if (array_key_exists($field, $node)) {
                    $expanded[$field] = $node[$field];
                }
            }

            // Expanded output is untrusted like any other node: it re-enters the full gate, with
            // this name pushed on the expansion stack so a cycle through it is caught. The parent
            // carries through, so a component used inside a cell is contained like any primitive.
            $this->scopes[] = $key;

            try {
                return $this->compileNode($expanded, $containerDepth, $components, [...$stack, $name], $validate, $parentPrimitive);
            } finally {
                array_pop($this->scopes);
            }
        }

        // The expand-only pre-pass ($validate=false) skips the leaf gate so binding markers survive
        // to the resolver; the full compile after resolution runs it. Structure guards below stay on.
        if ($validate) {
            $this->primitives->validateNode($node);
            // A `self` left after expansion sits on the page itself, which has no "own" state.
            InstanceScope::assertNoSelf($node);

            // Rich text's document is made from its Markdown, here, every time (PML v3 L4): the
            // Markdown is the one authority, so a document sent with a draft is never trusted.
            if (($node['primitive'] ?? null) === 'rich_text') {
                $node['props']['doc'] = ($this->richText ?? new RichTextCompiler(new MarkdownToDoc()))
                    ->doc(is_string($node['props']['markdown'] ?? null) ? $node['props']['markdown'] : '');

                // Inside a component: its settings filled into the words, a rich-text one formatted, `self` read
                // from this copy (component props p10). The marks are removed either way.
                $node = (new RichTextFill(new MarkdownToDoc()))->finish($node);
            }

            if (isset($node['props']['doc']) && is_array($node['props']['doc'])) {
                try {
                    $this->blocks->assertSafeDocument($node['props']['doc'], 'props.doc');
                } catch (InvalidBlockException $e) {
                    throw new InvalidPmlException($e->getMessage(), $e->path);
                }
            }

            if (($node['primitive'] ?? null) === 'script') {
                $this->assertCompiledProgram($node['props']['program'] ?? null, 'props.program');
            }
        }

        $primitive = (string) ($node['primitive'] ?? '');

        // Strict parent/child nesting (table/row/cell). Only on the validating pass, where the tree
        // is pure primitives; the expand-only pre-pass leaves it to the post-resolution compile.
        if ($validate) {
            $this->primitives->assertContainment($parentPrimitive, $primitive, 'children');
        }

        $isContainer = ElementCatalog::isContainer($primitive);
        // A paragraph and its inline elements (strong, link...) hold words, not sections: they never
        // count toward how deep sections nest.
        $nextDepth = $isContainer && ! ElementCatalog::holdsInline($primitive) ? $containerDepth + 1 : $containerDepth;

        if ($isContainer && $nextDepth > self::MAX_CONTAINER_DEPTH) {
            throw new InvalidPmlException(
                'Sections can only nest '.self::MAX_CONTAINER_DEPTH.' levels deep.',
                'children',
            );
        }

        if (isset($node['children']) && is_array($node['children'])) {
            $children = $this->stripComments($node['children']);

            if ($validate) {
                self::assertElseChains($children, 'children');

                if ($primitive === 'details') {
                    self::assertSummaryFirst($children);
                }
            }

            $node['children'] = array_map(
                fn ($child) => $this->compileNode($child, $nextDepth, $components, $stack, $validate, $primitive),
                $children,
            );
        }

        return $node;
    }

    /**
     * A `details` opens with its `summary` (PML v3 L5): at most one, and first, the way the browser draws it.
     *
     * @param  list<mixed>  $children
     *
     * @throws InvalidPmlException
     */
    public static function assertSummaryFirst(array $children): void
    {
        $at = [];

        foreach (array_values($children) as $i => $child) {
            if (is_array($child) && ($child['primitive'] ?? null) === 'summary') {
                $at[] = $i;
            }
        }

        if (count($at) > 1) {
            throw new InvalidPmlException('A "details" has one "summary" (the line a visitor clicks); this one has '.count($at).'.', 'children');
        }

        if ($at !== [] && $at[0] !== 0) {
            throw new InvalidPmlException('A "details" starts with its "summary" (the line a visitor clicks); move it first.', 'children');
        }
    }

    /**
     * An `else-if` or `else` must follow an `if` (or an `else-if`) among its siblings, the way it reads:
     * <text if={...}/> <text else-if={...}/> <text else/>. A stray one would never show, silently.
     *
     * @param  list<mixed>  $siblings
     *
     * @throws InvalidPmlException
     */
    public static function assertElseChains(array $siblings, string $path): void
    {
        $open = false;

        foreach (array_values($siblings) as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            $isElse = array_key_exists('else_if', $node) || array_key_exists('else', $node);

            if ($isElse && ! $open) {
                $word = array_key_exists('else_if', $node) ? 'else-if' : 'else';

                throw new InvalidPmlException("An \"{$word}\" must come straight after an element with \"if\" (or \"else-if\").", "{$path}.{$i}");
            }

            // A chain stays open through else-if and closes at a plain element or an else.
            $open = array_key_exists('if', $node) || array_key_exists('else_if', $node);
        }
    }

    /**
     * A <script> must carry a well-formed compiled PSL program - a Program node with a body list - so
     * the browser runtime receives AST-JSON it can execute, never a raw string to parse or a shape
     * that would fault the tree-walker. This is the "validate it compiles at publish" gate.
     *
     * It deliberately does NOT re-run the analyzer (that is advisory, aid-not-enforce) and does NOT
     * reject a hand-crafted AST: the runtime is sandboxed (a step budget, no host / DOM / network), so
     * an odd-but-well-formed program can only compute a value in the visitor's own tab. It only
     * guarantees the evaluator is handed a Program.
     *
     * @throws InvalidPmlException
     */
    private function assertCompiledProgram(mixed $program, string $path): void
    {
        if (! is_array($program)
            || ($program['type'] ?? null) !== 'Program'
            || ! isset($program['body'])
            || ! is_array($program['body'])
        ) {
            throw new InvalidPmlException('A "script" must carry a compiled PSL program (a Program node).', $path);
        }
    }

    /**
     * The COMPILED tree's authored prose as one string, for Porter's advisory compliance pass
     * (which reviews the copy a reader will see and cannot be handed a tree). One line per chunk.
     * Baked module copy (package descriptions) is deliberately excluded: it is HARD-gated by the
     * deterministic rules in {@see PmlSnapshotBuilder}, not left to the advisory layer.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    public function extractProse(array $tree): string
    {
        $chunks = array_map(fn (array $p) => $p[0], $this->prose($tree));

        return implode("\n", array_filter($chunks, fn (string $t) => trim($t) !== ''));
    }

    /**
     * Pull prose from a compiled tree: a paragraph's words (whole), rich text's Markdown and every
     * prose-bearing string prop. Expressions, urls and tokens are skipped.
     *
     * @param  list<array<string, mixed>>  $tree
     * @return list<array{0: string, 1: ?string, 2: ?string}>
     */
    private function prose(array $tree): array
    {
        $out = [];

        foreach ($tree as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = isset($node['primitive']) ? (string) $node['primitive'] : null;
            $props = $node['props'] ?? [];

            // A paragraph's words are read as ONE sentence (PML v3 L4), so a claim split by an inline
            // element ("we <strong>guarantee</strong> your visa") is read whole, never as three
            // harmless pieces. Its runs are not read again one by one.
            if ($type !== null && ElementCatalog::holdsInline($type) && ! in_array($type, ElementCatalog::inline(), true)) {
                $words = trim((string) preg_replace('/\s+/u', ' ', $this->wordsOf($node['children'] ?? [])));

                if ($words !== '') {
                    $out[] = [$words, $type, 'words'];
                }

                // Its runs' `{...}` values are not in those words: read the words written inside them.
                array_push($out, ...self::literalProseDeep([$node]));

                continue;
            }

            array_push($out, ...self::literalProse($node, $type));

            if (is_array($props)) {
                foreach ($props as $key => $value) {
                    // A rich text is read as its finished words: inside a component, a setting's words are in
                    // the document, not the Markdown it was written as (component props p10).
                    if ($type === 'rich_text' && $key === 'markdown' && is_array($props['doc'] ?? null)) {
                        $value = RichTextFill::plainText($props['doc']);
                    }

                    if (is_string($value)
                        && ElementCatalog::isProse($type, (string) $key)
                        && trim($value) !== ''
                    ) {
                        $out[] = [$value, $type, (string) $key];
                    }
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                foreach ($this->prose($node['children']) as $childProse) {
                    $out[] = $childProse;
                }
            }
        }

        return $out;
    }

    /**
     * The words written INSIDE an element's `{...}` values (owner, 2026-11-11: "add the compliance scan"): a list
     * or map setting's items (an FAQ answer, a plan's name), a `{"..."}` in a sentence, what a handler or a script
     * sets. A value read from page data is not written here and is not scanned; a literal one is shown exactly as
     * written, so it is held to the same rules as any other copy. A key names its value, so `{url: "/fees"}` and
     * `{href: "..."}` are machine values, as they are in a section's settings.
     *
     * @param  array<string, mixed>  $node
     * @return list<array{0: string, 1: ?string, 2: ?string}>
     */
    private static function literalProse(array $node, ?string $type): array
    {
        $out = [];
        $read = function (mixed $ast, string $field) use (&$out, $type): void {
            if (! is_array($ast)) {
                return;
            }

            foreach (self::stringLiterals($ast, $field) as [$key, $text]) {
                if (ComplianceRulePack::looksLikeProse($key, $text)) {
                    $out[] = [$text, $type, $field];
                }
            }
        };

        foreach ((array) ($node['props'] ?? []) as $key => $value) {
            if (PrimitiveSchema::isExpression($value)) {
                $read($value['ast'] ?? null, (string) $key);
            }
        }

        foreach (['if', 'else_if', 'each'] as $field) {
            $read($node[$field]['ast'] ?? null, $field);
        }

        foreach ((array) ($node['on'] ?? []) as $event => $handler) {
            $read(is_array($handler) ? ($handler['ast'] ?? null) : null, 'on-'.$event);
        }

        if ($type === 'script') {
            $read($node['props']['program'] ?? null, 'script');
        }

        if ($type === 'rich_text' && is_array($node['props']['doc'] ?? null)) {
            $atoms = function (array $doc) use (&$atoms, $read): void {
                if (($doc['type'] ?? null) === 'expr') {
                    $read($doc['attrs']['ast'] ?? null, 'markdown');

                    return;
                }

                foreach ((array) ($doc['content'] ?? []) as $child) {
                    if (is_array($child)) {
                        $atoms($child);
                    }
                }
            };
            $atoms($node['props']['doc']);
        }

        return $out;
    }

    /**
     * {@see literalProse} for a subtree (a paragraph's runs, which the sentence read does not descend into).
     *
     * @param  list<mixed>  $nodes
     * @return list<array{0: string, 1: ?string, 2: ?string}>
     */
    private static function literalProseDeep(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            array_push($out, ...self::literalProse($node, isset($node['primitive']) ? (string) $node['primitive'] : null));
            array_push($out, ...self::literalProseDeep((array) ($node['children'] ?? [])));
        }

        return $out;
    }

    /**
     * Every string literal in a tree, with the key that names it (a map entry's key, else the prop's own name).
     *
     * @param  array<int|string, mixed>  $ast
     * @return list<array{0: string, 1: string}>
     */
    private static function stringLiterals(array $ast, string $key): array
    {
        if (($ast['type'] ?? null) === 'StringLit') {
            return [[$key, (string) ($ast['value'] ?? '')]];
        }

        if (($ast['type'] ?? null) === 'MapLit') {
            $out = [];

            foreach ((array) ($ast['entries'] ?? []) as $entry) {
                if (is_array($entry) && is_array($entry['value'] ?? null)) {
                    array_push($out, ...self::stringLiterals($entry['value'], (string) ($entry['key'] ?? $key)));
                }
            }

            return $out;
        }

        $out = [];

        foreach ($ast as $child) {
            if (is_array($child)) {
                array_push($out, ...self::stringLiterals($child, $key));
            }
        }

        return $out;
    }

    /**
     * The static words of a paragraph's content, in reading order: literal runs and inline code. A `{...}`
     * run is not static text (decision 6) and reads as a gap.
     *
     * @param  list<mixed>  $children
     */
    private function wordsOf(array $children): string
    {
        $words = '';

        foreach ($children as $child) {
            if (! is_array($child) || PmlParser::isCommentNode($child)) {
                continue;
            }

            foreach ((array) ($child['props'] ?? []) as $key => $value) {
                if (is_string($value) && ElementCatalog::isProse($child['primitive'] ?? null, (string) $key)) {
                    $words .= $value;
                } elseif (PrimitiveSchema::isExpression($value)) {
                    $words .= ' ';
                }
            }

            $words .= $this->wordsOf((array) ($child['children'] ?? []));
        }

        return $words;
    }
}
