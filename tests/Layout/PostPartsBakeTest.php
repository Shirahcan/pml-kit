<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PmlComponentExpander;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSnapshotBuilder;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\StandardLibrary;

/** The post parts (post layouts C2a): they draw a post's own fields, baked like `credentials`, never a placeholder. */
class PostPartsBakeTest extends TestCase
{
    private function builder(): PmlSnapshotBuilder
    {
        $blocks = new BlockSchema();

        return new PmlSnapshotBuilder(
            new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack()),
            new PmlComponentExpander(new PmlParser(new PmlScanner(), new MarkdownToDoc())),
            new StandardLibrary(),
            new ComplianceRulePack(),
        );
    }

    /** @return array<string, mixed> */
    private function post(bool $confirmed): array
    {
        return [
            'title' => 'Canada Express Entry 2026: Eligibility, CRS and Draws',
            'dek' => '',
            'category' => ['name' => 'Express Entry', 'slug' => 'express-entry'],
            'tags' => ['express entry', 'crs'],
            'read_minutes' => 12,
            'reviewer' => ['name' => 'Ada Okafor', 'licence' => 'R000000', 'confirmed' => $confirmed],
            'internal_note' => 'never drawn',
        ];
    }

    public function test_each_post_part_bakes_the_posts_fields_and_drops_empty_ones(): void
    {
        $tree = [['primitive' => 'post_header', 'props' => []], ['primitive' => 'post_cover', 'props' => ['ratio' => '16:9']], ['primitive' => 'post_related', 'props' => ['heading' => 'Related guides']], ['primitive' => 'post_disclaimer', 'props' => ['text' => 'General information, not legal advice.']]];

        $baked = $this->builder()->snapshot($tree, ['post' => $this->post(true)])['tree'];

        foreach ($baked as $part) {
            $this->assertSame('Canada Express Entry 2026: Eligibility, CRS and Draws', $part['props']['resolved']['title']);
            $this->assertSame(12, $part['props']['resolved']['read_minutes']);
            $this->assertArrayNotHasKey('dek', $part['props']['resolved'], 'an empty field draws nothing');
            $this->assertArrayNotHasKey('internal_note', $part['props']['resolved'], 'only the post fields a part may draw');
        }
        $this->assertSame('Ada Okafor', $baked[0]['props']['resolved']['reviewer']['name']);
        $this->assertSame('16:9', $baked[1]['props']['ratio']);
    }

    public function test_an_unconfirmed_reviewer_never_reaches_a_part(): void
    {
        $baked = $this->builder()->snapshot([['primitive' => 'post_header', 'props' => []]], ['post' => $this->post(false)])['tree'];

        $this->assertArrayNotHasKey('reviewer', $baked[0]['props']['resolved']);
    }

    public function test_without_post_data_a_part_resolves_to_nothing_and_other_elements_are_untouched(): void
    {
        $baked = $this->builder()->snapshot([['primitive' => 'post_header', 'props' => []], ['primitive' => 'heading', 'props' => ['text' => 'Hi', 'level' => 2]]])['tree'];

        $this->assertSame([], $baked[0]['props']['resolved']);
        $this->assertArrayNotHasKey('resolved', $baked[1]['props']);
    }

    public function test_a_call_to_action_bakes_its_library_row_falls_back_to_the_stages_inline_row_and_is_empty_when_unset(): void
    {
        $ctas = [
            'decision:banner' => ['headline' => 'Move forward with a licensed consultant you can trust', 'button' => ['label' => 'Get Matched Now', 'href' => '/marketplace/intake']],
            'awareness:inline' => ['headline' => 'Rules change fast. Get the update before your next step.', 'button' => ['label' => 'Get updates', 'href' => null, 'kind' => 'subscribe']],
        ];
        $tree = [
            ['primitive' => 'post_cta', 'props' => ['stage' => 'decision', 'variant' => 'banner']],
            ['primitive' => 'post_cta', 'props' => ['stage' => 'awareness', 'variant' => 'rail']],
            ['primitive' => 'post_cta', 'props' => ['stage' => 'consideration', 'variant' => 'inline']],
        ];

        $baked = $this->builder()->snapshot($tree, ['ctas' => $ctas])['tree'];

        $this->assertSame('Get Matched Now', $baked[0]['props']['resolved']['button']['label']);
        $this->assertSame('Rules change fast. Get the update before your next step.', $baked[1]['props']['resolved']['headline'], 'a rail with no row of its own uses the stage inline row');
        $this->assertSame([], $baked[2]['props']['resolved'], 'an unset stage draws nothing, never a default');
    }

    public function test_a_story_draws_only_when_verified_with_consent_and_never_more_than_its_story_fields(): void
    {
        $story = ['case_type' => 'Canadian Experience Class', 'challenge' => 'A score just under recent cutoffs.', 'strategy' => 'Reference letters rebuilt to match the NOC duties.', 'outcome' => 'Invited in the next draw.', 'how_found' => 'Through Portify Marketplace.', 'client_name' => 'never drawn', 'verified' => true, 'consent_at' => '2026-11-10T12:00:00Z'];
        $tree = [['primitive' => 'post_story', 'props' => ['heading' => 'Client story']]];

        $baked = $this->builder()->snapshot($tree, ['story' => $story])['tree'][0]['props']['resolved'];
        $this->assertSame(['case_type', 'challenge', 'strategy', 'outcome', 'how_found'], array_keys($baked));

        $this->assertSame([], $this->builder()->snapshot($tree, ['story' => ['verified' => false] + $story])['tree'][0]['props']['resolved'], 'unverified: nothing');
        $this->assertSame([], $this->builder()->snapshot($tree, ['story' => ['consent_at' => null] + $story])['tree'][0]['props']['resolved'], 'no recorded consent: nothing');
        $this->assertSame([], $this->builder()->snapshot($tree)['tree'][0]['props']['resolved'], 'no story picked: nothing');
    }
}
