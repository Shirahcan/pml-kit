<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\Natives;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PmlComponentExpander;
use Shirahcan\Pml\PmlDiagnoseService;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSnapshotBuilder;
use Shirahcan\Pml\PmlStreamPrefix;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\Psl\Psl;
use Shirahcan\Pml\StandardLibrary;

/**
 * The snapshot, the Code view's diagnostics and the build stream reader, in the package (shirahcan/pml 0.9.0,
 * shared program E2g): each runs with no framework, the snapshot scans the RESOLVED page with the one rule pack,
 * diagnose knows a product's components only through the map it is handed, and the stream reader reads the
 * finished part of a page still being written.
 */
class SnapshotDiagnoseStreamTest extends TestCase
{
    protected function setUp(): void
    {
        Natives::declare(['owner' => ['description' => 'Owner.', 'fields' => []]]);
    }

    private function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    private function builder(): PmlSnapshotBuilder
    {
        $blocks = new BlockSchema();

        return new PmlSnapshotBuilder(
            new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack()),
            new PmlComponentExpander($this->parser()),
            new StandardLibrary(),
            new ComplianceRulePack(),
        );
    }

    private function diagnostics(): PmlDiagnoseService
    {
        return new PmlDiagnoseService(
            new PmlScanner(),
            $this->parser(),
            new PrimitiveSchema(new BlockSchema()),
            new Psl(),
            new StandardLibrary(),
            new PmlComponentExpander($this->parser()),
        );
    }

    public function test_a_snapshot_finds_a_guarantee_in_the_resolved_page(): void
    {
        $out = $this->builder()->snapshot([
            ['uid' => 'heading1', 'primitive' => 'heading', 'props' => ['text' => 'We guarantee your visa approval', 'level' => '2']],
        ]);

        $this->assertNotSame([], $out['tree']);
        $this->assertNotSame([], $out['compliance']->violations());
    }

    public function test_a_snapshot_bakes_the_testimonials_and_scans_their_quotes(): void
    {
        $out = $this->builder()->snapshot(
            [['uid' => 'quotes01', 'primitive' => 'testimonials', 'props' => []]],
            ['testimonials' => [['quote' => 'They guaranteed my PR and it worked', 'author' => 'A client']]],
        );

        $this->assertSame('They guaranteed my PR and it worked', $out['tree'][0]['props']['resolved'][0]['quote']);
        $this->assertNotSame([], $out['compliance']->violations(), 'an unattested quote, and a guarantee in it, are both found');
    }

    public function test_honest_copy_snapshots_clean(): void
    {
        $out = $this->builder()->snapshot([
            ['uid' => 'heading1', 'primitive' => 'heading', 'props' => ['text' => 'A licensed consultant files; IRCC decides', 'level' => '2']],
        ]);

        $this->assertSame([], $out['compliance']->violations());
    }

    public function test_diagnose_locates_a_tag_syntax_error_alone(): void
    {
        $diags = $this->diagnostics()->diagnose('<hero headline="Study"');

        $this->assertCount(1, $diags);
        $this->assertSame('syntax', $diags[0]['source']);
    }

    public function test_diagnose_knows_a_products_component_only_through_the_map_it_is_given(): void
    {
        $source = "<heading text=\"A\" level=\"2\"/>\n<my_cta/>";
        $unknown = array_values(array_filter($this->diagnostics()->diagnose($source), fn (array $d) => $d['severity'] === 'error'));
        $known = array_values(array_filter(
            $this->diagnostics()->diagnose($source, ['my_cta' => fn (array $props, array $children): array => ['primitive' => 'text', 'props' => ['value' => 'Hi']]]),
            fn (array $d) => $d['severity'] === 'error',
        ));

        $this->assertNotSame([], $unknown, 'an unknown element is an error');
        $this->assertSame([], $known, 'the same element is fine once the map names it');
    }

    public function test_the_stream_reader_reads_the_finished_part_of_a_page(): void
    {
        $prefix = new PmlStreamPrefix($this->parser());
        $read = $prefix->read("```\n<heading text=\"Hi\" level=\"1\"/>\n\n<text value=\"Intro\"/>\n\n<faq heading=\"Questions\">\n  <faq-item question=\"Is it");

        $this->assertCount(2, $read['nodes']);
        $this->assertSame('heading', $read['nodes'][0]['primitive']);
        $this->assertSame('faq', $read['writing']);
        $this->assertNull($prefix->read('<heading text=')['nodes']);
        $this->assertNull($prefix->read("<heading text=\"Hi\"/>\n\n<h")['writing'], 'half a tag name is not an element');
    }
}
