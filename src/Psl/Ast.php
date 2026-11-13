<?php

namespace Shirahcan\Pml\Psl;

/**
 * Builders for PSL AST nodes.
 *
 * A PSL AST node is a plain associative array - because its canonical form IS the AST-JSON the TS
 * evaluator consumes (see the conformance corpus README). Representing nodes as arrays (rather than
 * one PHP class per node, which PSR-4 would explode into ~25 files) keeps the tree serialization-
 * native and lets this one factory OWN the key order, so `php artisan psl:compile-corpus` emits a
 * byte-stable contract. Every node is `['type' => ..., ...fields, 'line' => int, 'col' => int]`.
 *
 * Expression builders live here from Phase 02; statement builders are added in Phase 03.
 */
final class Ast
{
    public static function number(string $value, int $line, int $col): array
    {
        return ['type' => 'NumberLit', 'value' => $value, 'line' => $line, 'col' => $col];
    }

    public static function str(string $value, int $line, int $col): array
    {
        return ['type' => 'StringLit', 'value' => $value, 'line' => $line, 'col' => $col];
    }

    public static function boolean(bool $value, int $line, int $col): array
    {
        return ['type' => 'BoolLit', 'value' => $value, 'line' => $line, 'col' => $col];
    }

    public static function nullLit(int $line, int $col): array
    {
        return ['type' => 'NullLit', 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<int, array<string, mixed>>  $elements
     */
    public static function listLit(array $elements, int $line, int $col): array
    {
        return ['type' => 'ListLit', 'elements' => array_values($elements), 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<int, array{key: string, value: array<string, mixed>}>  $entries
     */
    public static function mapLit(array $entries, int $line, int $col): array
    {
        return ['type' => 'MapLit', 'entries' => array_values($entries), 'line' => $line, 'col' => $col];
    }

    public static function identifier(string $name, int $line, int $col): array
    {
        return ['type' => 'Identifier', 'name' => $name, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $operand
     */
    public static function unary(string $op, array $operand, int $line, int $col): array
    {
        return ['type' => 'Unary', 'op' => $op, 'operand' => $operand, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    public static function binary(string $op, array $left, array $right, int $line, int $col): array
    {
        return ['type' => 'Binary', 'op' => $op, 'left' => $left, 'right' => $right, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $cond
     * @param  array<string, mixed>  $then
     * @param  array<string, mixed>  $else
     */
    public static function ternary(array $cond, array $then, array $else, int $line, int $col): array
    {
        return ['type' => 'Ternary', 'cond' => $cond, 'then' => $then, 'else' => $else, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $value
     */
    public static function assign(string $op, array $target, array $value, int $line, int $col): array
    {
        return ['type' => 'Assign', 'op' => $op, 'target' => $target, 'value' => $value, 'line' => $line, 'col' => $col];
    }

    /**
     * A prefix or postfix increment / decrement (`++x` / `x++`). The operand is an l-value
     * (Identifier | Member | Index); `prefix` selects pre (return the new value) vs post (the old).
     *
     * @param  array<string, mixed>  $operand
     */
    public static function update(string $op, array $operand, bool $prefix, int $line, int $col): array
    {
        return ['type' => 'Update', 'op' => $op, 'operand' => $operand, 'prefix' => $prefix, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $callee
     * @param  array<int, array<string, mixed>>  $args
     */
    public static function call(array $callee, array $args, int $line, int $col): array
    {
        return ['type' => 'Call', 'callee' => $callee, 'args' => array_values($args), 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $object
     */
    public static function member(array $object, string $name, int $line, int $col): array
    {
        return ['type' => 'Member', 'object' => $object, 'name' => $name, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $object
     * @param  array<string, mixed>  $index
     */
    public static function index(array $object, array $index, int $line, int $col): array
    {
        return ['type' => 'Index', 'object' => $object, 'index' => $index, 'line' => $line, 'col' => $col];
    }

    // --- Statements & declarations (Phase 03) ---

    /**
     * @param  array<int, array<string, mixed>>  $body
     */
    public static function program(array $body, int $line, int $col): array
    {
        return ['type' => 'Program', 'body' => array_values($body), 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<int, array<string, mixed>>  $body
     */
    public static function block(array $body, int $line, int $col): array
    {
        return ['type' => 'Block', 'body' => array_values($body), 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>|null  $init
     */
    public static function varDecl(string $kind, string $name, ?string $varType, ?array $init, int $line, int $col): array
    {
        return ['type' => 'VarDecl', 'kind' => $kind, 'name' => $name, 'varType' => $varType, 'init' => $init, 'line' => $line, 'col' => $col];
    }

    /**
     * A parameter is a plain {name, paramType} - it carries no position of its own.
     *
     * @return array{name: string, paramType: string|null}
     */
    public static function param(string $name, ?string $paramType): array
    {
        return ['name' => $name, 'paramType' => $paramType];
    }

    /**
     * @param  array<int, array{name: string, paramType: string|null}>  $params
     * @param  array<string, mixed>  $body
     */
    public static function funcDecl(string $name, array $params, ?string $returnType, array $body, int $line, int $col): array
    {
        return ['type' => 'FuncDecl', 'name' => $name, 'params' => array_values($params), 'returnType' => $returnType, 'body' => $body, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<int, array{name: string, paramType: string|null}>  $params
     * @param  array<string, mixed>  $body
     */
    public static function funcExpr(array $params, ?string $returnType, array $body, int $line, int $col): array
    {
        return ['type' => 'FuncExpr', 'params' => array_values($params), 'returnType' => $returnType, 'body' => $body, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<int, array{cond: array<string, mixed>|null, body: array<string, mixed>}>  $branches
     */
    public static function ifStmt(array $branches, int $line, int $col): array
    {
        return ['type' => 'If', 'branches' => array_values($branches), 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $iterable
     * @param  array<string, mixed>  $body
     */
    public static function forStmt(string $itemVar, ?string $keyVar, array $iterable, array $body, int $line, int $col): array
    {
        return ['type' => 'For', 'itemVar' => $itemVar, 'keyVar' => $keyVar, 'iterable' => $iterable, 'body' => $body, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>  $cond
     * @param  array<string, mixed>  $body
     */
    public static function whileStmt(array $cond, array $body, int $line, int $col): array
    {
        return ['type' => 'While', 'cond' => $cond, 'body' => $body, 'line' => $line, 'col' => $col];
    }

    /**
     * @param  array<string, mixed>|null  $value
     */
    public static function returnStmt(?array $value, int $line, int $col): array
    {
        return ['type' => 'Return', 'value' => $value, 'line' => $line, 'col' => $col];
    }

    public static function breakStmt(int $line, int $col): array
    {
        return ['type' => 'Break', 'line' => $line, 'col' => $col];
    }

    public static function continueStmt(int $line, int $col): array
    {
        return ['type' => 'Continue', 'line' => $line, 'col' => $col];
    }
}
