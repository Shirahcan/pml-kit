<?php

namespace Shirahcan\Pml;


use Shirahcan\Pml\Psl\Psl;
use Shirahcan\Pml\Psl\PslException;

/**
 * Turns PML source into a flat, LOCATED list of diagnostics for the Code view - the "problems as you
 * type" pass behind the editor squiggles + Problems panel. It is READ-ONLY and does NO AI and NO
 * mutation: it parses and validates, exactly like Apply's gate, but reports EVERY problem it finds
 * (Apply is fail-first and returns one) and never throws.
 *
 * Three located sources, all 1-based absolute editor coordinates:
 *  - `syntax`  - a PML tag error ({@see PmlSyntaxException}); the scanner is fail-first so it returns
 *                alone.
 *  - `psl`     - every diagnostic from re-compiling each `<script>`'s PSL ({@see Psl::compile}),
 *                offset to absolute lines by the script's source span (B2/B3). A PSL parse fault
 *                becomes a located `error`; advisory findings are `warning`s (aid-not-enforce).
 *  - `compile` - a primitive/vocabulary error from a located walk over the SOURCE tree (B5), reusing
 *                the real {@see PrimitiveSchema} validators per node and pairing each thrown
 *                {@see InvalidPmlException} with the node's span.
 *
 * Why a bespoke walk and not the compiler (recorded in DESIGN.md section 4 + B1): the exception path
 * is a local hint, not a position, and Apply's compile runs on the EXPANDED tree, where source spans
 * no longer align. So diagnose validates the source AS AUTHORED, which keeps every squiggle exact.
 * Apply remains the one hard gate. An expression's value exists only in the browser (PML v3 L3), so
 * a name no page has is a hint here, never a block.
 */
class PmlDiagnoseService
{
    /** Context roots a running script is given, so the analyzer does not flag them. Mirrors PmlParser. */
    private const RESERVED_ROOTS = ['state'];

    /** Container primitives, for the depth check. Mirrors PmlCompiler / PrimitiveSchema. */
    private const MAX_CONTAINER_DEPTH = PmlCompiler::MAX_CONTAINER_DEPTH;

    public function __construct(
        private readonly PmlScanner $scanner,
        private readonly PmlParser $parser,
        private readonly PrimitiveSchema $primitives,
        private readonly Psl $psl,
        private readonly StandardLibrary $library,
        private readonly PmlComponentExpander $expander,
        private readonly PmlStructureAdvisor $advisor = new PmlStructureAdvisor(),
    ) {
    }

    /**
     * @param  array<string, callable>  $partials  the owner's own components (handle => expander), the same map
     *                                           the compiler and the snapshot are given; empty for std-lib only
     * @return list<array{severity: string, message: string, line: int, col: int, endCol?: int, source: string}>
     */
    public function diagnose(string $source, array $partials = []): array
    {
        // 1. Tag syntax. The scanner is fail-first (and never compiles PSL, so a script's PSL fault
        //    does not short-circuit here), so a tag error returns alone with its exact location.
        try {
            $scanned = $this->scanner->scan($source, true);
        } catch (PmlSyntaxException $e) {
            return [$this->syntaxDiagnostic($e)];
        }

        $diagnostics = [];

        // 2. PSL: recompile each <script>'s source and collect every diagnostic, offset to absolute
        //    lines by the script's span. Independent of normalization, so it always runs.
        $this->collectPslDiagnostics($scanned['nodes'], $diagnostics);

        // 3. Vocabulary/structure: normalize the tree (parseWithSpans) and validate it located. If
        //    normalization throws, it can only be a script PSL parse fault (the scan already
        //    succeeded), which step 2 has already reported located - so return what we have.
        try {
            $parsed = $this->parser->parseWithSpans($source);
        } catch (PmlSyntaxException) {
            return $this->sorted($diagnostics);
        }

        $components = $this->componentMap($partials);
        $this->scriptNames = ExpressionNames::scriptNamesIn($parsed['nodes']);
        $this->walk($parsed['nodes'], 'nodes', null, 0, $parsed['spans'], $components, $diagnostics);

        // 4. Advice (S1-03): structure + accessibility findings, located like everything else. They
        //    are warnings with their own source, so the panel can tell "will not apply" from "could
        //    be better". Never errors: aid-not-enforce.
        foreach ($this->advisor->findings($parsed['nodes']) as $finding) {
            $span = $parsed['spans']['nodes/'.implode('/', $finding['path'])] ?? null;
            $diagnostic = $this->compileDiagnostic($finding['message'], $span, null);
            $diagnostic['severity'] = 'warning';
            $diagnostic['source'] = 'advice';
            $diagnostics[] = $diagnostic;
        }

        return $this->sorted($diagnostics);
    }

