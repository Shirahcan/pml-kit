<?php

namespace Shirahcan\Pml\Psl;

use RuntimeException;

/**
 * PSL source could not be lexed or parsed.
 *
 * This is the ONE hard failure in the language: source that cannot be turned into an AST. Everything
 * a program does semantically wrong (undefined variable, wrong type, reassigning a const) is a
 * DIAGNOSTIC, not an exception - the analyzer advises, it never blocks (aid-not-enforce). So this is
 * thrown only by the lexer and parser, and it carries the location so the builder's PSL pane can
 * underline the exact spot.
 *
 * The properties are `atLine`/`atColumn`, NOT `line`/`column`: `Exception` already declares a
 * non-readonly `$line`, and PHP forbids redeclaring it as a readonly promoted property. This mirrors
 * {@see \Shirahcan\Pml\PmlSyntaxException}.
 */
class PslException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $atLine = 1,
        public readonly int $atColumn = 1,
        public readonly string $phase = 'parse',
    ) {
        parent::__construct($message." (line {$atLine}, column {$atColumn})");
    }
}
