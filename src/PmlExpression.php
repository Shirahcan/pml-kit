<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\Parser;

/**
 * A `{...}` value as PML stores it (PML v3 L3): the PSL source and the AST PSL's own parser compiles
 * from it. The browser evaluates it; the server only ever builds or reads this shape.
 */
final class PmlExpression
{
    /**
     * @return array{"$expr": string, ast: array<string, mixed>}
     *
     * @throws \Shirahcan\Pml\Psl\PslException when the source is not one PSL expression
     */
    public static function of(string $source): array
    {
        $source = trim($source);

        return ['$expr' => $source, 'ast' => (new Parser())->parseExpression($source)];
    }
}
