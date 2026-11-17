<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
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
 * The reference studio's header and story (0.27.0, T2b; owner 2026-11-17: "The template should maintain this"): the
 * category pill, "Last verified", the trust badges, the story's own labels, and a part that still holds its template's
 * example words never publishing.
 */
final class ReferenceHeaderTest extends TestCase
{
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

    public function test_the_catalog_has_the_reference_pieces(): void
    {
        foreach (['post_category', 'post_badges', 'post_reviewer_badge', 'post_sources_badge'] as $name) {
            $this->assertTrue(ElementCatalog::has($name), $name);
        }
        $this->assertSame(['post_reviewer_badge', 'post_sources_badge'], ElementCatalog::childrenOf('post_badges'));

        $source = '<post-category/><post-meta><post-date kind="verified"/></post-meta>'
            .'<post-badges><post-reviewer-badge/><post-sources-badge/></post-badges>'
            .'<post-story heading="Client story" situation-label="The situation" how-found-label="How they found their consultant on Portify" outcome-label="What happened"/>';
        $nodes = self::parser()->parse($source)['nodes'];

        $this->assertSame('verified', $nodes[1]['children'][0]['props']['kind']);
        $this->assertSame('How they found their consultant on Portify', $nodes[3]['props']['how_found_label']);
        $this->assertSame($nodes, self::parser()->parse((new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]))['nodes'], 'round trip');
    }

    public function test_the_pieces_bake_the_home_the_verified_date_a_confirmed_reviewer_and_the_source_sites(): void
    {
        $tree = [
            ['primitive' => 'post_breadcrumbs', 'props' => []],
            ['primitive' => 'post_category', 'props' => []],
            ['primitive' => 'post_badges', 'props' => [], 'children' => [
                ['primitive' => 'post_reviewer_badge', 'props' => []],
                ['primitive' => 'post_sources_badge', 'props' => []],
            ]],
        ];
        $post = [
            'home_url' => 'https://example.test',
            'blog_url' => 'https://example.test/blog',
            'category' => ['name' => 'Work and LMIA', 'url' => 'https://example.test/blog/category/work'],
            'last_verified_at' => '2026-11-10T09:00:00Z',
            'reviewer' => ['name' => 'Lara Taj', 'number' => 'R706506', 'confirmed' => true],
            'sources' => [
                ['label' => 'IRCC fees', 'url' => 'https://www.canada.ca/en/immigration-refugees-citizenship/services/application/fees.html'],
                ['label' => 'Processing times', 'url' => 'https://www.canada.ca/en/immigration/processing-times.html'],
                ['label' => 'ESDC', 'url' => 'https://esdc.gc.ca/wages'],
                ['label' => 'not an address', 'url' => 'javascript:alert(1)'],
            ],
        ];

        $baked = self::builder()->snapshot($tree, ['post' => $post])['tree'];

        $this->assertSame('https://example.test', $baked[0]['props']['resolved']['home_url']);
        $this->assertSame('Work and LMIA', $baked[1]['props']['resolved']['category']['name']);
        $this->assertSame('Lara Taj', $baked[2]['children'][0]['props']['resolved']['reviewer']['name']);
        $this->assertSame(['canada.ca', 'esdc.gc.ca'], $baked[2]['children'][1]['props']['resolved']['source_domains']);
        $this->assertSame('2026-11-10T09:00:00Z', $baked[2]['props']['resolved']['last_verified_at']);

        $unconfirmed = self::builder()->snapshot($tree, ['post' => ['reviewer' => ['name' => 'Lara Taj', 'confirmed' => false]]])['tree'];
        $this->assertArrayNotHasKey('reviewer', $unconfirmed[2]['children'][0]['props']['resolved'], 'an unconfirmed reviewer is never shown');
        $this->assertArrayNotHasKey('source_domains', $unconfirmed[2]['children'][1]['props']['resolved'], 'no sources, no badge');
    }

    public function test_a_part_still_holding_its_example_words_blocks_once_and_says_which(): void
    {
        $layout = LayoutDefinition::fromArray(['key' => 'classic', 'name' => 'Classic', 'kind' => 'post', 'parts' => [
            ['key' => 'title', 'label' => 'Title', 'kind' => 'fill', 'required' => true, 'skeleton' => [
                ['comment' => 'Purpose: the [question] in the reader\'s words.'],
                ['primitive' => 'heading', 'props' => ['text' => '[The question the post answers]', 'level' => 2]],
            ]],
            ['key' => 'faq', 'label' => 'FAQ', 'kind' => 'repeat', 'min' => 1, 'max' => 3, 'skeleton' => [
                ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => '[A question readers ask] [VERIFY: the fee]']]]],
            ]],
        ]]);
        $faq = fn (string $words) => ['primitive' => 'p', 'part' => 'faq', 'children' => [['primitive' => 'text', 'props' => ['value' => $words]]]];

        $left = LayoutChecks::check($layout, [
            ['primitive' => 'heading', 'part' => 'title', 'props' => ['text' => '[The question the post answers]', 'level' => 2]],
            $faq('[A question readers ask]'),
            $faq('[A question readers ask]'),
        ]);
        $unfilled = array_values(array_filter($left['findings'], fn ($f) => $f['rule'] === 'layout.unfilled'));

        $this->assertFalse($left['mayPublish']);
        $this->assertSame(['[The question the post answers]', '[A question readers ask]'], array_column($unfilled, 'excerpt'), 'once per example per part; the note and the [VERIFY mark are not examples');
        $this->assertSame(['title', 'faq'], array_column($unfilled, 'part'));

        $written = LayoutChecks::check($layout, [
            ['primitive' => 'heading', 'part' => 'title', 'props' => ['text' => 'Who can apply for an LMIA?', 'level' => 2]],
            ['comment' => 'Kept from the template: [The question the post answers]'],
            $faq('How long does it take? See [1].'),
        ]);
        $this->assertTrue($written['mayPublish'], json_encode($written['findings']));
    }
}
