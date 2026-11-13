<?php

namespace Shirahcan\Pml\Psl;

/**
 * The statement + declaration half of the parser, split into a trait to keep {@see Parser} under the
 * project's file-size limit. These methods share the same token cursor and helpers as the expression
 * parser (`peek`/`advance`/`expect`/`expression`/`primary`), so they belong ON Parser as a trait
 * rather than in a separate object.
 *
 * Statements are self-delimiting: a `const`/`if`/`for`/... can only start a new statement, so a
 * missing semicolon is harmless (an optional `;` is consumed when present). The parser still only
 * builds the tree - never evaluates, never type-checks.
 */
trait ParsesStatements
{
    /**
     * Parse a whole program into a Program node.
     *
     * @return array<string, mixed>
     */
    public function parseProgram(string $source): array
    {
        $this->tokens = (new Lexer)->tokenize($source);
        $this->pos = 0;

        $body = [];
        while ($this->peek()->type !== TokenType::Eof) {
            $body[] = $this->statement();
        }

        return Ast::program($body, 1, 1);
    }

    /** @return array<string, mixed> */
    private function statement(): array
    {
        return match ($this->peek()->type) {
            TokenType::Const, TokenType::Let => $this->varDeclaration(),
            TokenType::Function => $this->isFunctionDeclaration()
                ? $this->functionDeclaration()
                : $this->expressionStatement(),
            TokenType::If => $this->ifStatement(),
            TokenType::For => $this->forStatement(),
            TokenType::While => $this->whileStatement(),
            TokenType::Return => $this->returnStatement(),
            TokenType::Break => $this->breakStatement(),
            TokenType::Continue => $this->continueStatement(),
            TokenType::LBrace => $this->block(),
            default => $this->expressionStatement(),
        };
    }

    /** `function` is a declaration when a name follows it, else a function expression. */
    private function isFunctionDeclaration(): bool
    {
        return isset($this->tokens[$this->pos + 1])
            && $this->tokens[$this->pos + 1]->type === TokenType::Ident;
    }

    /** @return array<string, mixed> */
    private function block(): array
    {
        $open = $this->expect(TokenType::LBrace, 'Expected "{" to open a block.');
        $body = [];

        while ($this->peek()->type !== TokenType::RBrace && $this->peek()->type !== TokenType::Eof) {
            $body[] = $this->statement();
        }

        $this->expect(TokenType::RBrace, 'Expected "}" to close the block.');

        return Ast::block($body, $open->line, $open->col);
    }

