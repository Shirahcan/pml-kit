<?php

namespace Shirahcan\Pml;

use RuntimeException;

/**
 * The ONE definition of PML's elements (PML v3 L1, owner 2026-11-10): every element's props and their
 * types, which props are visible words (scanned for compliance), what an element may contain and where
 * it may sit. Read from `resources/pml/elements.json`; the frontend gets a generated copy
 * (`php artisan pml:export-elements`) so the two sides cannot drift.
 *
 * Prop types: `prose` (visible words), `string` (machine text), `enum` (`values`: a list, or "@name"
 * into the file's `enums`; `number: true` stores a whole number), `bool`, `url`, `email`, `date`,
 * `anchor`, `pattern` (a named id shape PrimitiveSchema owns), `doc` (rich text), `program` (PSL).
 * Containment: `children` lists the only child elements allowed (absent = any); `parent` lists the
 * only parents allowed (one = directly inside it; several = inside one of them).
 * Inline content (PML v3 L4): `"category": "inline"` marks a run-level element (strong, link, code...)
 * and `"holds": "inline"` an element whose content is words (`p` and the inline containers). They are
 * resolved here into the same `children` / `parent` lists, so every reader (the compiler, the TS export,
 * the builder's drag rules) keeps reading one shape: a holder holds text runs and inline elements only,
 * and an inline element sits only inside a holder.
 * A prop marked `"compiled": true` is made by the compiler from another prop (rich text's `doc` from its
 * `markdown`); it is never authored, stored or written back.
 */
