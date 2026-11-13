<?php

namespace Shirahcan\Pml\Psl;

/**
 * Turns PSL source into a flat token stream with positions. Pure byte scanning, no dependencies.
 *
 * The lexer is deliberately dumb: it recognises numbers (kept as exact decimal STRINGS, never
 * float-parsed), double-quoted strings with a small escape set, identifiers/keywords, and the fixed
 * operator/punctuation set. Anything it cannot classify is a {@see PslException} with a precise
 * line/column, so the builder's PSL pane can underline the exact spot.
 */
class Lexer
{
    private string $src = '';

    private int $len = 0;

    private int $i = 0;

    private int $line = 1;

    private int $col = 1;

    /** Reserved words -> their token type. Everything else that looks like a word is an identifier. */
    private const KEYWORDS = [
        'const' => TokenType::Const,
        'let' => TokenType::Let,
        'function' => TokenType::Function,
        'if' => TokenType::If,
        'else' => TokenType::Else,
        'for' => TokenType::For,
        'in' => TokenType::In,
        'while' => TokenType::While,
        'return' => TokenType::Return,
        'break' => TokenType::Break,
        'continue' => TokenType::Continue,
        'true' => TokenType::BoolTrue,
        'false' => TokenType::BoolFalse,
        'null' => TokenType::Nil,
    ];

    private const TWO_CHAR = [
        '==' => TokenType::Eq,
        '!=' => TokenType::Neq,
        '<=' => TokenType::Lte,
        '>=' => TokenType::Gte,
        '&&' => TokenType::And,
        '||' => TokenType::Or,
        '+=' => TokenType::PlusAssign,
        '-=' => TokenType::MinusAssign,
        '*=' => TokenType::StarAssign,
        '/=' => TokenType::SlashAssign,
        // '++' / '--' are matched before the single-char '+' / '-' (exact two-char lookup), so a
        // greedy `count++` is one increment, never `count` `+` `+`.
        '++' => TokenType::PlusPlus,
        '--' => TokenType::MinusMinus,
    ];

    private const ONE_CHAR = [
        '+' => TokenType::Plus,
        '-' => TokenType::Minus,
        '*' => TokenType::Star,
        '/' => TokenType::Slash,
        '%' => TokenType::Percent,
        '=' => TokenType::Assign,
        '<' => TokenType::Lt,
        '>' => TokenType::Gt,
        '!' => TokenType::Bang,
        '?' => TokenType::Question,
        ':' => TokenType::Colon,
        '(' => TokenType::LParen,
        ')' => TokenType::RParen,
        '{' => TokenType::LBrace,
        '}' => TokenType::RBrace,
        '[' => TokenType::LBracket,
        ']' => TokenType::RBracket,
        ',' => TokenType::Comma,
        '.' => TokenType::Dot,
        ';' => TokenType::Semicolon,
    ];

    /**
     * @return Token[] always terminated by a single EOF token.
     */
    public function tokenize(string $source): array
    {
        $this->src = $source;
        $this->len = strlen($source);
        $this->i = 0;
        $this->line = 1;
        $this->col = 1;

        $tokens = [];

        while (true) {
            $token = $this->next();
            $tokens[] = $token;

            if ($token->type === TokenType::Eof) {
                break;
            }
        }

        return $tokens;
    }

    private function next(): Token
    {
        $this->skipTrivia();

        $line = $this->line;
        $col = $this->col;

        if ($this->i >= $this->len) {
            return new Token(TokenType::Eof, '', null, $line, $col);
        }

        $c = $this->src[$this->i];

        if ($this->isIdentStart($c)) {
            return $this->scanIdentifier($line, $col);
        }

        if ($this->isDigit($c)) {
            return $this->scanNumber($line, $col);
        }

        if ($c === '"') {
            return $this->scanString($line, $col);
        }

        return $this->scanOperator($line, $col);
    }