    /** @return array<string, mixed> */
    private function varDeclaration(): array
    {
        $kw = $this->advance();
        $kind = $kw->type === TokenType::Const ? 'const' : 'let';
        $nameTok = $this->expect(TokenType::Ident, 'Expected a variable name.');
        $varType = $this->optionalTypeAnnotation();

        $init = null;
        if ($this->peek()->type === TokenType::Assign) {
            $this->advance();
            $init = $this->expression();
        }

        if ($kind === 'const' && $init === null) {
            throw new PslException('A const must be given a value.', $kw->line, $kw->col);
        }

        $this->consumeStatementEnd();

        return Ast::varDecl($kind, $nameTok->lexeme, $varType, $init, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function functionDeclaration(): array
    {
        $kw = $this->advance();
        $nameTok = $this->expect(TokenType::Ident, 'Expected a function name.');
        $params = $this->parameterList();
        $returnType = $this->optionalTypeAnnotation();
        $body = $this->block();

        return Ast::funcDecl($nameTok->lexeme, $params, $returnType, $body, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function functionExpression(): array
    {
        $kw = $this->advance();
        $params = $this->parameterList();
        $returnType = $this->optionalTypeAnnotation();
        $body = $this->block();

        return Ast::funcExpr($params, $returnType, $body, $kw->line, $kw->col);
    }

    /** @return array<int, array{name: string, paramType: string|null}> */
    private function parameterList(): array
    {
        $this->expect(TokenType::LParen, 'Expected "(" for the parameter list.');
        $params = [];

        if ($this->peek()->type !== TokenType::RParen) {
            $params[] = $this->parameter();
            while ($this->peek()->type === TokenType::Comma) {
                $this->advance();
                $params[] = $this->parameter();
            }
        }

        $this->expect(TokenType::RParen, 'Expected ")" after the parameters.');

        return $params;
    }

    /** @return array{name: string, paramType: string|null} */
    private function parameter(): array
    {
        $nameTok = $this->expect(TokenType::Ident, 'Expected a parameter name.');
        $paramType = $this->optionalTypeAnnotation();

        return Ast::param($nameTok->lexeme, $paramType);
    }

    /** @return array<string, mixed> */
    private function ifStatement(): array
    {
        $kw = $this->advance();
        $branches = [];

        $this->expect(TokenType::LParen, 'Expected "(" after "if".');
        $cond = $this->expression();
        $this->expect(TokenType::RParen, 'Expected ")" after the condition.');
        $branches[] = ['cond' => $cond, 'body' => $this->block()];

        while ($this->peek()->type === TokenType::Else) {
            $this->advance();

            if ($this->peek()->type === TokenType::If) {
                $this->advance();
                $this->expect(TokenType::LParen, 'Expected "(" after "else if".');
                $elifCond = $this->expression();
                $this->expect(TokenType::RParen, 'Expected ")" after the condition.');
                $branches[] = ['cond' => $elifCond, 'body' => $this->block()];
            } else {
                $branches[] = ['cond' => null, 'body' => $this->block()];
                break;
            }
        }

        return Ast::ifStmt($branches, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function forStatement(): array
    {
        $kw = $this->advance();
        $this->expect(TokenType::LParen, 'Expected "(" after "for".');

        $first = $this->expect(TokenType::Ident, 'Expected a loop variable name.');
        $itemVar = $first->lexeme;
        $keyVar = null;

        if ($this->peek()->type === TokenType::Comma) {
            $this->advance();
            $second = $this->expect(TokenType::Ident, 'Expected a second loop variable after ",".');
            // `for (key, value in map)`: the first name is the key, the second is the value.
            $keyVar = $itemVar;
            $itemVar = $second->lexeme;
        }

        $this->expect(TokenType::In, 'Expected "in" in the for-loop.');
        $iterable = $this->expression();
        $this->expect(TokenType::RParen, 'Expected ")" after the iterable.');
        $body = $this->block();

        return Ast::forStmt($itemVar, $keyVar, $iterable, $body, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function whileStatement(): array
    {
        $kw = $this->advance();
        $this->expect(TokenType::LParen, 'Expected "(" after "while".');
        $cond = $this->expression();
        $this->expect(TokenType::RParen, 'Expected ")" after the condition.');
        $body = $this->block();

        return Ast::whileStmt($cond, $body, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function returnStatement(): array
    {
        $kw = $this->advance();

        $value = null;
        if (! $this->atStatementBoundary()) {
            $value = $this->expression();
        }

        $this->consumeStatementEnd();

        return Ast::returnStmt($value, $kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function breakStatement(): array
    {
        $kw = $this->advance();
        $this->consumeStatementEnd();

        return Ast::breakStmt($kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function continueStatement(): array
    {
        $kw = $this->advance();
        $this->consumeStatementEnd();

        return Ast::continueStmt($kw->line, $kw->col);
    }

    /** @return array<string, mixed> */
    private function expressionStatement(): array
    {
        $expr = $this->expression();
        $this->consumeStatementEnd();

        return $expr;
    }

    private function optionalTypeAnnotation(): ?string
    {
        if ($this->peek()->type !== TokenType::Colon) {
            return null;
        }

        $this->advance();

        return $this->expect(TokenType::Ident, 'Expected a type name after ":".')->lexeme;
    }

    private function consumeStatementEnd(): void
    {
        if ($this->peek()->type === TokenType::Semicolon) {
            $this->advance();
        }
    }

    /** A `return` value stops here: at `;`, `}`, EOF, or the start of another statement. */
    private function atStatementBoundary(): bool
    {
        return in_array($this->peek()->type, [
            TokenType::Semicolon,
            TokenType::RBrace,
            TokenType::Eof,
            TokenType::Const,
            TokenType::Let,
            TokenType::If,
            TokenType::For,
            TokenType::While,
            TokenType::Return,
            TokenType::Break,
            TokenType::Continue,
        ], true);
    }
}
