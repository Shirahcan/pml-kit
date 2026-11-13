<?php

namespace Shirahcan\Pml;


/**
 * The names a page's `{...}` expressions read, and the ones nothing on the page defines. ONE check, read two
 * ways: the Code view shows each unknown name as a hint to a person typing (aid-not-enforce), and Porter's
 * Build gates (pages and components) hand them to the repair pass, because a name nothing defines draws
 * nothing. A component Porter wrote with `{title}` for its `{props.title}` setting published with an empty
 * heading (2026-11-11): the hint existed, but only the Code view read it.
 *
 * Static syntax only: it reads which names an expression STARTS from, never evaluates one.
 */
final class ExpressionNames
{
    /** The PSL standard-library modules an expression may use (string.upper, list.length, ...). */
    public const STDLIB_MODULES = ['string', 'number', 'list', 'map', 'date', 'imm'];

    /**
     * Everything an expression may start from: the natives, the std-lib, the `each` items around it and the
     * names the page's scripts define.
     *
     * @param  list<string>  $locals
     * @param  list<string>  $scriptNames
     * @return list<string>
     */
    public static function known(array $locals = [], array $scriptNames = []): array
    {
        return [...Natives::roots(), ...self::STDLIB_MODULES, ...$locals, ...$scriptNames];
    }

    /**
     * One message per name the tree's expressions read that nothing defines, for a gate to refuse or repair.
     *
     * @param  list<mixed>  $nodes
     * @param  list<string>|null  $settings  inside a component, its setting names: `{title}` is told it means
     *                                       props.title, and `props.titel` that no such setting exists (component
     *                                       props p05). Null on a page, which has no settings of its own.
     * @return list<string>
     */
    public static function errors(array $nodes, ?array $settings = null): array
    {
        $out = [];
        self::walk($nodes, [], self::scriptNamesIn($nodes), $settings, $out);

        return array_values(array_unique($out));
    }

    /**
     * Only the certain mistakes about a component's OWN settings: a setting read without `props.` (`{title}`)
     * and a `props.X` it does not declare. A partial's save refuses these, because a reused section that reads
     * a setting wrongly shows nothing on every page that uses it; any other unknown name stays a hint.
     *
     * @param  list<mixed>  $nodes
     * @param  list<string>  $settings
     * @return list<string>
     */
    public static function settingErrors(array $nodes, array $settings): array
    {
        $out = [];
        self::walk($nodes, [], self::scriptNamesIn($nodes), $settings, $out, true);

        return array_values(array_unique($out));
    }

