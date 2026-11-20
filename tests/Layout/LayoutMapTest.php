<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * The document's map (0.33.0): every part instance with its state now, so a writer (Porter) can tell "the FAQ is still
 * the template's examples" from "the FAQ is written" without reading the PML (owner 2026-11-20: a console ask said the
 * FAQ was not populated and Porter could not see which parts were).
 */
class LayoutMapTest extends TestCase
{
    private function layout(): LayoutDefinition
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/layouts/post.full-guide.json'), true);

        return LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));
    }

    /** @return list<array<string, mixed>> the entries of one part, in order */
    private static function of(array $map, string $part): array
    {
        return array_values(array_filter($map, static fn (array $e) => $e['part'] === $part));
    }

    /** Every bracketed example in a node replaced by plain words (a part written by its author). */
    private static function written(array $node, string $words): array
    {
        foreach ((array) ($node['props'] ?? []) as $key => $value) {
            if (is_string($value) && preg_match('/^\s*\[.*\]\s*$/s', $value)) {
                $node['props'][$key] = $words;
            }
        }
        foreach ((array) ($node['children'] ?? []) as $i => $child) {
            if (is_array($child)) {
                $node['children'][$i] = self::written($child, $words);
            }
        }

        return $node;
    }

    public function test_a_fresh_document_maps_every_part_in_order_with_its_state(): void
    {
        $layout = $this->layout();
        $map = LayoutChecks::map($layout, $layout->starter());

        $this->assertSame(array_column($layout->parts, 'key'), array_values(array_unique(array_column($map, 'part'))), 'every part, in the layout\'s order');
        $this->assertSame('drawn', self::of($map, 'header')[0]['state'], 'the header draws the post\'s details');
        $this->assertSame('example words', self::of($map, 'hook')[0]['state']);
        $this->assertNotSame([], self::of($map, 'hook')[0]['examples_left']);
        $this->assertIsString(self::of($map, 'hook')[0]['purpose'], 'the template note, when the layout has one');

        $sections = self::of($map, 'section');
        $this->assertCount($layout->part('section')['min'], $sections, 'a repeat as many times as its minimum');
        $this->assertSame(['min' => $layout->part('section')['min'], 'max' => $layout->part('section')['max']], $sections[0]['bounds']);
        $this->assertSame([1, count($sections)], [$sections[0]['instance'], $sections[0]['of']]);
        $this->assertArrayHasKey('purpose', $sections[0]);
        $this->assertArrayNotHasKey('purpose', $sections[1], 'the purpose rides on the first instance only');
    }

    public function test_a_written_part_a_half_written_one_a_missing_one_and_a_pinned_check(): void
    {
        $layout = $this->layout();
        $nodes = $layout->starter();
        $at = static fn (string $part, int $nth = 0) => array_keys(array_filter($nodes, static fn ($n) => is_array($n) && ($n['part'] ?? null) === $part))[$nth];

        $nodes[$at('hook')] = self::written($nodes[$at('hook')], 'Most people with a job offer and a positive LMIA can apply.');
        // The first question section: its heading written, its answers still the template's examples.
        $first = $at('section');
        $nodes[$first]['uid'] = 'sec1';
        $nodes[$first]['children'][0] = self::written($nodes[$first]['children'][0], 'What is an LMIA?');
        // A check pinned to the SECOND question section, as the prepare pins one.
        $second = $at('section', 1);
        $nodes[$second]['uid'] = 'sec2';
        array_splice($nodes, $second, 0, [['comment' => 'VERIFY: the 1,000 CAD fee against the ESDC page.', 'note' => ['for' => 'sec2', 'author' => 'Porter']]]);
        $nodes = array_values(array_filter($nodes, static fn ($n) => ! is_array($n) || ($n['part'] ?? null) !== 'faq'));

        $map = LayoutChecks::map($layout, $nodes);

        $this->assertSame('written', self::of($map, 'hook')[0]['state']);
        $this->assertGreaterThan(5, self::of($map, 'hook')[0]['words']);
        $sections = self::of($map, 'section');
        $this->assertSame(['partly written', 'sec1'], [$sections[0]['state'], $sections[0]['uid']]);
        $this->assertSame([], $sections[0]['checks']);
        $this->assertSame(['VERIFY: the 1,000 CAD fee against the ESDC page.'], $sections[1]['checks'], 'the check belongs to the instance it is pinned to');
        $this->assertSame('missing', self::of($map, 'faq')[0]['state']);
        $this->assertSame(0, self::of($map, 'faq')[0]['of']);
    }
}
