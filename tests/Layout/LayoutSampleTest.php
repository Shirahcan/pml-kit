<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\Layout\LayoutSample;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * Growth templates A2 (owner 2026-11-20): a template's thumbnail is drawn as a reader sees a finished page, so its sample
 * has sample words where the guidance brackets were, a sample picture, and no notes.
 */
class LayoutSampleTest extends TestCase
{
    private function fixture(string $file): LayoutDefinition
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__)."/fixtures/layouts/{$file}"), true);

        return LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));
    }

    /** @return list<string> every string a reader would read (text values and prose props) */
    private static function strings(array $nodes): array
    {
        $out = [];
        array_walk_recursive($nodes, function ($v) use (&$out) {
            if (is_string($v)) {
                $out[] = $v;
            }
        });

        return $out;
    }

    private static function hasComment(array $nodes): bool
    {
        foreach ($nodes as $node) {
            if (is_array($node) && (PmlParser::isCommentNode($node) || self::hasComment((array) ($node['children'] ?? [])))) {
                return true;
            }
        }

        return false;
    }

    public function test_no_guidance_bracket_or_note_survives_in_either_fixture(): void
    {
        foreach (['post.full-guide.json', 'email.portify-weekly.json'] as $file) {
            $sample = LayoutSample::nodes($this->fixture($file));

            $bracketed = array_filter(self::strings($sample), static fn (string $s) => str_starts_with(trim($s), '[') && str_ends_with(trim($s), ']'));
            $this->assertSame([], array_values($bracketed), $file);
            $this->assertFalse(self::hasComment($sample), "{$file}: notes are the writer's, not the reader's");
        }
    }

    public function test_every_picture_has_an_address_and_the_structure_is_the_starters(): void
    {
        $layout = $this->fixture('email.portify-weekly.json');
        $sample = LayoutSample::nodes($layout);

        $images = [];
        array_walk_recursive($sample, function ($v, $k) use (&$images) {
            if ($k === 'primitive' && $v === 'image') {
                $images[] = true;
            }
        });
        $srcs = array_filter(self::strings($sample), static fn ($s) => $s === LayoutSample::PICTURE);
        $this->assertGreaterThanOrEqual(count($images), count($srcs) + 0, 'each empty picture draws the sample picture');

        $parts = static fn (array $nodes) => array_values(array_filter(array_map(static fn ($n) => $n['part'] ?? null, $nodes)));
        $starterParts = $parts(array_values(array_filter($layout->starter(), static fn ($n) => ! PmlParser::isCommentNode($n))));
        $this->assertSame($starterParts, $parts($sample), 'the same parts, in the same order');
    }

    public function test_a_headings_guidance_becomes_a_heading_short_line(): void
    {
        $sample = LayoutSample::nodes($this->fixture('post.full-guide.json'));
        $headings = [];
        $walk = function (array $nodes) use (&$walk, &$headings) {
            foreach ($nodes as $n) {
                if (is_array($n) && ($n['primitive'] ?? null) === 'heading' && is_string($n['props']['text'] ?? null)) {
                    $headings[] = $n['props']['text'];
                }
                if (is_array($n['children'] ?? null)) {
                    $walk($n['children']);
                }
            }
        };
        $walk($sample);

        $this->assertNotSame([], $headings);
        foreach ($headings as $text) {
            $this->assertLessThanOrEqual(40, mb_strlen($text), "a heading, not a paragraph: {$text}");
            $this->assertStringNotContainsString('.', $text);
        }
    }

    public function test_words_a_template_keeps_are_shown_as_they_are(): void
    {
        $layout = $this->fixture('post.full-guide.json');
        $data = $layout->toArray();
        foreach ($data['parts'] as &$part) {
            if ($part['key'] === 'hook') {
                $part['skeleton'][count($part['skeleton']) - 1]['children'][0]['props']['value'] = 'An approved LMIA is not a work permit.';
            }
        }
        unset($part);

        $sample = LayoutSample::nodes(LayoutDefinition::fromArray($data));

        $this->assertContains('An approved LMIA is not a work permit.', self::strings($sample));
    }
}
