<?php

namespace Shirahcan\Pml\Component;

use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PmlExpression;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\Psl\PslException;

/**
 * Moved from Portify's StudioParamSchema (shared PML Studio P3c-2, 2026-11-25), unchanged but for the exception.
 *
 * The typed settings ("props") a Studio component declares (PML v2 S3-02; component props 2026-11-11), and
 * the one place their rules live.
 *
 * A setting is what the builder's rail shows for a component: a name the design reads as `{props.name}`, a
 * label, a KIND that picks the field, and a default. Kinds: text, long text, link, image, choice, number,
 * yes/no, rich text, style (a style the page may change), list and map. A list or a map holds JS-like values
 * (`[{name: "Starter", price: 49}]`), read in the design with `each={plan in props.plans}`. Shapes live in
 * {@see ParamShape}.
 *
 * Every value still re-enters the PML gate after substitution, so a kind is the builder's contract and an
 * early, readable refusal; it is never the security boundary.
 */
final class ParamSchema
{
    public const KINDS = [...ParamShape::SCALAR, ...ParamShape::COLLECTION];

    /** Props a component tag carries that are NOT params: the pinned version and the chosen look. */
    public const RESERVED = ['version', 'look'];

    public const MAX_PARAMS = 20;

    private const NAME = '/^[a-z][a-z0-9_]{0,39}$/';

    /**
     * Validate and normalise a declared setting list. Field errors are keyed `params.<i>.<field>` so the
     * Studio editor can point at the exact row.
     *
     * @param  mixed  $params
     * @return list<array<string, mixed>>
     *
     * @throws ParamRefused
     */
    public function normalise(mixed $params): array
    {
        if ($params === null) {
            return [];
        }

        if (! is_array($params) || ! array_is_list($params)) {
            throw ParamRefused::at('params', 'Params must be a list.');
        }

        if (count($params) > self::MAX_PARAMS) {
            throw ParamRefused::at('params', 'A component can have at most '.self::MAX_PARAMS.' settings.');
        }

        $styleKeys = PrimitiveSchema::styleKeys();
        $seen = [];
        $out = [];

        foreach ($params as $i => $raw) {
            $at = "params.{$i}";

            if (! is_array($raw)) {
                throw ParamRefused::at($at, 'Each setting must be an object.');
            }

            $name = strtolower(str_replace('-', '_', trim((string) ($raw['name'] ?? ''))));
            if (preg_match(self::NAME, $name) !== 1) {
                throw ParamRefused::at("{$at}.name", 'A setting name starts with a letter and uses only lowercase letters, numbers and underscores.');
            }
            // A style token on a tag is read as STYLE, never a prop, so a param named `align` would never arrive.
            if (in_array($name, self::RESERVED, true) || in_array($name, $styleKeys, true)) {
                throw ParamRefused::at("{$at}.name", "\"{$name}\" is a reserved word in PML. Choose another setting name.");
            }
            if (isset($seen[$name])) {
                throw ParamRefused::at("{$at}.name", "Two settings are both named \"{$name}\".");
            }
            $seen[$name] = true;

            $kind = ParamShape::kind($raw['kind'] ?? 'text', $at);

            $label = trim((string) ($raw['label'] ?? ''));
            if ($label === '' || mb_strlen($label) > 80) {
                throw ParamRefused::at("{$at}.label", 'Give the setting a label of up to 80 characters.');
            }

            $param = ['name' => $name, 'label' => $label, 'kind' => $kind, 'options' => [], ...ParamShape::extras($kind, $raw, $at, 0)];
            $default = $this->defaultOf($param, $raw['default'] ?? null, "{$at}.default");
            $required = (bool) ($raw['required'] ?? false);

            // A required setting has a default, so a freshly inserted component is already valid.
            if ($required && $default === null) {
                throw ParamRefused::at("{$at}.default", 'A required setting needs a default value.');
            }

            $help = trim((string) ($raw['help'] ?? ''));

            $out[] = [...$param, 'required' => $required, 'default' => $default, 'help' => $help === '' ? null : mb_substr($help, 0, 200)];
        }

        return $out;
    }

    /**
     * The value each setting takes when a component is checked at publish and in the preview: its default,
     * else a value of the right shape. Publishing compiles the body with these so a broken template never ships.
     *
     * @param  list<array<string, mixed>>  $params
     * @return array<string, mixed>
     */
    public function sampleValues(array $params): array
    {
        $values = [];

        foreach ($params as $param) {
            if (in_array($param['kind'], ParamShape::COLLECTION, true)) {
                // The sample's tree is the one its own text gives (one parser, p01).
                $values[$param['name']] = $param['default'] ?? PmlExpression::of(ParamShape::literalText(ParamShape::sampleAst($param)));

                continue;
            }

            $values[$param['name']] = $param['default'] ?? ParamShape::sampleScalar($param);
        }

        return $values;
    }

