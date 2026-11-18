<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\Layout\TocItems;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PmlComponentExpander;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PmlSnapshotBuilder;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\StandardLibrary;

/**
 * The Classic template's pieces (pml-kit 0.26.0, T1a; owner 2026-11-17: "the template should be properly mirrored"):
 * the header as separate bound pieces, a contents list made of items, and purpose notes in a layout's skeleton.
 */
final class PostPiecesTest extends TestCase
{
    private const HEADER = '<post-breadcrumbs/>'
        ."\n".'<post-title/>'
        ."\n".'<post-dek/>'
        ."\n".'<post-meta>'
        ."\n".'  <post-author/>'
        ."\n".'  <post-date kind="published"/>'
        ."\n".'  <post-date kind="updated"/>'
        ."\n".'  <post-read-time/>'
        ."\n".'  <post-reviewer/>'
        ."\n".'</post-meta>'
        ."\n".'<toc title="On this page">'
        ."\n".'  <toc-item label="Who can apply" target="who-can-apply"/>'
        ."\n".'</toc>'
        ."\n".'<post-tags/>';

    private static function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    private static function builder(): PmlSnapshotBuilder
    {
        $blocks = new BlockSchema();

        return new PmlSnapshotBuilder(
            new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack()),
            new PmlComponentExpander(self::parser()),
            new StandardLibrary(),
            new ComplianceRulePack(),
        );
    }

    public function test_the_catalog_has_the_pieces_and_their_nesting(): void
    {
        foreach (['post_breadcrumbs', 'post_title', 'post_dek', 'post_meta', 'post_author', 'post_date', 'post_read_time', 'post_reviewer', 'post_tags', 'toc_item'] as $name) {
            $this->assertTrue(ElementCatalog::has($name), $name);
        }
        $this->assertSame(['post_author', 'post_date', 'post_read_time', 'post_reviewer'], ElementCatalog::childrenOf('post_meta'));
        $this->assertSame(['toc_item'], ElementCatalog::childrenOf('toc'));
        $this->assertTrue(ElementCatalog::has('post_header'), 'older posts keep their header');
    }

    public function test_the_pieces_round_trip_through_pml_text_and_an_old_toc_stays_self_closing(): void
    {
        $nodes = self::parser()->parse(self::HEADER)['nodes'];
        $serializer = new PmlSerializer(new DocToMarkdown());

        $this->assertSame('updated', $nodes[3]['children'][2]['props']['kind']);
        $this->assertSame(['label' => 'Who can apply', 'target' => 'who-can-apply'], $nodes[4]['children'][0]['props']);
        $this->assertSame($nodes, self::parser()->parse($serializer->serialize(['imports' => [], 'nodes' => $nodes]))['nodes']);

        $old = [['primitive' => 'toc', 'props' => ['title' => 'On this page', 'depth' => 2]]];
        $this->assertSame('<toc title="On this page" depth="2"/>', trim($serializer->serialize(['imports' => [], 'nodes' => $old])));
    }

    public function test_each_piece_bakes_the_posts_fields_and_the_notes_never_reach_readers(): void
    {
        $tree = [
            ['comment' => 'Purpose: where the reader is on the site. Built from the post\'s category, never typed.'],
            ['primitive' => 'post_breadcrumbs', 'props' => []],
            ['primitive' => 'post_title', 'props' => []],
            ['primitive' => 'post_meta', 'props' => [], 'children' => [
                ['primitive' => 'post_date', 'props' => ['kind' => 'updated']],
            ]],
        ];
        $post = [
            'title' => 'Who can apply for a work permit',
            'blog_url' => 'https://example.test/blog',
            'category' => ['name' => 'Work and LMIA', 'slug' => 'work', 'url' => 'https://example.test/blog/category/work'],
            'published_at' => '2026-11-01T10:00:00Z',
            'updated_at' => '2026-11-15T09:00:00Z',
        ];

        $baked = self::builder()->snapshot($tree, ['post' => $post])['tree'];

        $this->assertSame([], array_values(array_filter($baked, fn ($n) => is_array($n) && array_key_exists('comment', $n))), 'a purpose note is dropped from what readers get');
        $this->assertSame('https://example.test/blog', $baked[0]['props']['resolved']['blog_url']);
        $this->assertSame('Work and LMIA', $baked[0]['props']['resolved']['category']['name']);
        $this->assertSame('Who can apply for a work permit', $baked[1]['props']['resolved']['title']);
        $this->assertSame('2026-11-15T09:00:00Z', $baked[2]['children'][0]['props']['resolved']['updated_at']);
    }

    public function test_the_summary_and_the_sources_list_bake_the_posts_own_summary_and_sources(): void
    {
        $this->assertTrue(ElementCatalog::has('post_summary'));
        $this->assertTrue(ElementCatalog::has('post_sources'));

        $tree = [['primitive' => 'post_summary', 'props' => []], ['primitive' => 'post_sources', 'props' => []]];
        $post = [
            'answer' => 'Most graduates of an eligible program can apply within 180 days of their final marks.',
            'sources' => [['label' => 'IRCC: Post-graduation work permit', 'url' => 'https://www.canada.ca/pgwp']],
        ];

        $baked = self::builder()->snapshot($tree, ['post' => $post])['tree'];

        $this->assertSame($post['answer'], $baked[0]['props']['resolved']['answer']);
        $this->assertSame($post['sources'], $baked[1]['props']['resolved']['sources']);
    }

    public function test_a_skeletons_purpose_note_is_kept_by_the_starter_and_never_checked(): void
    {
        $layout = LayoutDefinition::fromArray(['key' => 'classic', 'name' => 'Classic', 'kind' => 'post', 'parts' => [
            ['key' => 'title', 'label' => 'Title', 'kind' => 'bound', 'skeleton' => [
                ['comment' => 'The page\'s one h1: the Title field. Never write a figure here: use [VERIFY: the fee] in the body.'],
                ['primitive' => 'post_title', 'props' => []],
            ]],
        ]]);

        $starter = $layout->starter();
        $this->assertSame(['comment' => 'The page\'s one h1: the Title field. Never write a figure here: use [VERIFY: the fee] in the body.'], $starter[0]);
        $this->assertSame('title', $starter[1]['part']);
        $this->assertArrayNotHasKey('part', $starter[0], 'a note belongs to no part');

        $this->assertTrue(LayoutChecks::check($layout, $starter)['mayPublish'], 'a marker named inside a note never holds a post back');
    }

    /** 0.26.1: a post started from a template with notes is validated on every save, so every node must pass. */
    public function test_every_node_a_starter_returns_passes_validation_including_nested_notes(): void
    {
        $schema = new PrimitiveSchema(new BlockSchema());
        $layout = LayoutDefinition::fromArray(['key' => 'classic', 'name' => 'Classic', 'kind' => 'post', 'parts' => [
            ['key' => 'title', 'label' => 'Title', 'kind' => 'bound', 'skeleton' => [
                ['comment' => 'Purpose: the page\'s one h1.'],
                ['primitive' => 'post_title', 'props' => []],
            ]],
            ['key' => 'body', 'label' => 'Body', 'kind' => 'fill', 'skeleton' => [
                ['primitive' => 'box', 'props' => [], 'children' => [
                    ['comment' => 'Purpose: the hook, in the reader\'s words.'],
                    ['primitive' => 'p', 'props' => [], 'children' => [['primitive' => 'text', 'props' => ['value' => '[Hook]']]]],
                ]],
            ]],
        ]]);

        $walk = function (array $nodes, string $path) use (&$walk, $schema): int {
            $count = 0;
            foreach ($nodes as $i => $node) {
                $schema->validateNode($node, "{$path}.{$i}");
                $count++;
                $count += $walk((array) ($node['children'] ?? []), "{$path}.{$i}.children");
            }

            return $count;
        };

        $this->assertSame(6, $walk($layout->starter(), 'nodes'));
        $this->assertSame([['comment' => 'Purpose: the page\'s one h1.']], PrimitiveSchema::canonical([['comment' => 'Purpose: the page\'s one h1.']]), 'kept byte for byte');

        $schema->validateNode(['comment' => 'A studio note', 'note' => ['for' => 'u1']]);
        foreach ([['comment' => 5], ['comment' => 'x', 'primitive' => 'p'], ['comment' => 'x', 'note' => 'not a note']] as $bad) {
            try {
                $schema->validateNode($bad);
                $this->fail('accepted '.json_encode($bad));
            } catch (\Shirahcan\Pml\InvalidPmlException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_contents_items_point_at_the_ids_the_page_gives_its_headings(): void
    {
        $nodes = [
            ['primitive' => 'heading', 'props' => ['text' => 'Fees', 'level' => 2, 'anchor' => 'fees']],
            ['comment' => 'a note'],
            ['primitive' => 'box', 'children' => [
                ['primitive' => 'heading', 'props' => ['text' => 'Fees', 'level' => 2]],
                ['primitive' => 'heading', 'props' => ['text' => "Documents you'll need (2026)", 'level' => 2]],
                ['primitive' => 'heading', 'props' => ['text' => 'Café rules', 'level' => 3]],
                ['primitive' => 'heading', 'props' => ['text' => '2026 changes', 'level' => 2]],
            ]],
            ['primitive' => 'rich_text', 'props' => ['doc' => ['type' => 'doc', 'content' => [
                ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Fees']]],
            ]]]],
        ];

        $targets = fn (array $items) => array_map(fn (array $i) => $i['props']['target'], $items);

        // An explicit anchor is reserved first, so the second "Fees" is fees-2 and the prose heading fees-3.
        $this->assertSame(['fees', 'fees-2', 'documents-you-ll-need-2026', 's-2026-changes', 'fees-3'], $targets(TocItems::fromNodes($nodes)));
        $this->assertContains('cafe-rules', $targets(TocItems::fromNodes($nodes, 3)), 'depth 3 adds the parts, accents folded');
        $this->assertSame(['primitive' => 'toc_item', 'props' => ['label' => 'Fees', 'target' => 'fees']], TocItems::fromNodes($nodes)[0]);
    }
}
