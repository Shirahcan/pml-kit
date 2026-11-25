<?php

namespace Shirahcan\Pml\Tests\Component;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Component\ParamRefused;
use Shirahcan\Pml\Component\ParamSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PmlExpression;

/**
 * A component's typed settings, moved from Portify (shared PML Studio P3c-2, 2026-11-25): the cases of Portify's
 * ComponentPropKindsTest that test the schema alone, unchanged but for the kit's exception.
 */
final class ParamSchemaTest extends TestCase
{
    private function schema(): ParamSchema
    {
        return new ParamSchema();
    }

    public function test_a_refusal_keeps_the_row_it_is_about(): void
    {
        try {
            $this->schema()->normalise([['name' => 'title', 'label' => 'Title'], ['name' => 'look', 'label' => 'Look']]);
            $this->fail('A reserved name is refused.');
        } catch (ParamRefused $e) {
            $this->assertSame('params.1.name', $e->field);
            $this->assertSame('"look" is a reserved word in PML. Choose another setting name.', $e->getMessage());
        }
    }

    private const PLANS = ['name' => 'plans', 'label' => 'Plans', 'kind' => 'list', 'fields' => [
        ['name' => 'name', 'kind' => 'text', 'label' => 'Name'],
        ['name' => 'price', 'kind' => 'number', 'label' => 'Price'],
        ['name' => 'tags', 'kind' => 'list', 'of' => 'text'],
    ], 'default' => '[{name: "Starter", price: 49, tags: ["New"]}]'];

    public function test_scalar_kinds_declare_with_their_defaults(): void
    {
        $params = $this->schema()->normalise([
            ['name' => 'start', 'label' => 'Starts at', 'kind' => 'number', 'default' => ['$expr' => '0']],
            ['name' => 'show_reset', 'label' => 'Show reset', 'kind' => 'yes-no', 'default' => true],
            ['name' => 'intro', 'label' => 'Introduction', 'kind' => 'rich-text', 'default' => 'Pick a **plan**.'],
            ['name' => 'tone', 'label' => 'Background', 'kind' => 'style', 'style_key' => 'background', 'default' => 'surface'],
            ['name' => 'ink', 'label' => 'Colour', 'kind' => 'style', 'style' => 'background-color', 'default' => '#1a73e8'],
        ]);

        $this->assertSame(['0', 'true', 'Pick a **plan**.', 'surface', '#1a73e8'], array_column($params, 'default'));
        $this->assertSame(['number', 'yes_no', 'rich_text', 'style', 'style'], array_column($params, 'kind'));
        $this->assertSame('background_color', $params[4]['style_key']);
    }

    public function test_a_style_setting_refuses_a_style_a_page_cannot_choose_and_a_token_its_style_lacks(): void
    {
        foreach ([
            ['name' => 'p', 'label' => 'P', 'kind' => 'style', 'style_key' => 'padding_top_px'],
            ['name' => 'p', 'label' => 'P', 'kind' => 'style', 'style_key' => 'background', 'default' => 'purple'],
        ] as $raw) {
            try {
                $this->schema()->normalise([$raw]);
                $this->fail('A style setting that cannot work was declared.');
            } catch (ParamRefused) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_number_arrives_as_a_number_and_yes_no_as_true_or_false(): void
    {
        $params = $this->schema()->normalise([
            ['name' => 'start', 'label' => 'Starts at', 'kind' => 'number', 'default' => '0'],
            ['name' => 'show_reset', 'label' => 'Show reset', 'kind' => 'yes_no', 'default' => 'true'],
        ]);

        $this->assertSame(['start' => 3, 'show_reset' => true], $this->schema()->apply('counter', $params, ['start' => '3']));
        $this->assertSame(['start' => 2.5, 'show_reset' => false], $this->schema()->apply('counter', $params, ['start' => 2.5, 'show_reset' => false]));

        $this->expectException(InvalidPmlException::class);
        $this->expectExceptionMessage('Starts at on "counter": Use a number.');
        $this->schema()->apply('counter', $params, ['start' => 'three']);
    }

    public function test_a_list_of_maps_declares_with_a_js_like_default(): void
    {
        $params = $this->schema()->normalise([self::PLANS]);

        $this->assertSame('ListLit', $params[0]['default']['ast']['type']);
        $this->assertSame(['name', 'price', 'tags'], array_column($params[0]['fields'], 'name'));
        $this->assertSame('text', $params[0]['fields'][2]['of']);
    }

    public function test_a_page_value_is_checked_against_the_shape(): void
    {
        $params = $this->schema()->normalise([self::PLANS]);
        $value = fn (string $source) => ['plans' => PmlExpression::of($source)];

        $this->assertSame('ListLit', $this->schema()->apply('pricing', $params, $value('[{name: "Firm", price: 199}]'))['plans']['ast']['type']);
        // Page data is live: its shape is only known in the browser.
        $this->assertSame('Member', $this->schema()->apply('pricing', $params, $value('owner.packages'))['plans']['ast']['type']);

        foreach ([
            '[{name: "Firm", price: "lots"}]' => 'Plans on "pricing", item 1: Price must be a number.',
            '[{name: "Firm", colour: "red"}]' => 'Plans on "pricing", item 1 has no field called "colour". It has: name, price, tags.',
            '[{name: "Firm", tags: [1]}]' => 'Plans on "pricing", item 1: Tags, item 1 must be words, in quotes.',
            '{name: "Firm"}' => 'Plans on "pricing" takes a list, written like [...].',
        ] as $source => $message) {
            try {
                $this->schema()->apply('pricing', $params, $value($source));
                $this->fail("{$source} was accepted.");
            } catch (InvalidPmlException $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_a_map_setting_and_its_sample(): void
    {
        $params = $this->schema()->normalise([[
            'name' => 'contact', 'label' => 'Contact', 'kind' => 'map',
            'fields' => [['name' => 'email', 'kind' => 'text'], ['name' => 'call_back', 'kind' => 'yes_no']],
        ]]);

        $sample = $this->schema()->sampleValues($params)['contact'];

        $this->assertSame('{email: "Email", call_back: true}', $sample['$expr']);
        $this->assertSame($sample['ast'], PmlExpression::of($sample['$expr'])['ast']);
    }

    public function test_lists_and_maps_nest_at_most_three_deep(): void
    {
        $deep = ['name' => 'a', 'label' => 'A', 'kind' => 'map', 'fields' => [['name' => 'b', 'kind' => 'map', 'fields' => [['name' => 'c', 'kind' => 'map', 'fields' => [['name' => 'd', 'kind' => 'map', 'fields' => [['name' => 'e', 'kind' => 'text']]]]]]]]];

        $this->expectException(ParamRefused::class);
        $this->schema()->normalise([$deep]);
    }
}
