<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A list item holds words and one nested list (pml-kit 0.25.0, owner 2026-11-17 "close all gaps"): the catalog allows
 * it, the PML text round-trips it, every list written before it serialises byte for byte, and the compliance scan
 * still reads every word of a list (a plain item's props, a rich item's runs, a nested list's items).
 */
final class ListItemWordsTest extends TestCase
{
    private const RICH = '<list type="bullet">'
        ."\n".'  <list-item title="Fees">– pay at <link href="https://ircc.example/pay">IRCC</link> <emphasis>online</emphasis><list type="numbered">'
        ."\n".'  <list-item text="Keep the receipt"/>'
        ."\n".'</list></list-item>'
        ."\n".'  <list-item text="Plain words"/>'
        ."\n".'</list>';

    private static function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    private static function serialize(array $nodes): string
    {
        return (new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]);
    }

    public function test_the_catalog_lets_a_list_item_hold_words_and_one_nested_list(): void
    {
        $this->assertTrue(ElementCatalog::holdsInline('list_item'));
        $this->assertSame(['list'], ElementCatalog::alsoHolds('list_item'));
        $this->assertTrue(ElementCatalog::hasProseProps('list_item'));
        $this->assertFalse(ElementCatalog::hasProseProps('p'), 'a paragraph keeps its old serialisation');
        $this->assertSame([], ElementCatalog::alsoHolds('p'));
    }

    public function test_a_list_written_before_items_held_words_serialises_byte_for_byte(): void
    {
        $nodes = [['primitive' => 'list', 'props' => ['type' => 'bullet'], 'children' => [
            ['primitive' => 'list_item', 'props' => ['title' => 'Payments', 'text' => 'Accept payments']],
            ['primitive' => 'list_item', 'props' => ['text' => 'Plain item']],
        ]]];

        $this->assertSame(
            '<list type="bullet">'."\n".'  <list-item title="Payments" text="Accept payments"/>'."\n".'  <list-item text="Plain item"/>'."\n".'</list>',
            trim(self::serialize($nodes)),
        );
    }

    public function test_an_item_with_a_link_and_a_nested_list_round_trips_through_pml_text(): void
    {
        $nodes = self::parser()->parse(self::RICH)['nodes'];
        $item = $nodes[0]['children'][0];

        $this->assertSame(['title' => 'Fees'], $item['props']);
        $this->assertSame(['text', 'link', 'text', 'emphasis', 'list'], array_map(fn (array $n) => $n['primitive'], $item['children']));
        $this->assertSame('https://ircc.example/pay', $item['children'][1]['props']['href']);
        $this->assertSame('Keep the receipt', $item['children'][4]['children'][0]['props']['text']);

        // Written back and read again, nothing moves.
        $this->assertSame($nodes, self::parser()->parse(self::serialize($nodes))['nodes']);
    }

    public function test_the_compliance_scan_reads_every_word_of_a_list(): void
    {
        $blocks = new BlockSchema();
        $compiler = new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack());
        $prose = $compiler->extractProse(self::parser()->parse(self::RICH)['nodes']);

        $this->assertStringContainsString('Fees', $prose, 'a rich item\'s title is still read');
        $this->assertStringContainsString('– pay at IRCC online', $prose, 'its words are read as one sentence');
        $this->assertStringContainsString('Keep the receipt', $prose, 'its nested list is read');
        $this->assertStringContainsString('Plain words', $prose, 'a plain item is read as before');
    }
}