final class ElementCatalog
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** The definitions file, found without the framework so plain unit tests can read it. */
    public static function path(): string
    {
        return dirname(__DIR__, 1).'/resources/pml/elements.json';
    }

    /**
     * @return array{version: int, component_prose: list<string>, enums: array<string, list<string>>, elements: array<string, array<string, mixed>>}
     */
    public static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }

        $raw = @file_get_contents(self::path());
        $data = is_string($raw) ? json_decode($raw, true) : null;

        if (! is_array($data) || ! is_array($data['elements'] ?? null) || ! is_array($data['enums'] ?? null)) {
            throw new RuntimeException('resources/pml/elements.json is missing or is not valid JSON with "elements" and "enums".');
        }

        foreach ($data['elements'] as $name => $element) {
            if (! is_array($element) || ! is_bool($element['container'] ?? null) || ! is_array($element['props'] ?? null)) {
                throw new RuntimeException("resources/pml/elements.json: \"{$name}\" needs \"container\" (true/false) and \"props\".");
            }
        }

        $data['component_prose'] = array_values((array) ($data['component_prose'] ?? []));
        $data['elements'] = self::resolveInline($data['elements']);

        return self::$data = $data;
    }

    /**
     * `holds: inline` -> `children` (text runs + every inline element); `category: inline` -> `parent`
     * (every holder). Written into the lists the rest of the platform already reads.
     *
     * @param  array<string, array<string, mixed>>  $elements
     * @return array<string, array<string, mixed>>
     */
    private static function resolveInline(array $elements): array
    {
        $inline = array_keys(array_filter($elements, fn (array $el) => ($el['category'] ?? null) === 'inline'));
        $holders = array_keys(array_filter($elements, fn (array $el) => ($el['holds'] ?? null) === 'inline'));

        foreach ($elements as $name => $el) {
            if (($el['holds'] ?? null) === 'inline') {
                $elements[$name]['children'] = ['text', ...$inline];
            }

            if (($el['category'] ?? null) === 'inline') {
                $elements[$name]['parent'] = $holders;
            }
        }

        return $elements;
    }

    /** @return list<string> the inline elements (strong, link, code...), in the file's order */
    public static function inline(): array
    {
        return array_keys(array_filter(self::data()['elements'], fn (array $el) => ($el['category'] ?? null) === 'inline'));
    }

    /** Whether this element's content is words: text runs and inline elements (`p`, `strong`...). */
    public static function holdsInline(string $element): bool
    {
        return (self::data()['elements'][$element]['holds'] ?? null) === 'inline';
    }

    /** Whether the compiler makes this prop (never authored, stored or written back). */
    public static function isCompiled(string $element, string $key): bool
    {
        return (self::prop($element, $key)['compiled'] ?? false) === true;
    }

    /** @return list<string> every element name, in the file's order */
    public static function names(): array
    {
        return array_keys(self::data()['elements']);
    }

    /** @return list<string> the elements that may hold children */
    public static function containers(): array
    {
        return array_keys(array_filter(self::data()['elements'], fn (array $el) => $el['container'] === true));
    }

    public static function has(string $element): bool
    {
        return isset(self::data()['elements'][$element]);
    }

    public static function isContainer(string $element): bool
    {
        return (self::data()['elements'][$element]['container'] ?? false) === true;
    }

    /** @return array<string, array<string, mixed>>|null prop name => definition, or null for an unknown element */
    public static function props(string $element): ?array
    {
        $el = self::data()['elements'][$element] ?? null;

        return $el === null ? null : $el['props'];
    }

    /** @return list<string>|null */
    public static function propNames(string $element): ?array
    {
        $props = self::props($element);

        return $props === null ? null : array_keys($props);
    }

    /** @return array<string, mixed>|null */
    public static function prop(string $element, string $key): ?array
    {
        $def = self::props($element)[$key] ?? null;

        return is_array($def) ? $def : null;
    }

    /**
     * An enum definition's allowed values ("@name" resolved against the file's enums).
     *
     * @param  array<string, mixed>  $def
     * @return list<string>
     */
    public static function enumValues(array $def): array
    {
        $values = $def['values'] ?? [];

        if (is_string($values) && str_starts_with($values, '@')) {
            $values = self::data()['enums'][substr($values, 1)] ?? null;

            if (! is_array($values)) {
                throw new RuntimeException("resources/pml/elements.json: enum \"{$def['values']}\" is not defined.");
            }
        }

        return array_values(array_map('strval', (array) $values));
    }

    /** @return list<string> a named shared list (e.g. "icon") */
    public static function enum(string $name): array
    {
        return array_values((array) (self::data()['enums'][$name] ?? []));
    }

    /** @return list<string>|null the only child elements allowed, or null when any element may go inside */
    public static function childrenOf(string $element): ?array
    {
        $children = self::data()['elements'][$element]['children'] ?? null;

        return is_array($children) ? array_values($children) : null;
    }

    /** @return list<string>|null the only parents allowed, or null when it may sit anywhere */
    public static function parentsOf(string $element): ?array
    {
        $parents = self::data()['elements'][$element]['parent'] ?? null;

        return is_array($parents) ? array_values($parents) : null;
    }

    /**
     * Whether a prop holds visible words (scanned for compliance). For an element the file defines, its
     * own declaration decides; for anything else (a component's settings), a key that is prose on ANY
     * element, or one of the file's `component_prose` keys, counts.
     */
    public static function isProse(?string $element, string $key): bool
    {
        if ($element !== null && self::has($element)) {
            return (self::prop($element, $key)['type'] ?? null) === 'prose';
        }

        return in_array($key, self::proseKeys(), true);
    }

    /**
     * A quoted attribute value in its declared type (PML v3 L2: every value is written in double quotes,
     * so "2" is the source form of a heading level). A whole-number enum becomes an int, a yes/no prop
     * becomes a bool when it reads exactly "true" or "false"; everything else stays the string, so the
     * validator still reports a wrong value with its own message.
     */
    public static function coerce(string $element, string $key, string $raw): mixed
    {
        $def = self::prop($element, $key);

        if ($def === null) {
            return $raw;
        }

        if (($def['number'] ?? false) === true && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        if ($def['type'] === 'bool' && ($raw === 'true' || $raw === 'false')) {
            return $raw === 'true';
        }

        return $raw;
    }

    /** @return list<string> every prop name that is prose somewhere, plus the component-only prose keys */
    public static function proseKeys(): array
    {
        $keys = [];

        foreach (self::data()['elements'] as $element) {
            foreach ($element['props'] as $key => $def) {
                if (($def['type'] ?? null) === 'prose') {
                    $keys[$key] = true;
                }
            }
        }

        foreach (self::data()['component_prose'] as $key) {
            $keys[$key] = true;
        }

        return array_keys($keys);
    }
}
