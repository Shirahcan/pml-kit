<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\InvalidPmlException;

/**
 * A component's own state (PML v3, owner 2026-11-10: "close all gaps"). A page has ONE PSL runtime and one
 * `state`, so two copies of the same interactive component used to share every value and every function:
 * opening one accordion opened the other. Inside a component, `self.open` is that copy's own value.
 *
 * Done at expansion, by substitution (never evaluation, like `props.X`): every `self.name` in the copy
 * becomes `state.<key>_name`, and every function or top-level variable its scripts declare becomes
 * `<key>_name`, where `<key>` is the copy's own (its uid, prefixed by the copy it sits in). The browser
 * runs the rewritten tree with no idea `self` existed, so nothing new runs at runtime.
 *
 * Words a page put into the component's slot are the PAGE's, not the copy's, and are left alone.
 */
final class InstanceScope
{
    /** Marks a node the expander put in a slot (the page's own content); removed on the way out. */
    public const OUTER = '__outer';

    /**
     * @param  array<string, mixed>  $node  the expanded component root
     * @return array<string, mixed>
     */
    public static function apply(array $node, string $key): array
    {
        $names = self::declared($node);

        return self::walk($node, $key, $names);
    }

    /**
     * Throw when a `self` is left outside a component (the page itself has no "own" state).
     *
     * @param  array<string, mixed>  $node
     *
     * @throws InvalidPmlException
     */
    public static function assertNoSelf(array $node): void
    {
        foreach (self::expressionsOf($node) as $ast) {
            if (self::readsSelf($ast)) {
                throw new InvalidPmlException('"self" is a component\'s own state, so it only works inside a component. On a page, use state (state.open).', 'props');
            }
        }
    }

    /**
     * Every name a copy's scripts declare at their top level (functions and variables).
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    private static function declared(array $node): array
    {
        if (isset($node[self::OUTER])) {
            return [];
        }

        $names = [];

        if (($node['primitive'] ?? null) === 'script' && is_array($node['props']['program']['body'] ?? null)) {
            foreach ($node['props']['program']['body'] as $statement) {
                if (in_array($statement['type'] ?? null, ['FuncDecl', 'VarDecl'], true) && is_string($statement['name'] ?? null)) {
                    $names[] = $statement['name'];
                }
            }
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                array_push($names, ...self::declared($child));
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  list<string>  $names
     * @return array<string, mixed>
     */
    private static function walk(array $node, string $key, array $names): array
    {
        if (isset($node[self::OUTER])) {
            unset($node[self::OUTER]);

            return $node;
        }

        foreach (['props', 'style'] as $bag) {
            foreach ((array) ($node[$bag] ?? []) as $prop => $value) {
                if (PrimitiveSchema::isExpression($value)) {
                    $node[$bag][$prop]['ast'] = self::rewrite($value['ast'], $key, $names);
                }
            }
        }

        foreach (['if', 'else_if'] as $field) {
            if (is_array($node[$field]['ast'] ?? null)) {
                $node[$field]['ast'] = self::rewrite($node[$field]['ast'], $key, $names);
            }
        }

        if (is_array($node['each']['ast'] ?? null)) {
            $node['each']['ast'] = self::rewrite($node['each']['ast'], $key, $names);
        }

        foreach ((array) ($node['on'] ?? []) as $event => $handler) {
            if (is_array($handler['ast'] ?? null)) {
                $node['on'][$event]['ast'] = self::rewrite($handler['ast'], $key, $names);
            }
        }

        if (($node['primitive'] ?? null) === 'script' && is_array($node['props']['program'] ?? null)) {
            $node['props']['program'] = self::rewrite($node['props']['program'], $key, $names);
        }

        // A rich text's `{...}` only become atoms when its document is made, after this: it carries this copy's
        // key so `{self.count}` in a sentence reads this copy's value too (component props p10).
        if (($node['primitive'] ?? null) === 'rich_text' && str_contains((string) ($node['props']['markdown'] ?? ''), '{')) {
            $node[RichTextFill::INSTANCE] = ['key' => $key, 'names' => $names];
        }

        if (isset($node['children']) && is_array($node['children'])) {
            $node['children'] = array_map(fn ($child) => is_array($child) ? self::walk($child, $key, $names) : $child, $node['children']);
        }

        return $node;
    }

