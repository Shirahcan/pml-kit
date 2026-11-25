<?php

namespace Shirahcan\Pml\Component;

use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlSyntaxException;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A component's settings written in its code (component props p03a, 2026-11-11): a `<props>` block at the top
 * of the Code view, the same declarations the visual panel and Porter edit (owner: "do components support a
 * proper props system").
 *
 *   <props>
 *     <prop name="title" kind="text" label="Title" default="Counter"/>
 *     <prop name="start" kind="number" label="Starts at" default={0}/>
 *     <prop name="plans" kind="list" label="Plans" default={[{name: "Starter", price: 49}]}>
 *       <prop name="name" kind="text" label="Name"/>
 *       <prop name="price" kind="number" label="Price"/>
 *     </prop>
 *   </props>
 *
 * A list's or map's fields are `<prop>`s inside it (`<field>` is the form element). The stored settings stay
 * the authority ({@see ParamSchema}); this block is how they read and write as code.
 *
 * Moved from Portify (shared PML Studio P3c-3, 2026-11-25), unchanged but for the exception it reads.
 */
final class PropsBlock
{
    private const BLOCK = '/\A\s*(<props\b[^>]*\/>|<props\b.*?<\/props>)\s*/s';

    public function __construct(private readonly PmlParser $parser, private readonly ParamSchema $schema)
    {
    }

    /**
     * Split a component's code into its settings and its design. No block, no settings.
     *
     * @return array{params: list<array<string, mixed>>, body: string}
     *
     * @throws PmlSyntaxException  a located fault in the block (an unknown kind, a duplicate name...)
     */
    public function read(string $source): array
    {
        if (preg_match(self::BLOCK, $source, $m) !== 1) {
            return ['params' => [], 'body' => $source];
        }

        $block = $m[1];
        $body = substr($source, strlen($m[0]));
        $parsed = $this->parser->parseWithSpans($block);
        $root = $parsed['nodes'][0] ?? [];
        $spans = $parsed['spans'];
        $raw = [];

        foreach (array_values((array) ($root['children'] ?? [])) as $i => $child) {
            $raw[] = $this->declaration($child, $spans, "nodes/0/children/{$i}");
        }

        try {
            return ['params' => $this->schema->normalise($raw), 'body' => $body];
        } catch (ParamRefused $e) {
            [$key, $messages] = [$e->field, [$e->getMessage()]];
            // params.<i>.<...>: point at that setting's line.
            $index = preg_match('/^params\.(\d+)/', (string) $key, $k) === 1 ? (int) $k[1] : null;
            $span = $index !== null ? ($spans["nodes/0/children/{$index}"] ?? null) : null;
            $name = $index !== null ? ($raw[$index]['name'] ?? null) : null;

            throw new PmlSyntaxException(
                ($name ? "In <props>, \"{$name}\": " : 'In <props>: ').($messages[0] ?? 'This setting is not valid.'),
                (int) ($span['line'] ?? 1),
                (int) ($span['col'] ?? 1),
            );
        }
    }

    /**
     * The block for these settings, ready to sit above the design ('' for none).
     *
     * @param  list<array<string, mixed>>  $params
     */
    public function write(array $params): string
    {
        if ($params === []) {
            return '';
        }

        $lines = ['<props>'];

        foreach ($params as $param) {
            array_push($lines, ...$this->propLines($param, '  ', true));
        }

        $lines[] = '</props>';

        return implode("\n", $lines);
    }

    /**
     * @param  mixed  $node
     * @param  array<string, array{line: int, col: int}>  $spans
     * @return array<string, mixed>
     *
     * @throws PmlSyntaxException
     */
    private function declaration(mixed $node, array $spans, string $path): array
    {
        $span = $spans[$path] ?? ['line' => 1, 'col' => 1];

        if (! is_array($node) || ($node['component'] ?? null) !== 'prop') {
            throw new PmlSyntaxException('Only <prop> elements go inside <props>, like <prop name="title" kind="text" label="Title"/>.', (int) $span['line'], (int) $span['col']);
        }

        $props = (array) ($node['props'] ?? []);
        $raw = [
            'name' => $props['name'] ?? '',
            'label' => $props['label'] ?? '',
            'kind' => $props['kind'] ?? 'text',
            'default' => $props['default'] ?? null,
            'required' => ($props['required'] ?? false) === true,
            'help' => $props['help'] ?? null,
        ];

        foreach (['style' => 'style_key', 'of' => 'of'] as $attr => $key) {
            if (isset($props[$attr])) {
                $raw[$key] = $props[$attr];
            }
        }

        if (isset($props['options'])) {
            $raw['options'] = explode('|', (string) $props['options']);
        }

        $children = array_values((array) ($node['children'] ?? []));

        if ($children !== []) {
            $raw['fields'] = [];

            foreach ($children as $i => $child) {
                $raw['fields'][] = $this->declaration($child, $spans, "{$path}/children/{$i}");
            }
        }

        return $raw;
    }

    /**
     * @param  array<string, mixed>  $param
     * @return list<string>
     */
    private function propLines(array $param, string $pad, bool $top): array
    {
        $attrs = ['name="'.$param['name'].'"', 'kind="'.str_replace('_', '-', (string) $param['kind']).'"'];

        if (isset($param['label']) && $param['label'] !== '') {
            $attrs[] = 'label='.self::quoted((string) $param['label']);
        }
        if (isset($param['style_key'])) {
            $attrs[] = 'style="'.str_replace('_', '-', (string) $param['style_key']).'"';
        }
        if (isset($param['of'])) {
            $attrs[] = 'of="'.str_replace('_', '-', (string) $param['of']).'"';
        }
        if (($param['options'] ?? []) !== []) {
            $attrs[] = 'options='.self::quoted(implode('|', $param['options']));
        }
        if ($top && ($param['required'] ?? false)) {
            $attrs[] = 'required';
        }
        if ($top && ($param['default'] ?? null) !== null) {
            $attrs[] = 'default='.$this->defaultText($param);
        }
        if ($top && ($param['help'] ?? null) !== null) {
            $attrs[] = 'help='.self::quoted((string) $param['help']);
        }

        $open = $pad.'<prop '.implode(' ', $attrs);
        $fields = (array) ($param['fields'] ?? []);

        if ($fields === []) {
            return [$open.'/>'];
        }

        $lines = [$open.'>'];

        foreach ($fields as $field) {
            array_push($lines, ...$this->propLines($field, $pad.'  ', false));
        }

        $lines[] = $pad.'</prop>';

        return $lines;
    }

    /** @param  array<string, mixed>  $param */
    private function defaultText(array $param): string
    {
        $default = $param['default'];

        if (PrimitiveSchema::isExpression($default)) {
            return '{'.$default['$expr'].'}';
        }

        return match ($param['kind']) {
            'number', 'yes_no' => '{'.$default.'}',
            default => self::quoted((string) $default),
        };
    }

    private static function quoted(string $value): string
    {
        return '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';
    }
}