    /**
     * Fill and check the values a page passes to a component: absent settings take their default, a value
     * of the wrong kind or shape is refused with the setting's own label, a number arrives as a number and
     * yes/no as true or false, and the reserved props are removed so they never reach the template.
     *
     * @param  list<array<string, mixed>>  $params
     * @param  array<string, mixed>  $props
     * @return array<string, mixed>
     *
     * @throws InvalidPmlException
     */
    public function apply(string $componentName, array $params, array $props): array
    {
        foreach (self::RESERVED as $reserved) {
            unset($props[$reserved]);
        }

        foreach ($params as $param) {
            $name = $param['name'];
            $value = $props[$name] ?? null;
            $where = "{$param['label']} on \"{$componentName}\"";

            if ($value === null || $value === '') {
                $value = $param['default'];
            }

            if ($value === null || $value === '') {
                if ($param['required']) {
                    throw new InvalidPmlException("\"{$componentName}\" needs a value for {$param['label']}.", "props.{$name}");
                }
                unset($props[$name]);

                continue;
            }

            // A JS-like value or page data: a literal is checked against the shape, page data passes.
            if (PrimitiveSchema::isExpression($value)) {
                $problem = is_array($value['ast'] ?? null) ? ParamShape::problem($param, $value['ast'], $where) : null;

                if ($problem !== null) {
                    throw new InvalidPmlException($problem, "props.{$name}");
                }

                $props[$name] = $value;

                continue;
            }

            if (in_array($param['kind'], ParamShape::COLLECTION, true) || ! is_scalar($value)) {
                throw new InvalidPmlException("{$where} takes ".($param['kind'] === 'map' ? 'named values, written like {name: "..."}' : 'a list, written like [...]').'.', "props.{$name}");
            }

            $text = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            $problem = ParamShape::scalarProblem($param, $text);

            if ($problem !== null) {
                throw new InvalidPmlException("{$where}: {$problem}", "props.{$name}");
            }

            $props[$name] = match ($param['kind']) {
                'number' => $text + 0,
                'yes_no' => $text === 'true',
                default => $text,
            };
        }

        return $props;
    }

    /**
     * A declared default: a scalar as text, a list or map as its JS-like expression.
     *
     * @param  array<string, mixed>  $param
     * @return string|array{"$expr": string, ast: array<string, mixed>}|null
     *
     * @throws ParamRefused
     */
    private function defaultOf(array $param, mixed $raw, string $at): string|array|null
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }

        if (in_array($param['kind'], ParamShape::COLLECTION, true)) {
            $source = PrimitiveSchema::isExpression($raw) ? (string) $raw['$expr'] : (is_string($raw) ? $raw : null);

            try {
                $expr = $source !== null ? PmlExpression::of($source) : null;
            } catch (PslException) {
                $expr = null;
            }

            $type = $expr['ast']['type'] ?? null;
            $problem = $expr === null || ! in_array($type, ['ListLit', 'MapLit'], true)
                ? ($param['kind'] === 'map' ? 'Write the default as named values, like {name: "Starter"}.' : 'Write the default as a list, like [{name: "Starter"}].')
                : ParamShape::problem($param, $expr['ast'], 'The default');

            if ($problem !== null) {
                throw ParamRefused::at($at, $problem);
            }

            return $expr;
        }

        if (PrimitiveSchema::isExpression($raw)) {
            // A JS-like default for a scalar (`default={49}`, `default={true}`, `default={"Hi"}`) is its literal.
            try {
                $ast = is_array($raw['ast'] ?? null) ? $raw['ast'] : PmlExpression::of((string) $raw['$expr'])['ast'];
            } catch (PslException) {
                $ast = [];
            }

            $text = match ($ast['type'] ?? null) {
                'StringLit', 'NumberLit' => (string) $ast['value'],
                'BoolLit' => $ast['value'] ? 'true' : 'false',
                default => throw ParamRefused::at($at, 'A default is a plain value, like "Hello", 49 or true.'),
            };
        } else {
            $text = is_bool($raw) ? ($raw ? 'true' : 'false') : (string) $raw;
        }

        $problem = ParamShape::scalarProblem($param, $text);

        if ($problem !== null) {
            throw ParamRefused::at($at, $problem);
        }

        return $text;
    }
}
