<?php

namespace Shirahcan\Pml\Psl;

/**
 * Turns a token stream into an AST (arrays; see {@see Ast}). A hand-written precedence-climbing
 * parser - one method per precedence level - which is easy to read and to extend. This file is the
 * expression half (Phase 02); statement parsing + `parseProgram` are added in Phase 03.
 *
 * The parser only builds the tree. It never evaluates and never type-checks: an undefined variable
 * or a const reassignment is a job for the advisory {@see Analyzer}, not a parse error. The ONE
 * thing that fails here is source that cannot form a tree - a {@see PslException} with a position.
 */
class Parser
{
    use ParsesStatements;

    /** @var Token[] */
    private array $tokens = [];

    private int $pos = 0;

    /** Left-associative binary levels, tightest last. Keyed by TokenType->value. */
    private const OR_OPS = ['OR' => '||'];

    private const AND_OPS = ['AND' => '&&'];

    private const EQUALITY_OPS = ['EQ' => '==', 'NEQ' => '!='];

    private const COMPARISON_OPS = ['LT' => '<', 'LTE' => '<=', 'GT' => '>', 'GTE' => '>='];

    private const TERM_OPS = ['PLUS' => '+', 'MINUS' => '-'];

    private const FACTOR_OPS = ['STAR' => '*', 'SLASH' => '/', 'PERCENT' => '%'];

    /**
     * Parse a single expression (used by tests + wherever a bare expression is wanted). Rejects
     * trailing input so `1 2` is an error, not a silent `1`.
     *
     * @return array<string, mixed>
     */
    public function parseExpression(string $source): array
    {
        $this->tokens = (new Lexer)->tokenize($source);
        $this->pos = 0;

        $expr = $this->expression();
        $this->expect(TokenType::Eof, 'Unexpected extra input after the expression.');

        return $expr;
    }

    /** @return array<string, mixed> */
    private function expression(): array
    {
        return $this->assignment();
    }

    /** @return array<string, mixed> */
    private function assignment(): array
    {
        $left = $this->ternary();

        $op = match ($this->peek()->type) {
            TokenType::Assign => '=',
            TokenType::PlusAssign => '+=',
            TokenType::MinusAssign => '-=',
            TokenType::StarAssign => '*=',
            TokenType::SlashAssign => '/=',
            default => null,
        };

        if ($op === null) {
            return $left;
        }

        $t = $this->advance();

        if (! in_array($left['type'], ['Identifier', 'Member', 'Index'], true)) {
            throw new PslException('The left side of an assignment must be a variable, member, or index.', $t->line, $t->col);
        }

        $value = $this->assignment(); // right-associative

        return Ast::assign($op, $left, $value, $left['line'], $left['col']);
    }

    /** @return array<string, mixed> */
    private function ternary(): array
    {
        $cond = $this->binaryLeft('logicalAnd', self::OR_OPS);

        if ($this->peek()->type !== TokenType::Question) {
            return $cond;
        }

        $this->advance();
        $then = $this->assignment();
        $this->expect(TokenType::Colon, 'Expected ":" in a conditional expression.');
        $else = $this->assignment();

        return Ast::ternary($cond, $then, $else, $cond['line'], $cond['col']);
    }

    /**
     * A left-associative binary level: parse the tighter level, then fold `<op> tighter` while the
     * next token is one of $ops.
     *
     * @param  array<string, string>  $ops  TokenType->value => operator string
     * @return array<string, mixed>
     */
    private function binaryLeft(string $tighter, array $ops): array
    {
        $left = $this->$tighter();

        while (($op = $ops[$this->peek()->type->value] ?? null) !== null) {
            $this->advance();
            $right = $this->$tighter();
            $left = Ast::binary($op, $left, $right, $left['line'], $left['col']);
        }

        return $left;
    }

    /** @return array<string, mixed> */
    private function logicalAnd(): array
    {
        return $this->binaryLeft('equality', self::AND_OPS);
    }

    /** @return array<string, mixed> */
    private function equality(): array
    {
        return $this->binaryLeft('comparison', self::EQUALITY_OPS);
    }

    /** @return array<string, mixed> */
    private function comparison(): array
    {
        return $this->binaryLeft('term', self::COMPARISON_OPS);
    }

    /** @return array<string, mixed> */
    private function term(): array
    {
        return $this->binaryLeft('factor', self::TERM_OPS);
    }

    /** @return array<string, mixed> */
    private function factor(): array
    {
        return $this->binaryLeft('unary', self::FACTOR_OPS);
    }

    /** @return array<string, mixed> */
    private function unary(): array
    {
        $t = $this->peek();

        if ($t->type === TokenType::Bang || $t->type === TokenType::Minus) {
            $this->advance();
            $operand = $this->unary();

            return Ast::unary($t->type === TokenType::Bang ? '!' : '-', $operand, $t->line, $t->col);
        }

        if ($t->type === TokenType::PlusPlus || $t->type === TokenType::MinusMinus) {
            $this->advance();
            $operand = $this->unary();
            $this->assertUpdatable($operand, $t);

            return Ast::update($t->type === TokenType::PlusPlus ? '++' : '--', $operand, true, $t->line, $t->col);
        }

        return $this->postfix();
    }

