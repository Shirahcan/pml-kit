<?php

namespace Shirahcan\Pml\Psl;

/**
 * A single lexical token: its kind, the raw source text, and (for literals) the parsed value -
 * a decimal STRING for numbers, the decoded text for strings, a bool for true/false, else null.
 * `line`/`col` are 1-based and point at the token's first character, so the parser can report a
 * precise location.
 */
class Token
{
    public function __construct(
        public readonly TokenType $type,
        public readonly string $lexeme,
        public readonly mixed $value,
        public readonly int $line,
        public readonly int $col,
    ) {
    }
}
