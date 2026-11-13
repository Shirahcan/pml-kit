<?php

namespace Shirahcan\Pml\Psl;

/**
 * The server-side entry point for PSL: compile source to an AST plus advisory diagnostics.
 *
 * This is one half of the parse-on-server / execute-on-browser split. Parsing is the ONE step that
 * can hard-fail (a {@see PslException} for source that cannot form a tree). Analysis is advisory and
 * never throws - it only adds diagnostics the builder surfaces to help the author (aid-not-enforce).
 * The AST this returns is the AST-JSON the browser evaluator consumes.
 *
 */
class Psl
{
    /**
     * @param  array<int, string>  $contextRoots  names the page supplies at runtime, so the analyzer
     *                                            does not flag them as undefined (e.g. `dependents`).
     * @return array{ast: array<string, mixed>, diagnostics: array<int, array<string, mixed>>}
     */
    public function compile(string $source, array $contextRoots = []): array
    {
        $ast = (new Parser)->parseProgram($source);
        $diagnostics = (new Analyzer)->analyze($ast, $contextRoots);

        return [
            'ast' => $ast,
            'diagnostics' => array_map(static fn (Diagnostic $d): array => $d->toArray(), $diagnostics),
        ];
    }

    /**
     * Just the advisory diagnostics (a convenience for the builder's lint pass).
     *
     * @param  array<int, string>  $contextRoots
     * @return array<int, array<string, mixed>>
     */
    public function lint(string $source, array $contextRoots = []): array
    {
        return $this->compile($source, $contextRoots)['diagnostics'];
    }
}
