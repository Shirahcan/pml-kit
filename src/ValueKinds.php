<?php

namespace Shirahcan\Pml;

/**
 * The declared kind of what an expression reads, inside a component (component props p10, 2026-11-11; owner:
 * "pull context from the containing element on what types/kinds of data to look for").
 *
 * A component declares its settings, and a list's or map's fields, with kinds. So `props.intro`, and `item.answer`
 * inside `each={item in props.faq}`, have a kind the page can trust: a rich-text one is Markdown, and is drawn
 * formatted wherever it sits. Static only: it follows names, never evaluates. Anything it cannot follow (page
 * data, `state`, a function's result) has no kind, and is shown as words.
 *
 * A scope maps a name to its declared spec: `props` to the settings (as a map), an `each` item to its list's row.
 */
final class ValueKinds
{
    public const RICH = 'rich_text';

    /**
     * The scope a component's design starts in: its settings, read as `props`.
     *
     * @param  list<array<string, mixed>>  $settings
     * @return array<string, array<string, mixed>|null>
     */
    public static function settingsScope(array $settings): array
    {
        return $settings === [] ? [] : ['props' => ['kind' => 'map', 'fields' => array_values($settings)]];
    }

    /**
     * The declared spec an expression reads, or null when it cannot be followed.
     *
     * @param  array<string, mixed>  $ast
     * @param  array<string, array<string, mixed>|null>  $scope
     * @return array<string, mixed>|null
     */
    public static function of(array $ast, array $scope): ?array
    {
        return match ($ast['type'] ?? null) {
            'Identifier' => $scope[(string) ($ast['name'] ?? '')] ?? null,
            'Member' => self::field(self::of((array) ($ast['object'] ?? []), $scope), (string) ($ast['name'] ?? '')),
            'Index' => self::indexed(self::of((array) ($ast['object'] ?? []), $scope), (array) ($ast['index'] ?? [])),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $ast  @param  array<string, array<string, mixed>|null>  $scope */
    public static function isRich(array $ast, array $scope): bool
    {
        return (self::of($ast, $scope)['kind'] ?? null) === self::RICH;
    }

    /**
     * One item of a list: a map of its fields, or a value of its `of` kind.
     *
     * @param  array<string, mixed>|null  $spec
     * @return array<string, mixed>|null
     */
    public static function row(?array $spec): ?array
    {
        if (($spec['kind'] ?? null) !== 'list') {
            return null;
        }

        if (isset($spec['fields']) && is_array($spec['fields'])) {
            return ['kind' => 'map', 'fields' => $spec['fields']];
        }

        return ['kind' => (string) ($spec['of'] ?? 'text')];
    }

    /**
     * Whether anything in this scope is rich text, so a node that reads nothing of the kind carries no marks.
     *
     * @param  array<string, array<string, mixed>|null>  $scope
     */
    public static function anyRich(array $scope): bool
    {
        $walk = static function (?array $spec) use (&$walk): bool {
            if ($spec === null) {
                return false;
            }

            if (($spec['kind'] ?? null) === self::RICH || ($spec['of'] ?? null) === self::RICH) {
                return true;
            }

            foreach ((array) ($spec['fields'] ?? []) as $field) {
                if (is_array($field) && $walk($field)) {
                    return true;
                }
            }

            return false;
        };

        foreach ($scope as $spec) {
            if ($walk($spec)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>|null  $spec
     * @return array<string, mixed>|null
     */
    private static function field(?array $spec, string $name): ?array
    {
        if (($spec['kind'] ?? null) !== 'map') {
            return null;
        }

        foreach ((array) ($spec['fields'] ?? []) as $field) {
            if (is_array($field) && ($field['name'] ?? null) === $name) {
                return $field;
            }
        }

        return null;
    }

    /**
     * `list[i]` is a row; `map["name"]` is that field.
     *
     * @param  array<string, mixed>|null  $spec
     * @param  array<string, mixed>  $index
     * @return array<string, mixed>|null
     */
    private static function indexed(?array $spec, array $index): ?array
    {
        if (($spec['kind'] ?? null) === 'list') {
            return self::row($spec);
        }

        return ($index['type'] ?? null) === 'StringLit' ? self::field($spec, (string) ($index['value'] ?? '')) : null;
    }
}
