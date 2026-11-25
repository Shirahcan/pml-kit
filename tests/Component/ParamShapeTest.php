<?php

namespace Shirahcan\Pml\Tests\Component;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Component\ParamRefused;
use Shirahcan\Pml\Component\ParamShape;
use Shirahcan\Pml\Psl\Ast;

/**
 * The shape of a component setting's value, moved from Portify (shared PML Studio P3c-1, 2026-11-25) with its
 * sentences unchanged. A refusal names WHERE (the field key a form shows it under) as well as why.
 */
final class ParamShapeTest extends TestCase
{
    public function test_a_kind_is_read_with_dashes_or_underscores_and_an_unknown_one_is_refused_where_it_is(): void
    {
        $this->assertSame('long_text', ParamShape::kind('long-text', 'params.0'));

        try {
            ParamShape::kind('colour', 'params.2');
            $this->fail('An unknown kind is refused.');
        } catch (ParamRefused $e) {
            $this->assertSame('params.2.kind', $e->field);
            $this->assertStringStartsWith('Choose a kind: text, long-text, url', $e->getMessage());
        }
    }

    public function test_a_list_of_maps_keeps_its_fields_and_nests_at_most_three_deep(): void
    {
        $extras = ParamShape::extras('list', ['fields' => [['name' => 'Plan-Name', 'kind' => 'text'], ['name' => 'price', 'kind' => 'number']]], 'params.0', 0);
        $this->assertSame([['name' => 'plan_name', 'label' => 'Plan name', 'kind' => 'text'], ['name' => 'price', 'label' => 'Price', 'kind' => 'number']], $extras['fields']);

        $deep = ['kind' => 'list', 'fields' => [['name' => 'a', 'kind' => 'list', 'fields' => [['name' => 'b', 'kind' => 'list', 'fields' => [['name' => 'c', 'kind' => 'map', 'fields' => [['name' => 'd']]]]]]]]];
        try {
            ParamShape::extras('list', $deep, 'params.0', 0);
            $this->fail('A fourth level is refused.');
        } catch (ParamRefused $e) {
            $this->assertSame('Lists and maps nest at most 3 deep.', $e->getMessage());
        }

        try {
            ParamShape::extras('map', ['fields' => [['name' => 'x'], ['name' => 'x']]], 'params.1', 0);
            $this->fail('Two fields with one name are refused.');
        } catch (ParamRefused $e) {
            $this->assertSame('params.1.fields.1.name', $e->field);
            $this->assertSame('Two fields are both named "x".', $e->getMessage());
        }
    }

    public function test_a_choice_needs_two_options(): void
    {
        $this->assertSame(['options' => ['a', 'b']], ParamShape::extras('enum', ['options' => ['a', ' b ', 'a', '']], 'p', 0));

        $this->expectException(ParamRefused::class);
        ParamShape::extras('enum', ['options' => ['only']], 'p', 0);
    }

    public function test_a_literal_value_is_checked_against_its_shape_and_page_data_passes(): void
    {
        $plans = ['kind' => 'list', 'label' => 'Plans', 'fields' => [['name' => 'name', 'label' => 'Name', 'kind' => 'text'], ['name' => 'price', 'label' => 'Price', 'kind' => 'number']]];

        $ok = Ast::listLit([Ast::mapLit([['key' => 'name', 'value' => Ast::str('Starter', 1, 1)], ['key' => 'price', 'value' => Ast::number('49', 1, 1)]], 1, 1)], 1, 1);
        $this->assertNull(ParamShape::problem($plans, $ok, 'Plans'));

        $bad = Ast::listLit([Ast::mapLit([['key' => 'price', 'value' => Ast::str('cheap', 1, 1)]], 1, 1)], 1, 1);
        $this->assertSame('Plans, item 1: Price must be a number.', ParamShape::problem($plans, $bad, 'Plans'));

        $this->assertNull(ParamShape::problem($plans, ['type' => 'Path', 'segments' => ['owner', 'packages']], 'Plans'), 'page data is live');
        $this->assertSame('Use a web address starting with https://, a page path starting with /, or a #section link.', ParamShape::scalarProblem(['kind' => 'url'], 'ftp://x'));
    }

    public function test_a_sample_has_the_right_shape_and_is_written_back_js_like(): void
    {
        $plans = ['kind' => 'list', 'fields' => [['name' => 'name', 'label' => 'Name', 'kind' => 'text'], ['name' => 'price', 'label' => 'Price', 'kind' => 'number']]];

        $this->assertSame('[{name: "Name", price: 1}]', ParamShape::literalText(ParamShape::sampleAst($plans)));
        $this->assertSame('https://example.com', ParamShape::sampleScalar(['kind' => 'url']));
    }
}
