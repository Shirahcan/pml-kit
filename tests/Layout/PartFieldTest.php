<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * `part="<key>"` (post layouts A1a, owner 2026-11-16): which part of a layout an element is, so a layout can lock,
 * repeat or check it. A node-level field like `slot`, never a prop, and it survives the code view both ways.
 */
class PartFieldTest extends TestCase
{
    private function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    public function test_part_is_a_node_field_not_a_prop_and_round_trips_through_the_code_view(): void
    {
        $nodes = $this->parser()->parse('<box part="hook"><p part="hook-words">Express Entry is still the main door.</p></box>')['nodes'];

        $this->assertSame('hook', $nodes[0]['part']);
        $this->assertArrayNotHasKey('part', $nodes[0]['props'] ?? []);
        $this->assertSame('hook-words', $nodes[0]['children'][0]['part']);

        $source = (new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]);

        $this->assertStringContainsString('part="hook"', $source);
        // A marked paragraph keeps its tag: written as bare words it would lose the mark.
        $this->assertStringContainsString('part="hook-words"', $source);
        $this->assertSame($nodes, $this->parser()->parse($source)['nodes']);
    }

    public function test_unmarked_elements_are_written_exactly_as_before(): void
    {
        $nodes = $this->parser()->parse('<box part="body"><p>Plain words.</p></box>')['nodes'];
        $source = (new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]);

        $this->assertStringContainsString('<p>Plain words.</p>', $source);
        $this->assertSame(1, substr_count($source, 'part='));
        $this->assertArrayNotHasKey('part', $nodes[0]['children'][0]);
    }

    public function test_the_schema_accepts_a_short_part_key_and_names_a_bad_one(): void
    {
        $schema = new PrimitiveSchema(new BlockSchema());
        $schema->validateNode(['primitive' => 'box', 'props' => [], 'part' => 'cta-decision']);

        $this->expectException(InvalidPmlException::class);
        $this->expectExceptionMessage('A "part" names one part of the layout');
        $schema->validateNode(['primitive' => 'box', 'props' => [], 'part' => '9 not a key']);
    }
}