    /**
     * The site's compile-time component map: its partials plus the standard library, std-lib winning
     * a name collision. Mirrors PmlSnapshotBuilder::components exactly (one source of truth for what
     * "an unknown component" means).
     *
     * @param  array<string, callable>  $partials
     * @return array<string, callable>
     */
    private function componentMap(array $partials): array
    {
        return array_merge($partials, $this->library->components($this->expander));
    }

    /**
     * A located walk mirroring PmlCompiler::compileNode's VALIDATION (not its expansion): check each
     * primitive node with the real schema, and pair a
     * thrown InvalidPmlException with the node's span. Unlike Apply it does NOT stop at the first
     * error - siblings keep being validated so the panel can list every problem at once.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, array{line: int, col: int}>  $spans
     * @param  array<string, callable>  $components
     * @param  list<array<string, mixed>>  $out
     */
    private function walk(array $nodes, string $prefix, ?string $parentPrimitive, int $containerDepth, array $spans, array $components, array &$out, array $locals = []): void
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                continue;
            }

            // A comment node ({comment: text}) is non-rendering and carries no primitive/component; it
            // is never a problem, so skip it before the primitive validation below would mis-flag it.
            if (PmlParser::isCommentNode($node)) {
                continue;
            }

            $path = "{$prefix}/{$i}";
            $span = $spans[$path] ?? null;

            // A component: its name must resolve. We do NOT expand it (its expansion has no source
            // span); its source children are slot content the
            // expander owns, not top-level primitives, so they are left to Apply.
            if (isset($node['component'])) {
                $name = (string) $node['component'];

                if (! isset($components[$name])) {
                    $out[] = $this->compileDiagnostic("Unknown component \"{$name}\".", $span, $name);
                }

                continue;
            }

            // An expression reading a name no page has (a typo like {ownr.name}) is a hint, never a block:
            // the value only exists in the browser, so it would just draw nothing there.
            $inner = isset($node['each']['item']) ? [...$locals, (string) $node['each']['item']] : $locals;
            $this->expressionHints($node, $span, $inner, $out);

            try {
                $this->primitives->validateNode($node);
            } catch (InvalidPmlException $e) {
                $out[] = $this->compileDiagnostic($e->getMessage(), $span, $node['primitive'] ?? null);

                continue; // an invalid node's children would only cascade noise
            }

            $primitive = (string) ($node['primitive'] ?? '');

            try {
                $this->primitives->assertContainment($parentPrimitive, $primitive, 'children');
            } catch (InvalidPmlException $e) {
                $out[] = $this->compileDiagnostic($e->getMessage(), $span, $primitive);
            }

            $isContainer = ElementCatalog::isContainer($primitive);
            $nextDepth = $isContainer ? $containerDepth + 1 : $containerDepth;

            if ($isContainer && $nextDepth > self::MAX_CONTAINER_DEPTH) {
                $out[] = $this->compileDiagnostic(
                    'Sections can only nest '.self::MAX_CONTAINER_DEPTH.' levels deep.',
                    $span,
                    $primitive,
                );

                continue;
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $this->walk($node['children'], "{$path}/children", $primitive, $nextDepth, $spans, $components, $out, $inner);
            }
        }
    }

    /** Names a page's own scripts define (functions, top-level values): an expression may call them. */
    private array $scriptNames = [];

    /**
     * A hint for each name an expression on this node reads that no page has (the shared check:
     * {@see ExpressionNames}, which Porter's Build gates read too).
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $locals  the `each` items in scope
     * @param  list<array<string, mixed>>  $out
     */
    private function expressionHints(array $node, ?array $span, array $locals, array &$out): void
    {
        foreach (ExpressionNames::unknownIn($node, ExpressionNames::known($locals, $this->scriptNames)) as $name) {
            $line = (int) ($span['line'] ?? 1);
            $col = (int) ($span['col'] ?? 1);
            $out[] = [
                'severity' => 'warning',
                'message' => ExpressionNames::message($name),
                'line' => $line,
                'col' => $col,
                'endCol' => $col + 1,
                'source' => 'compile',
            ];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $out
     */
    private function collectPslDiagnostics(array $nodes, array &$out): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['primitive'] ?? null) === 'script') {
                $source = $node['props']['source'] ?? null;
                $spanLine = (int) ($node['_span']['line'] ?? 1);
                // The script's code sits indented under its tag; PSL counts columns from the code's start.
                $indent = (int) ($node['_span']['col'] ?? 1) - 1;

                if (is_string($source) && trim($source) !== '') {
                    $this->pslDiagnostics($source, $spanLine, $out, $indent);
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $this->collectPslDiagnostics($node['children'], $out);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $out
     */
    private function pslDiagnostics(string $scriptSource, int $spanLine, array &$out, int $indent = 0): void
    {
        try {
            $result = $this->psl->compile($scriptSource, self::RESERVED_ROOTS);
        } catch (PslException $e) {
            // The one hard PSL failure: source that cannot form a tree. Located to the absolute line.
            $out[] = [
                'severity' => 'error',
                'message' => 'PSL: '.$this->stripPosition($e->getMessage()),
                'line' => $spanLine + ($e->atLine - 1),
                'col' => $e->atColumn + $indent,
                'source' => 'psl',
            ];

            return;
        }

        foreach ($result['diagnostics'] as $d) {
            if (! is_array($d)) {
                continue;
            }

            $out[] = [
                // Advisory findings (undefined var, wrong arity, ...) are warnings; only a genuine
                // "will fault" is an error. Aid-not-enforce: none of these block a save.
                'severity' => ($d['severity'] ?? '') === 'error' ? 'error' : 'warning',
                'message' => 'PSL: '.(string) ($d['message'] ?? 'This script has an issue.'),
                'line' => $spanLine + ((int) ($d['line'] ?? 1) - 1),
                'col' => (int) ($d['col'] ?? 1) + $indent,
                'source' => 'psl',
            ];
        }
    }

    /**
     * @param  array{line: int, col: int}|null  $span
     * @return array{severity: string, message: string, line: int, col: int, endCol: int, source: string}
     */
    private function compileDiagnostic(string $message, ?array $span, ?string $name): array
    {
        $line = (int) ($span['line'] ?? 1);
        $col = (int) ($span['col'] ?? 1);
        // Underline the opening tag ("<name"), so the squiggle sits on the offending element.
        $width = $name !== null ? strlen('<'.$name) : 1;

        return [
            'severity' => 'error',
            'message' => $message,
            'line' => $line,
            'col' => $col,
            'endCol' => $col + max(1, $width),
            'source' => 'compile',
        ];
    }

    /**
     * @return array{severity: string, message: string, line: int, col: int, source: string}
     */
    private function syntaxDiagnostic(PmlSyntaxException $e): array
    {
        return [
            'severity' => 'error',
            'message' => $this->stripPosition($e->getMessage()),
            'line' => $e->atLine,
            'col' => $e->atColumn,
            'source' => 'syntax',
        ];
    }

    /** Drop the " (line X, column Y)" the exception classes append; the line/col live in the fields. */
    private function stripPosition(string $message): string
    {
        return (string) preg_replace('/\s*\(line \d+, column \d+\)$/', '', $message);
    }

    /**
     * @param  list<array<string, mixed>>  $diagnostics
     * @return list<array<string, mixed>>
     */
    private function sorted(array $diagnostics): array
    {
        usort($diagnostics, static function (array $a, array $b): int {
            return [$a['line'], $a['col']] <=> [$b['line'], $b['col']];
        });

        return array_values($diagnostics);
    }
}