    /**
     * One AST with `self.name` -> `state.<key>_name` and each declared name -> `<key>_name`. A function's own
     * parameter of the same name is its own, so the rename stops at that function.
     *
     * @param  array<int|string, mixed>  $ast
     * @param  list<string>  $names
     * @return array<int|string, mixed>
     */
    public static function rewriteAst(array $ast, string $key, array $names): array
    {
        return self::rewrite($ast, $key, $names);
    }

    /**
     * @param  array<int|string, mixed>  $ast
     * @param  list<string>  $names
     * @return array<int|string, mixed>
     */
    private static function rewrite(array $ast, string $key, array $names): array
    {
        $type = $ast['type'] ?? null;

        if ($type === 'Member' && ($ast['object']['type'] ?? null) === 'Identifier' && ($ast['object']['name'] ?? null) === 'self') {
            $ast['object']['name'] = 'state';
            $ast['name'] = $key.'_'.$ast['name'];

            return $ast;
        }

        // `self` on its own (passed around, indexed with [...]) cannot be given a copy's keys ahead of time.
        if ($type === 'Identifier' && ($ast['name'] ?? null) === 'self') {
            throw new InvalidPmlException('Read a component\'s own value by name, like self.open (not self on its own or self[...]).', 'props');
        }

        if ($type === 'Identifier' && in_array($ast['name'] ?? null, $names, true)) {
            $ast['name'] = $key.'_'.$ast['name'];

            return $ast;
        }

        if (in_array($type, ['FuncDecl', 'VarDecl'], true) && in_array($ast['name'] ?? null, $names, true)) {
            $ast['name'] = $key.'_'.$ast['name'];
        }

        if (in_array($type, ['FuncDecl', 'FuncExpr'], true)) {
            $params = array_map(fn ($p) => is_array($p) ? ($p['name'] ?? null) : null, (array) ($ast['params'] ?? []));
            $names = array_values(array_diff($names, $params));
        }

        foreach ($ast as $field => $child) {
            if (is_array($child)) {
                $ast[$field] = self::rewrite($child, $key, $names);
            }
        }

        return $ast;
    }

    /**
     * @param  array<int|string, mixed>  $ast
     */
    private static function readsSelf(array $ast): bool
    {
        if (($ast['type'] ?? null) === 'Identifier' && ($ast['name'] ?? null) === 'self') {
            return true;
        }

        foreach ($ast as $child) {
            if (is_array($child) && self::readsSelf($child)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The ASTs a node itself carries (not its children's).
     *
     * @param  array<string, mixed>  $node
     * @return list<array<int|string, mixed>>
     */
    private static function expressionsOf(array $node): array
    {
        $out = [];

        foreach (['props', 'style'] as $bag) {
            foreach ((array) ($node[$bag] ?? []) as $value) {
                if (PrimitiveSchema::isExpression($value)) {
                    $out[] = $value['ast'];
                }
            }
        }

        foreach (['if', 'else_if', 'each'] as $field) {
            if (is_array($node[$field]['ast'] ?? null)) {
                $out[] = $node[$field]['ast'];
            }
        }

        foreach ((array) ($node['on'] ?? []) as $handler) {
            if (is_array($handler['ast'] ?? null)) {
                $out[] = $handler['ast'];
            }
        }

        if (($node['primitive'] ?? null) === 'script' && is_array($node['props']['program'] ?? null)) {
            $out[] = $node['props']['program'];
        }

        return $out;
    }
}
