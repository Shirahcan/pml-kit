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

    public function test_a_part_the_layout_has_once_appearing_twice_holds_publish(): void
    {
        $twice = $this->complete();
        array_splice($twice, 1, 0, [self::marked('header')]);

        $result = LayoutChecks::check($this->layout(), $twice, ['title' => 'Canada Express Entry 2026']);

        $this->assertFalse($result['mayPublish']);
        $this->assertSame(['layout.part_twice:header'], $this->rules($result, 'block'));
        $this->assertStringContainsString('Post header appears 2 times; this layout has one.', $result['findings'][0]['message']);
    }

    public function test_a_missing_part_and_a_wrong_faq_count_warn_and_say_the_fix_but_never_block(): void
    {
        $post = $this->complete();
        unset($post[6]); // the decision call to action
        array_pop($post[7]['children']); // one FAQ short

        $result = LayoutChecks::check($this->layout(), array_values($post));

        // Structure is advice (owner 2026-11-18): every element can be removed, and a removed part only warns.
        $this->assertTrue($result['mayPublish']);
        $this->assertSame([], $this->rules($result, 'block'));
        $this->assertSame(['layout.part_missing:cta-decision', 'layout.repeat_count:faq-item'], $this->rules($result, 'warn'));
        $count = array_values(array_filter($result['findings'], static fn ($f) => $f['rule'] === 'layout.repeat_count'))[0];
        $this->assertSame('FAQ: this layout takes exactly 3, the post has 2.', $count['message']);
        $this->assertSame('Add FAQ until there are exactly 3.', $count['suggestion']);
        $this->assertSame('layout', $count['source']);
    }

    public function test_calls_to_action_out_of_stage_order_warn(): void
    {
        $post = $this->complete();
        [$post[2], $post[6]] = [$post[6], $post[2]]; // decision first, awareness late

        $result = LayoutChecks::check($this->layout(), $post);

        $this->assertTrue($result['mayPublish'], 'the order is advice');
        // The first consideration and the first decision both appear before the first awareness.
        $this->assertSame(['layout.cta_order:cta-consideration', 'layout.cta_order:cta-decision'], $this->rules($result, 'warn'));
    }

    public function test_a_later_ask_box_after_the_decision_call_to_action_is_the_reference_layout_not_a_mistake(): void
    {
        $layout = LayoutDefinition::fromArray(['key' => 'l', 'name' => 'L', 'kind' => 'post', 'parts' => [
            ['key' => 'a', 'label' => 'Awareness', 'kind' => 'cta', 'stage' => 'awareness'],
            ['key' => 'c', 'label' => 'Consideration', 'kind' => 'cta', 'stage' => 'consideration'],
            ['key' => 'd', 'label' => 'Decision', 'kind' => 'cta', 'stage' => 'decision'],
            ['key' => 'ask', 'label' => 'Ask us', 'kind' => 'cta', 'stage' => 'consideration', 'variant' => 'ask'],
            ['key' => 'banner', 'label' => 'Closing banner', 'kind' => 'cta', 'stage' => 'decision', 'variant' => 'banner'],
        ]]);
        $post = [self::marked('a'), self::marked('c'), self::marked('d'), self::marked('ask'), self::marked('banner')];

        $this->assertTrue(LayoutChecks::check($layout, $post)['mayPublish']);
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

    public function test_an_faq_outside_its_part_warns(): void
    {
        $post = $this->complete();
        $post[] = self::marked('faq-item', [self::text('A stray answer.')]);
        array_pop($post[7]['children']);

        $this->assertContains('layout.outside_its_part:faq-item', $this->rules(LayoutChecks::check($this->layout(), $post), 'warn'));
    }

    public function test_right_after_the_last_section_is_inside_and_a_data_block_may_move_too(): void
    {
        $layout = LayoutDefinition::fromArray([
            'key' => 'classic', 'name' => 'Classic', 'kind' => 'post',
            'parts' => [
                ['key' => 'hook', 'label' => 'Hook', 'kind' => 'fill', 'skeleton' => [['primitive' => 'p', 'children' => [self::text('Hook words here.')]]]],
                ['key' => 'section', 'label' => 'Question section', 'kind' => 'repeat', 'min' => 4, 'skeleton' => [['primitive' => 'heading', 'props' => ['text' => 'Who can apply?', 'level' => 2]]]],
                ['key' => 'data', 'label' => 'Data table', 'kind' => 'repeat', 'min' => 1, 'moves_within' => 'section', 'starts_after' => 2, 'skeleton' => [['primitive' => 'p', 'children' => [self::text('The fee table.')]]]],
                ['key' => 'cta-consideration', 'label' => 'Consideration call to action', 'kind' => 'cta', 'stage' => 'consideration', 'moves_within' => 'section', 'starts_after' => 4, 'skeleton' => [['primitive' => 'post_cta', 'props' => ['stage' => 'consideration']]]],
            ],
        ]);

        $starter = $layout->starter();
        $this->assertSame(['hook', 'section', 'section', 'data', 'section', 'section', 'cta-consideration'], array_column($starter, 'part'), 'data after the 2nd section, the CTA after the 4th');
        $this->assertSame([], array_values(array_filter(LayoutChecks::check($layout, $starter)['findings'], static fn ($f) => str_contains($f['rule'], 'outside'))), 'a fresh document starts inside its ranges');

        // Fewer sections than starts_after: the CTA lands after the last one, and a data block there with the CTA behind it is still inside.
        $short = [$starter[0], $starter[1], $starter[2], $starter[3], $starter[6]];
        $this->assertSame([], array_values(array_filter(LayoutChecks::check($layout, $short)['findings'], static fn ($f) => str_contains($f['rule'], 'outside'))));

        // A data block above the first section is outside, and says so with its own rule.
        $above = [$starter[0], $starter[3], $starter[1], $starter[2], $starter[4], $starter[5], $starter[6]];
        $this->assertSame(['layout.outside_range:data'], $this->rules(LayoutChecks::check($layout, $above), 'warn'));
    }

    public function test_a_figure_to_check_is_a_note_that_blocks_until_it_is_removed(): void
    {
        $post = $this->complete();
        // Before the hook (the part of the next node), and inside a question section (the part it sits in).
        array_splice($post, 1, 0, [['comment' => 'VERIFY: the $85 processing fee (IRCC fee list)']]);
        $post[4]['children'][] = ['comment' => 'verify: biometrics cost $85 per person'];
        // A purpose note that only MENTIONS checking is not a figure to check.
        $post[] = ['comment' => 'Purpose: every figure you cannot confirm becomes a VERIFY note.'];

        $result = LayoutChecks::check($this->layout(), $post);
        $notes = array_values(array_filter($result['findings'], static fn ($f) => $f['rule'] === 'layout.verify_note'));

        $this->assertFalse($result['mayPublish']);
        $this->assertSame(['hook', 'section'], array_column($notes, 'part'));
        $this->assertSame('Check this, then remove its note: the $85 processing fee (IRCC fee list)', $notes[0]['message'], 'VERIFY said once');
        $this->assertSame('VERIFY: the $85 processing fee (IRCC fee list)', $notes[0]['excerpt'], 'the note\'s own words, to find it by');
        $this->assertSame('block', $notes[1]['severity']);

        $settled = array_values(array_filter($post, static fn ($n) => ! isset($n['comment']) || ! str_starts_with(strtoupper($n['comment']), 'VERIFY')));
        unset($settled[3]['children'][1]);
        $this->assertTrue(LayoutChecks::check($this->layout(), $settled)['mayPublish'], 'removing the notes is how a figure is settled');

        $old = $this->complete();
        $old[1] = self::marked('hook', [self::text('The fee is [VERIFY: $85].')]);
        $this->assertSame(['layout.verify_marker'], array_values(array_unique(array_column(array_filter(LayoutChecks::check($this->layout(), $old)['findings'], static fn ($f) => $f['severity'] === 'block'), 'rule'))), 'an old inline mark still blocks');
    }

    public function test_a_purpose_note_takes_no_place_on_the_page(): void
    {
        // 0.27.2: the Classic starter puts the CTA's purpose note in front of it, and the note made the CTA read as
        // NOT right after the last section.
        $note = ['comment' => 'Purpose: the consideration call to action, after the question sections.'];
        $layout = LayoutDefinition::fromArray([
            'key' => 'classic', 'name' => 'Classic', 'kind' => 'post',
            'parts' => [
                ['key' => 'section', 'label' => 'Question section', 'kind' => 'repeat', 'min' => 2, 'skeleton' => [$note, ['primitive' => 'heading', 'props' => ['text' => 'Who can apply?', 'level' => 2]]]],
                ['key' => 'cta-consideration', 'label' => 'Consideration call to action', 'kind' => 'cta', 'stage' => 'consideration', 'moves_within' => 'section', 'starts_after' => 4, 'skeleton' => [$note, ['primitive' => 'post_cta', 'props' => ['stage' => 'consideration']]]],
            ],
        ]);

        $starter = $layout->starter();
        $this->assertSame([], array_values(array_filter(LayoutChecks::check($layout, $starter)['findings'], static fn ($f) => str_contains($f['rule'], 'outside'))));
    }

    public function test_a_moving_part_says_where_it_starts_and_only_a_moving_part_may(): void
    {
        $part = static fn (array $extra) => LayoutDefinition::fromArray(['key' => 'x', 'name' => 'X', 'kind' => 'post', 'parts' => [
            ['key' => 'section', 'label' => 'Section', 'kind' => 'repeat', 'min' => 1],
            ['key' => 'data', 'label' => 'Data', 'kind' => 'fill'] + $extra,
        ]]);

        $this->assertSame(2, $part(['moves_within' => 'section', 'starts_after' => 2])->part('data')['starts_after']);
        foreach ([['starts_after' => 2], ['moves_within' => 'section', 'starts_after' => 0]] as $bad) {
            try {
                $part($bad);
                $this->fail('refused: '.json_encode($bad));
            } catch (\Shirahcan\Pml\InvalidPmlException $e) {
                $this->assertStringContainsString('starts_after', $e->getMessage());
            }
        }
    }

    public function test_counts_and_a_misplaced_cta_only_warn(): void
    {
        $post = $this->complete();
        $post[1] = self::marked('hook', [self::text('Too short.')]);
        // The consideration call to action moved above every question section (right after the LAST one is inside its
        // range since 0.27.1, so "outside" is now before the first).
        $cta = $post[4];
        unset($post[4]);
        array_splice($post, 3, 0, [$cta]);
        $post = array_values($post);

        $result = LayoutChecks::check($this->layout(), $post);

        $this->assertTrue($result['mayPublish']);
        $this->assertSame(['layout.rule:hook', 'layout.cta_outside_range:cta-consideration'], $this->rules($result, 'warn'));
        $this->assertSame('Hook: 2 words, the layout asks for at least 3.', $result['findings'][0]['message']);
    }
}
