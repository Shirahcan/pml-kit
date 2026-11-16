<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;

/** The structural publish lock (post layouts A3, owner: "lock on structure only"): blocks for structure, warns for the rest. */
class LayoutChecksTest extends TestCase
{
    private function layout(): LayoutDefinition
    {
        return LayoutDefinition::fromArray([
            'key' => 'full-guide', 'name' => 'Full guide', 'kind' => 'post',
            'parts' => [
                ['key' => 'header', 'label' => 'Post header', 'kind' => 'bound', 'locked' => true],
                ['key' => 'hook', 'label' => 'Hook', 'kind' => 'fill', 'rules' => ['words' => ['min' => 3, 'max' => 12]]],
                ['key' => 'cta-awareness', 'label' => 'Awareness call to action', 'kind' => 'cta', 'stage' => 'awareness'],
                ['key' => 'section', 'label' => 'Question section', 'kind' => 'repeat', 'min' => 2],
                ['key' => 'cta-consideration', 'label' => 'Consideration call to action', 'kind' => 'cta', 'stage' => 'consideration', 'moves_within' => 'section'],
                ['key' => 'story', 'label' => 'Client story', 'kind' => 'record', 'required' => false],
                ['key' => 'cta-decision', 'label' => 'Decision call to action', 'kind' => 'cta', 'stage' => 'decision'],
                ['key' => 'faq', 'label' => 'FAQs', 'kind' => 'fill'],
                ['key' => 'faq-item', 'label' => 'FAQ', 'kind' => 'repeat', 'min' => 3, 'max' => 3, 'inside' => 'faq'],
            ],
        ]);
    }

    private static function text(string $value): array
    {
        return ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => $value]]]];
    }

    private static function marked(string $part, array $children = []): array
    {
        return ['primitive' => 'box', 'props' => [], 'part' => $part, 'children' => $children];
    }

    /** @return list<array<string, mixed>> a post that follows the layout */
    private function complete(): array
    {
        $faq = static fn ($i) => self::marked('faq-item', [self::text("Answer {$i} in a few words.")]);

        return [
            self::marked('header'),
            self::marked('hook', [self::text('Express Entry is still the main door.')]),
            self::marked('cta-awareness'),
            self::marked('section', [self::text('Who is eligible?')]),
            self::marked('cta-consideration'),
            self::marked('section', [self::text('What does it cost?')]),
            self::marked('cta-decision'),
            self::marked('faq', [$faq(1), $faq(2), $faq(3)]),
        ];
    }

    private function rules(array $result, string $severity): array
    {
        return array_values(array_map(static fn ($f) => $f['rule'].':'.$f['part'], array_filter($result['findings'], static fn ($f) => $f['severity'] === $severity)));
    }

    public function test_a_post_that_follows_its_layout_may_publish(): void
    {
        $result = LayoutChecks::check($this->layout(), $this->complete(), ['title' => 'Canada Express Entry 2026']);

        $this->assertTrue($result['mayPublish']);
        $this->assertSame([], $result['findings']);
    }

    public function test_a_missing_part_and_a_wrong_faq_count_block_and_say_the_fix(): void
    {
        $post = $this->complete();
        unset($post[6]); // the decision call to action
        array_pop($post[7]['children']); // one FAQ short

        $result = LayoutChecks::check($this->layout(), array_values($post));

        $this->assertFalse($result['mayPublish']);
        $this->assertSame(['layout.part_missing:cta-decision', 'layout.repeat_count:faq-item'], $this->rules($result, 'block'));
        $count = array_values(array_filter($result['findings'], static fn ($f) => $f['rule'] === 'layout.repeat_count'))[0];
        $this->assertSame('FAQ: this layout takes exactly 3, the post has 2.', $count['message']);
        $this->assertSame('Add FAQ until there are exactly 3.', $count['suggestion']);
        $this->assertSame('layout', $count['source']);
    }

    public function test_calls_to_action_out_of_stage_order_block(): void
    {
        $post = $this->complete();
        [$post[2], $post[6]] = [$post[6], $post[2]]; // decision first, awareness late

        $result = LayoutChecks::check($this->layout(), $post);

        $this->assertFalse($result['mayPublish']);
        $this->assertContains('layout.cta_order:cta-consideration', $this->rules($result, 'block'));
        $this->assertContains('layout.cta_order:cta-awareness', $this->rules($result, 'block'));
    }

    public function test_a_verify_marker_anywhere_blocks_with_its_words(): void
    {
        $post = $this->complete();
        $post[3]['children'][] = self::text('The fee is [VERIFY OFFICIAL IRCC DATA] for one person.');

        $result = LayoutChecks::check($this->layout(), $post, ['dek' => 'Fees from [VERIFY 2026]']);

        $this->assertFalse($result['mayPublish']);
        $markers = array_values(array_filter($result['findings'], static fn ($f) => $f['rule'] === 'layout.verify_marker'));
        $this->assertCount(2, $markers);
        $this->assertStringContainsString('The fee is [VERIFY OFFICIAL IRCC DATA]', $markers[0]['excerpt']);
    }

    public function test_an_faq_outside_its_part_blocks(): void
    {
        $post = $this->complete();
        $post[] = self::marked('faq-item', [self::text('A stray answer.')]);
        array_pop($post[7]['children']);

        $this->assertContains('layout.outside_its_part:faq-item', $this->rules(LayoutChecks::check($this->layout(), $post), 'block'));
    }

    public function test_counts_and_a_misplaced_cta_only_warn(): void
    {
        $post = $this->complete();
        $post[1] = self::marked('hook', [self::text('Too short.')]);
        // The consideration call to action moved below every question section.
        [$post[4], $post[5]] = [$post[5], $post[4]];

        $result = LayoutChecks::check($this->layout(), $post);

        $this->assertTrue($result['mayPublish']);
        $this->assertSame(['layout.rule:hook', 'layout.cta_outside_range:cta-consideration'], $this->rules($result, 'warn'));
        $this->assertSame('Hook: 2 words, the layout asks for at least 3.', $result['findings'][0]['message']);
    }
}