    private function skipTrivia(): void
    {
        while ($this->i < $this->len) {
            $c = $this->src[$this->i];

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $this->advance();

                continue;
            }

            if ($c === '/' && $this->peek(1) === '/') {
                while ($this->i < $this->len && $this->src[$this->i] !== "\n") {
                    $this->advance();
                }

                continue;
            }

            if ($c === '/' && $this->peek(1) === '*') {
                $this->advance();
                $this->advance();
                while ($this->i < $this->len && ! ($this->src[$this->i] === '*' && $this->peek(1) === '/')) {
                    $this->advance();
                }
                if ($this->i < $this->len) {
                    $this->advance();
                    $this->advance();
                }

                continue;
            }

            break;
        }
    }

    private function scanIdentifier(int $line, int $col): Token
    {
        $start = $this->i;
        while ($this->i < $this->len && $this->isIdentPart($this->src[$this->i])) {
            $this->advance();
        }
        $lexeme = substr($this->src, $start, $this->i - $start);
        $type = self::KEYWORDS[$lexeme] ?? TokenType::Ident;

        $value = match ($type) {
            TokenType::BoolTrue => true,
            TokenType::BoolFalse => false,
            default => null,
        };

        return new Token($type, $lexeme, $value, $line, $col);
    }

    private function scanNumber(int $line, int $col): Token
    {
        $start = $this->i;
        while ($this->i < $this->len && $this->isDigit($this->src[$this->i])) {
            $this->advance();
        }

        if ($this->i < $this->len && $this->src[$this->i] === '.') {
            if (! $this->isDigit($this->peek(1))) {
                throw new PslException('A number cannot end with a decimal point.', $this->line, $this->col, 'lex');
            }
            $this->advance();
            while ($this->i < $this->len && $this->isDigit($this->src[$this->i])) {
                $this->advance();
            }
        }

        $lexeme = substr($this->src, $start, $this->i - $start);

        // value is the exact decimal string - never a float.
        return new Token(TokenType::Number, $lexeme, $lexeme, $line, $col);
    }

    private function scanString(int $line, int $col): Token
    {
        $start = $this->i;
        $this->advance(); // opening quote
        $buf = '';

        while (true) {
            if ($this->i >= $this->len) {
                throw new PslException('Unterminated string.', $line, $col, 'lex');
            }

            $c = $this->src[$this->i];

            if ($c === "\n") {
                throw new PslException('Unterminated string (a string cannot span a line).', $line, $col, 'lex');
            }

            $this->advance();

            if ($c === '"') {
                break;
            }

            if ($c === '\\') {
                if ($this->i >= $this->len) {
                    throw new PslException('Unterminated escape in string.', $this->line, $this->col, 'lex');
                }
                $e = $this->src[$this->i];
                $this->advance();
                $buf .= match ($e) {
                    '"' => '"',
                    '\\' => '\\',
                    'n' => "\n",
                    't' => "\t",
                    default => throw new PslException("Unknown escape \"\\{$e}\".", $this->line, $this->col, 'lex'),
                };

                continue;
            }

            $buf .= $c;
        }

        $lexeme = substr($this->src, $start, $this->i - $start);

        return new Token(TokenType::Str, $lexeme, $buf, $line, $col);
    }

    private function scanOperator(int $line, int $col): Token
    {
        $c = $this->src[$this->i];
        $two = $c.$this->peek(1);

        if (isset(self::TWO_CHAR[$two])) {
            $this->advance();
            $this->advance();

            return new Token(self::TWO_CHAR[$two], $two, null, $line, $col);
        }

        if (isset(self::ONE_CHAR[$c])) {
            $this->advance();

            return new Token(self::ONE_CHAR[$c], $c, null, $line, $col);
        }

        throw new PslException("Unexpected character \"{$c}\".", $line, $col, 'lex');
    }

    private function advance(): string
    {
        $c = $this->src[$this->i];
        $this->i++;

        if ($c === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }

        return $c;
    }

    private function peek(int $offset): string
    {
        return $this->src[$this->i + $offset] ?? '';
    }

    private function isDigit(string $c): bool
    {
        return $c >= '0' && $c <= '9';
    }

    private function isIdentStart(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_';
    }

    private function isIdentPart(string $c): bool
    {
        return $this->isIdentStart($c) || $this->isDigit($c);
    }
}
