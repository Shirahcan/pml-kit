<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Psl\PslException;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PmlExpression;

/**
 * Every `{...}` in a tree a browser sent, read again from its own text (component props p01, 2026-11-11).
 *
 * An expression is stored as `{"$expr": source, "ast": tree}`. The save gate checked only that shape, so a
 * draft kept whatever tree the browser sent, even one that disagreed with its own text: the Code view showed
 * the text and the page ran the tree. PML v3's rule is ONE parser (PSL's, on the server), so the stored tree
 * is always the one the text gives, and a browser (the builder's list editor, a hand-made request) only ever
 * supplies the text.
 */
final class ExpressionSource
{
    /**
     * @param  list<mixed>  $nodes
     * @return list<mixed>
     *
     * @throws InvalidPmlException  when an expression's text is not one PSL expression, at its path
     */
    public static function recompile(array $nodes, string $path = 'nodes'): array
    {
        foreach ($nodes as $i => $node) {
            if (! is_array($node) || PmlParser::isCommentNode($node)) {
                continue;
            }

            $at = "{$path}.{$i}";

            foreach (['props', 'style'] as $bag) {
                if (! is_array($node[$bag] ?? null)) {
                    continue;
                }

                foreach ($node[$bag] as $key => $value) {
                    if (PrimitiveSchema::isExpression($value)) {
                        $node[$bag][$key] = self::read($value, "{$at}.{$bag}.{$key}");
                    }
                }
            }

            foreach (['if', 'else_if'] as $field) {
                if (PrimitiveSchema::isExpression($node[$field] ?? null)) {
                    $node[$field] = self::read($node[$field], "{$at}.{$field}");
                }
            }

            // `each` keeps the item's name beside the list expression.
            if (PrimitiveSchema::isExpression($node['each'] ?? null)) {
                $node['each'] = ['item' => (string) ($node['each']['item'] ?? ''), ...self::read($node['each'], "{$at}.each")];
            }

            if (is_array($node['on'] ?? null)) {
                foreach ($node['on'] as $event => $handler) {
                    if (PrimitiveSchema::isExpression($handler)) {
                        $node['on'][$event] = self::read($handler, "{$at}.on.{$event}");
                    }
                }
            }

            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::recompile($node['children'], "{$at}.children");
            }

            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array{"$expr": string, ast: array<string, mixed>}
     *
     * @throws InvalidPmlException
     */
    private static function read(array $value, string $path): array
    {
        $source = $value['$expr'] ?? null;

        if (! is_string($source) || trim($source) === '') {
            throw new InvalidPmlException('An expression must be written in braces, like {owner.name}.', $path);
        }

        try {
            return PmlExpression::of($source);
        } catch (PslException $e) {
            $suffix = " (line {$e->atLine}, column {$e->atColumn})";
            $message = str_ends_with($e->getMessage(), $suffix) ? substr($e->getMessage(), 0, -strlen($suffix)) : $e->getMessage();

            throw new InvalidPmlException("The expression {{$source}} has a PSL error: {$message}", $path);
        }
    }
}
