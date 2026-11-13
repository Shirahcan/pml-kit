<?php

namespace Shirahcan\Pml\Psl;

/**
 * Every kind of PSL token. String-backed so a case can be printed for debugging / test messages,
 * but the parser and tests compare the enum cases themselves (type-safe, no magic strings).
 */
enum TokenType: string
{
    // Literals
    case Number = 'NUMBER';
    case Str = 'STRING';
    case BoolTrue = 'TRUE';
    case BoolFalse = 'FALSE';
    case Nil = 'NULL';

    // Identifier
    case Ident = 'IDENT';

    // Keywords
    case Const = 'CONST';
    case Let = 'LET';
    case Function = 'FUNCTION';
    case If = 'IF';
    case Else = 'ELSE';
    case For = 'FOR';
    case In = 'IN';
    case While = 'WHILE';
    case Return = 'RETURN';
    case Break = 'BREAK';
    case Continue = 'CONTINUE';

    // Operators
    case Plus = 'PLUS';
    case Minus = 'MINUS';
    case Star = 'STAR';
    case Slash = 'SLASH';
    case Percent = 'PERCENT';
    case Assign = 'ASSIGN';
    case PlusAssign = 'PLUS_ASSIGN';
    case MinusAssign = 'MINUS_ASSIGN';
    case StarAssign = 'STAR_ASSIGN';
    case SlashAssign = 'SLASH_ASSIGN';
    case PlusPlus = 'PLUS_PLUS';
    case MinusMinus = 'MINUS_MINUS';
    case Eq = 'EQ';
    case Neq = 'NEQ';
    case Lt = 'LT';
    case Lte = 'LTE';
    case Gt = 'GT';
    case Gte = 'GTE';
    case And = 'AND';
    case Or = 'OR';
    case Bang = 'BANG';
    case Question = 'QUESTION';
    case Colon = 'COLON';

    // Punctuation
    case LParen = 'LPAREN';
    case RParen = 'RPAREN';
    case LBrace = 'LBRACE';
    case RBrace = 'RBRACE';
    case LBracket = 'LBRACKET';
    case RBracket = 'RBRACKET';
    case Comma = 'COMMA';
    case Dot = 'DOT';
    case Semicolon = 'SEMICOLON';

    case Eof = 'EOF';
}
