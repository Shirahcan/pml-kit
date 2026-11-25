<?php

namespace Shirahcan\Pml\Component;

use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\Psl\Ast;

/**
 * The shape of a component setting's value (component props p02a/p02b, 2026-11-11): what each kind may hold,
 * and the fields of a list or a map, nested up to three deep. Values are written JS-like, as PSL literals
 * (`49`, `true`, `"Starter"`, `[a, b]`, `{name: "Starter", price: 49}`), so a value is checked by reading its
 * literal tree. A value that is page data rather than a literal (`owner.packages`) is live and passes: its
 * shape is only known in the browser.
 */
final class ParamShape
{
    /** Kinds that hold one value. */
    public const SCALAR = ['text', 'long_text', 'url', 'image', 'enum', 'number', 'yes_no', 'rich_text', 'style'];

    /** Kinds that hold many: a list of values or maps, a map of named fields. */
    public const COLLECTION = ['list', 'map'];

    public const MAX_DEPTH = 3;

    private const NAME = '/^[a-z][a-z0-9_]{0,39}$/';

    private const MAX_LENGTH = ['text' => 300, 'long_text' => 2000, 'url' => 2048, 'image' => 2048, 'enum' => 60, 'number' => 30, 'yes_no' => 5, 'rich_text' => 5000, 'style' => 60];

    /**
     * A list's item, a list's or map's fields: the parts every kind but the simplest carries.
     *
     * @param  array<string, mixed>  $raw  the declaration (a setting or a field)
     * @return array<string, mixed>  the kind's extra keys: options, style_key, of, fields
     *
     * @throws ParamRefused
     */
    public static function extras(string $kind, array $raw, string $at, int $depth): array
    {
        return match ($kind) {
            'enum' => ['options' => self::options($raw, $at)],
            'style' => ['style_key' => self::styleKey($raw, $at)],
            'list' => self::listShape($raw, $at, $depth),
            'map' => ['fields' => self::fields($raw['fields'] ?? null, $at, $depth)],
            default => [],
        };
    }

    /**
     * What is wrong with one plain value for a scalar kind, or null.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function scalarProblem(array $spec, string $value): ?string
    {
        $kind = (string) $spec['kind'];

        if (mb_strlen($value) > (self::MAX_LENGTH[$kind] ?? 300)) {
            return 'This is longer than '.self::MAX_LENGTH[$kind].' characters.';
        }

        return match ($kind) {
            'enum' => in_array($value, $spec['options'] ?? [], true) ? null : 'Choose one of: '.implode(', ', $spec['options'] ?? []).'.',
            'url' => preg_match('#^(https?://|/(?!/)|\#|mailto:|tel:)\S*$#i', $value) === 1
                ? null
                : 'Use a web address starting with https://, a page path starting with /, or a #section link.',
            'image' => preg_match('#^(https://|/(?!/))\S*$#i', $value) === 1 ? null : 'Use an image address starting with https:// or /.',
            'number' => is_numeric($value) && is_finite((float) $value) ? null : 'Use a number.',
            'yes_no' => in_array($value, ['true', 'false'], true) ? null : 'Use true or false.',
            'style' => self::styleProblem((string) ($spec['style_key'] ?? ''), $value),
            default => null,
        };
    }

    /**
     * What is wrong with a value's literal tree for this setting or field, or null. Anything that is not a
     * literal (page data, a calculation) is live and passes.
     *
     * @param  array<string, mixed>  $spec
     * @param  array<string, mixed>  $ast
     */
    public static function problem(array $spec, array $ast, string $where): ?string
    {
        $kind = (string) $spec['kind'];
        $type = (string) ($ast['type'] ?? '');

        if (! in_array($type, ['StringLit', 'NumberLit', 'BoolLit', 'NullLit', 'ListLit', 'MapLit'], true) || $type === 'NullLit') {
            return null;
        }

        if ($kind === 'list') {
            if ($type !== 'ListLit') {
                return "{$where} takes a list, written like [...].";
            }

            $item = isset($spec['fields']) ? ['kind' => 'map', 'fields' => $spec['fields']] : ['kind' => (string) ($spec['of'] ?? 'text')];

            foreach (array_values((array) ($ast['elements'] ?? [])) as $i => $element) {
                $problem = is_array($element) ? self::problem($item, $element, "{$where}, item ".($i + 1)) : null;

                if ($problem !== null) {
                    return $problem;
                }
            }

            return null;
        }

        if ($kind === 'map') {
            if ($type !== 'MapLit') {
                return "{$where} takes named values, written like {name: \"...\"}.";
            }

            $fields = [];
            foreach ((array) ($spec['fields'] ?? []) as $field) {
                $fields[(string) $field['name']] = $field;
            }

            foreach ((array) ($ast['entries'] ?? []) as $entry) {
                $key = (string) ($entry['key'] ?? '');

                if (! isset($fields[$key])) {
                    return "{$where} has no field called \"{$key}\". It has: ".implode(', ', array_keys($fields)).'.';
                }

                $problem = is_array($entry['value'] ?? null) ? self::problem($fields[$key], $entry['value'], "{$where}: {$fields[$key]['label']}") : null;

                if ($problem !== null) {
                    return $problem;
                }
            }

            return null;
        }

        $expected = match ($kind) {
            'number' => 'NumberLit',
            'yes_no' => 'BoolLit',
            default => 'StringLit',
        };

        if ($type !== $expected) {
            return "{$where} ".match ($expected) {
                'NumberLit' => 'must be a number.',
                'BoolLit' => 'must be true or false.',
                default => 'must be words, in quotes.',
            };
        }

        $value = $type === 'BoolLit' ? ($ast['value'] ? 'true' : 'false') : (string) ($ast['value'] ?? '');
        $problem = self::scalarProblem($spec, $value);

        return $problem === null ? null : "{$where}: {$problem}";
    }