    /**
     * The settings an element's expressions read as `props.X` that the component does not declare.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $settings
     * @return list<string>
     */
    public static function undeclaredProps(array $node, array $settings): array
    {
        $names = [];

        foreach (self::expressionsOf($node) as $expr) {
            foreach (self::propsReads((array) ($expr['ast'] ?? [])) as $name) {
                if (! in_array($name, $settings, true) && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /** What a person or Porter is told about a `props.X` the component does not declare. */
    public static function undeclaredMessage(string $name, array $settings): string
    {
        return "props.{$name} is not one of this component's settings, so it shows nothing. "
            .($settings === [] ? 'It has no settings yet: declare one in the <props> block.' : 'It has: '.implode(', ', $settings).'.');
    }

    /**
     * The setting names an expression reads as `props.X`.
     *
     * @param  array<int|string, mixed>  $ast
     * @return list<string>
     */
    private static function propsReads(array $ast): array
    {
        if (($ast['type'] ?? null) === 'Member' && ($ast['object']['type'] ?? null) === 'Identifier' && ($ast['object']['name'] ?? null) === 'props') {
            return [(string) ($ast['name'] ?? '')];
        }

        $names = [];
        foreach ($ast as $child) {
            if (is_array($child)) {
                $names = [...$names, ...self::propsReads($child)];
            }
        }

        return $names;
    }

    /**
     * The names this one element's expressions read that are not in `$known`, each once.
     *
     * @param  array<string, mixed>  $node
     * @param  list<string>  $known
     * @return list<string>
     */
    public static function unknownIn(array $node, array $known): array
    {
        $names = [];

        foreach (self::expressionsOf($node) as $expr) {
            foreach (self::freeNames((array) ($expr['ast'] ?? [])) as $name) {
                if (! in_array($name, $known, true) && ! in_array($name, $names, true)) {
                    $names[] = $name;
                }
            }
        }

        return $names;
    }

    /**
     * What a person (or Porter's repair pass) is told about one unknown name.
     *
     * @param  list<string>|null  $settings
     */
    public static function message(string $name, ?array $settings = null): string
    {
        if (in_array($name, $settings ?? [], true)) {
            return "\"{$name}\" is one of this component's settings: read it as props.{$name}, or it shows nothing.";
        }

        return "\"{$name}\" is not something a page can read, so this shows nothing. Start from owner, site, page, state, location, device or now (or props inside a component).";
    }

    /**
     * Top-level names the page's `<script>`s define (function and value declarations).
     *
     * @param  list<mixed>  $nodes
     * @return list<string>
     */
    public static function scriptNamesIn(array $nodes): array
    {
        $names = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            if (($node['primitive'] ?? null) === 'script') {
                foreach ((array) ($node['props']['program']['body'] ?? []) as $statement) {
                    if (in_array($statement['type'] ?? null, ['FuncDecl', 'VarDecl'], true) && is_string($statement['name'] ?? null)) {
                        $names[] = $statement['name'];
                    }
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                $names = [...$names, ...self::scriptNamesIn($node['children'])];
            }
        }

        return $names;
    }

    /**
     * The variable names an expression reads (a member's property is not a variable; a function
     * written inside the expression keeps its own parameters to itself).
     *
     * @param  array<int|string, mixed>  $ast
     * @return list<string>
     */
    public static function freeNames(array $ast): array
    {
        if (($ast['type'] ?? null) === 'Identifier') {
            return [(string) ($ast['name'] ?? '')];
        }

        if (($ast['type'] ?? null) === 'FuncExpr') {
            return [];
        }

        $names = [];
        foreach ($ast as $child) {
            if (is_array($child)) {
                $names = [...$names, ...self::freeNames($child)];
            }
        }

        return $names;
    }

    /**
     * Every expression on one element: its props and styles, its if / else-if / each, its handlers.
     *
     * @param  array<string, mixed>  $node
     * @return list<array<string, mixed>>
     */
    private static function expressionsOf(array $node): array
    {
        $exprs = [];

        foreach (['props', 'style'] as $bag) {
            foreach ((array) ($node[$bag] ?? []) as $value) {
                if (PrimitiveSchema::isExpression($value)) {
                    $exprs[] = $value;
                }
            }
        }

        foreach (['if', 'else_if', 'each'] as $field) {
            if (isset($node[$field]) && is_array($node[$field])) {
                $exprs[] = $node[$field];
            }
        }

        foreach ((array) ($node['on'] ?? []) as $handler) {
            if (is_array($handler)) {
                $exprs[] = $handler;
            }
        }

        return $exprs;
    }

    /**
     * @param  list<mixed>  $nodes
     * @param  list<string>  $locals
     * @param  list<string>  $scriptNames
     * @param  list<string>|null  $settings
     * @param  list<string>  $out
     * @param  bool  $settingsOnly  report only names that are the component's settings (and undeclared props.X)
     */
    private static function walk(array $nodes, array $locals, array $scriptNames, ?array $settings, array &$out, bool $settingsOnly = false): void
    {
        foreach ($nodes as $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            // An `each` item is in scope on the element it repeats and everything inside it.
            $inner = isset($node['each']['item']) ? [...$locals, (string) $node['each']['item']] : $locals;

            foreach (self::unknownIn($node, self::known($inner, $scriptNames)) as $name) {
                if (! $settingsOnly || in_array($name, $settings ?? [], true)) {
                    $out[] = self::message($name, $settings);
                }
            }

            if ($settings !== null) {
                foreach (self::undeclaredProps($node, $settings) as $name) {
                    $out[] = self::undeclaredMessage($name, $settings);
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                self::walk($node['children'], $inner, $scriptNames, $settings, $out, $settingsOnly);
            }
        }
    }
}