    /** @return array<string, mixed> */
    private function postfix(): array
    {
        $expr = $this->primary();

        while (true) {
            $t = $this->peek();

            if ($t->type === TokenType::LParen) {
                $this->advance();
                $args = $this->argumentList();
                $this->expect(TokenType::RParen, 'Expected ")" after the arguments.');
                $expr = Ast::call($expr, $args, $expr['line'], $expr['col']);
            } elseif ($t->type === TokenType::Dot) {
                $this->advance();
                $name = $this->expect(TokenType::Ident, 'Expected a property name after ".".');
                $expr = Ast::member($expr, $name->lexeme, $expr['line'], $expr['col']);
            } elseif ($t->type === TokenType::LBracket) {
                $this->advance();
                $index = $this->expression();
                $this->expect(TokenType::RBracket, 'Expected "]" after the index.');
                $expr = Ast::index($expr, $index, $expr['line'], $expr['col']);
            } else {
                break;
            }
        }

        // A postfix ++ / -- binds to the whole postfix chain (i++, obj.count++, xs[0]++).
        $t = $this->peek();
        if ($t->type === TokenType::PlusPlus || $t->type === TokenType::MinusMinus) {
            $this->advance();
            $this->assertUpdatable($expr, $t);
            $expr = Ast::update($t->type === TokenType::PlusPlus ? '++' : '--', $expr, false, $expr['line'], $expr['col']);
        }

        return $expr;
    }

    /**
     * The operand of ++/-- must be an assignable l-value, same as an assignment target.
     *
     * @param  array<string, mixed>  $node
     */
    private function assertUpdatable(array $node, Token $t): void
    {
        if (! in_array($node['type'], ['Identifier', 'Member', 'Index'], true)) {
            throw new PslException('The target of "++"/"--" must be a variable, member, or index.', $t->line, $t->col);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function argumentList(): array
    {
        $args = [];

        if ($this->peek()->type === TokenType::RParen) {
            return $args;
        }

        $args[] = $this->expression();

        while ($this->peek()->type === TokenType::Comma) {
            $this->advance();
            $args[] = $this->expression();
        }

        return $args;
    }

    /** @return array<string, mixed> */
    private function primary(): array
    {
        $t = $this->peek();

        switch ($t->type) {
            case TokenType::Number:
                $this->advance();

                return Ast::number($t->value, $t->line, $t->col);

            case TokenType::Str:
                $this->advance();

                return Ast::str($t->value, $t->line, $t->col);

            case TokenType::BoolTrue:
                $this->advance();

                return Ast::boolean(true, $t->line, $t->col);

            case TokenType::BoolFalse:
                $this->advance();

                return Ast::boolean(false, $t->line, $t->col);

            case TokenType::Nil:
                $this->advance();

                return Ast::nullLit($t->line, $t->col);

            case TokenType::Ident:
                $this->advance();

                return Ast::identifier($t->lexeme, $t->line, $t->col);

            case TokenType::Function:
                return $this->functionExpression();

            case TokenType::LBracket:
                return $this->listLiteral();

            case TokenType::LBrace:
                return $this->mapLiteral();

            case TokenType::LParen:
                $this->advance();
                $expr = $this->expression();
                $this->expect(TokenType::RParen, 'Expected ")" to close the group.');

                return $expr;

            default:
                if ($t->type === TokenType::Eof) {
                    throw new PslException('Unexpected end of input.', $t->line, $t->col);
                }

                throw new PslException("Unexpected \"{$t->lexeme}\".", $t->line, $t->col);
        }
    }

    /** @return array<string, mixed> */
    private function listLiteral(): array
    {
        $open = $this->advance(); // [
        $elements = [];

        if ($this->peek()->type !== TokenType::RBracket) {
            $elements[] = $this->expression();

            while ($this->peek()->type === TokenType::Comma) {
                $this->advance();
                if ($this->peek()->type === TokenType::RBracket) {
                    break; // tolerate a trailing comma
                }
                $elements[] = $this->expression();
            }
        }

        $this->expect(TokenType::RBracket, 'Expected "]" to close the list.');

        return Ast::listLit($elements, $open->line, $open->col);
    }

    /** @return array<string, mixed> */
    private function mapLiteral(): array
    {
        $open = $this->advance(); // {
        $entries = [];

        if ($this->peek()->type !== TokenType::RBrace) {
            $entries[] = $this->mapEntry();

            while ($this->peek()->type === TokenType::Comma) {
                $this->advance();
                if ($this->peek()->type === TokenType::RBrace) {
                    break; // tolerate a trailing comma
                }
                $entries[] = $this->mapEntry();
            }
        }

        $this->expect(TokenType::RBrace, 'Expected "}" to close the map.');

        return Ast::mapLit($entries, $open->line, $open->col);
    }

    /** @return array{key: string, value: array<string, mixed>} */
    private function mapEntry(): array
    {
        $keyToken = $this->peek();

        if ($keyToken->type === TokenType::Ident) {
            $this->advance();
            $key = $keyToken->lexeme;
        } elseif ($keyToken->type === TokenType::Str) {
            $this->advance();
            $key = $keyToken->value;
        } else {
            throw new PslException('A map key must be a name or a string.', $keyToken->line, $keyToken->col);
        }

        $this->expect(TokenType::Colon, 'Expected ":" after the map key.');
        $value = $this->expression();

        return ['key' => $key, 'value' => $value];
    }

    private function peek(): Token
    {
        return $this->tokens[$this->pos];
    }

    private function advance(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(TokenType $type, string $message): Token
    {
        $t = $this->peek();

        if ($t->type !== $type) {
            throw new PslException($message, $t->line, $t->col);
        }

        return $this->advance();
    }
}