    /**
     * A value of the right shape, for a publish check and the preview: a list of one sample item, a map of
     * sample fields.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>  a literal tree
     */
    public static function sampleAst(array $spec): array
    {
        return match ((string) $spec['kind']) {
            'list' => Ast::listLit([self::sampleAst(isset($spec['fields']) ? ['kind' => 'map', 'fields' => $spec['fields']] : ['kind' => (string) ($spec['of'] ?? 'text'), 'label' => 'Item'])], 1, 1),
            'map' => Ast::mapLit(array_map(fn (array $field) => ['key' => (string) $field['name'], 'value' => self::sampleAst($field)], (array) ($spec['fields'] ?? [])), 1, 1),
            'number' => Ast::number('1', 1, 1),
            'yes_no' => Ast::boolean(true, 1, 1),
            default => Ast::str(self::sampleScalar($spec), 1, 1),
        };
    }

    /**
     * A literal tree written back as JS-like PSL text (`[{name: "Starter", price: 49}]`): the text a list or
     * map value is stored and shown as. Only literals; anything else is written as null.
     *
     * @param  array<string, mixed>  $ast
     */
    public static function literalText(array $ast): string
    {
        return match ($ast['type'] ?? null) {
            'StringLit' => '"'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], (string) $ast['value']).'"',
            'NumberLit' => (string) $ast['value'],
            'BoolLit' => $ast['value'] ? 'true' : 'false',
            'ListLit' => '['.implode(', ', array_map(fn ($e) => self::literalText((array) $e), (array) ($ast['elements'] ?? []))).']',
            'MapLit' => '{'.implode(', ', array_map(fn ($e) => $e['key'].': '.self::literalText((array) $e['value']), (array) ($ast['entries'] ?? []))).'}',
            default => 'null',
        };
    }

    /** @param  array<string, mixed>  $spec */
    public static function sampleScalar(array $spec): string
    {
        return match ((string) $spec['kind']) {
            'url' => 'https://example.com',
            'image' => 'https://example.com/sample.jpg',
            'enum' => (string) ($spec['options'][0] ?? ''),
            'number' => '1',
            'yes_no' => 'true',
            'style' => self::styleSample((string) ($spec['style_key'] ?? '')),
            default => (string) ($spec['label'] ?? 'Sample'),
        };
    }

    /**
     * @param  mixed  $raw
     * @return list<array<string, mixed>>
     *
     * @throws ParamRefused
     */
    private static function fields(mixed $raw, string $at, int $depth): array
    {
        if ($depth >= self::MAX_DEPTH) {
            throw ParamRefused::at($at, 'Lists and maps nest at most '.self::MAX_DEPTH.' deep.');
        }

        if (! is_array($raw) || ! array_is_list($raw) || $raw === []) {
            throw ParamRefused::at("{$at}.fields", 'Name at least one field.');
        }

        $out = [];
        $seen = [];

        foreach ($raw as $i => $field) {
            $where = "{$at}.fields.{$i}";
            $field = is_array($field) ? $field : [];
            $name = strtolower(str_replace('-', '_', trim((string) ($field['name'] ?? ''))));

            if (preg_match(self::NAME, $name) !== 1) {
                throw ParamRefused::at("{$where}.name", 'A field name starts with a letter and uses only lowercase letters, numbers and underscores.');
            }
            if (isset($seen[$name])) {
                throw ParamRefused::at("{$where}.name", "Two fields are both named \"{$name}\".");
            }
            $seen[$name] = true;

            $kind = self::kind($field['kind'] ?? 'text', $where);
            $label = trim((string) ($field['label'] ?? '')) ?: ucfirst(str_replace('_', ' ', $name));

            $out[] = ['name' => $name, 'label' => mb_substr($label, 0, 80), 'kind' => $kind, ...self::extras($kind, $field, $where, $depth + 1)];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return array{of?: string, fields?: list<array<string, mixed>>}
     *
     * @throws ParamRefused
     */
    private static function listShape(array $raw, string $at, int $depth): array
    {
        if (isset($raw['fields'])) {
            return ['fields' => self::fields($raw['fields'], $at, $depth)];
        }

        $of = str_replace('-', '_', (string) ($raw['of'] ?? 'text'));

        if (! in_array($of, ['text', 'long_text', 'url', 'image', 'number', 'yes_no'], true)) {
            throw ParamRefused::at("{$at}.of", 'A list holds text, long text, links, images, numbers or yes/no values, or maps with fields.');
        }

        return ['of' => $of];
    }

    /** @throws ParamRefused */
    public static function kind(mixed $raw, string $at): string
    {
        $kind = str_replace('-', '_', (string) $raw);

        if (! in_array($kind, [...self::SCALAR, ...self::COLLECTION], true)) {
            throw ParamRefused::at("{$at}.kind", 'Choose a kind: '.implode(', ', array_map(fn ($k) => str_replace('_', '-', $k), [...self::SCALAR, ...self::COLLECTION])).'.');
        }

        return $kind;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<string>
     *
     * @throws ParamRefused
     */
    private static function options(array $raw, string $at): array
    {
        $options = array_values(array_unique(array_filter(
            array_map(fn ($o) => trim((string) $o), is_array($raw['options'] ?? null) ? $raw['options'] : []),
            fn ($o) => $o !== '',
        )));

        if (count($options) < 2) {
            throw ParamRefused::at("{$at}.options", 'A choice setting needs at least two options.');
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws ParamRefused
     */
    private static function styleKey(array $raw, string $at): string
    {
        $key = str_replace('-', '_', trim((string) ($raw['style_key'] ?? $raw['style'] ?? '')));

        if (PrimitiveSchema::styleChoices($key) === null) {
            throw ParamRefused::at("{$at}.style_key", 'A style setting names the style a page may change, such as background, gap or text-color.');
        }

        return $key;
    }

    private static function styleProblem(string $key, string $value): ?string
    {
        if (PrimitiveSchema::isColourStyle($key)) {
            return preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? null : 'Use a colour like #1a73e8.';
        }

        $choices = PrimitiveSchema::styleChoices($key) ?? [];

        return in_array($value, $choices, true) ? null : 'Choose one of: '.implode(', ', $choices).'.';
    }

    private static function styleSample(string $key): string
    {
        return PrimitiveSchema::isColourStyle($key) ? '#1a73e8' : (string) ((PrimitiveSchema::styleChoices($key) ?? [''])[0]);
    }
}
