<?php

namespace Shirahcan\Pml;

use RuntimeException;

/**
 * PML source could not be parsed.
 *
 * Carries the location so the builder's PML pane can underline the exact spot rather than
 * saying "invalid syntax". Distinct from {@see InvalidPmlException}, which is a semantic
 * (schema) failure on an already-parsed tree.
 *
 * The properties are `atLine`/`atColumn`, NOT `line`/`column`: `Exception` already declares a
 * non-readonly `$line`, and PHP forbids redeclaring it as a readonly promoted property.
 */
class PmlSyntaxException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $atLine = 1,
        public readonly int $atColumn = 1,
    ) {
        parent::__construct($message." (line {$atLine}, column {$atColumn})");
    }
}
