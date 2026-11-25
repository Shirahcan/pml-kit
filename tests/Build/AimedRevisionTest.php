<?php

namespace Shirahcan\Pml\Tests\Build;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Build\AimedRevision;

/**
 * P5-1c 2b: an aimed ask lands with the block's id, part marks and notes, and nothing outside it moves.
 */
final class AimedRevisionTest extends TestCase
{
    private const PURPOSE = 'Purpose: the direct answer in under 50 words.';

    /** @return list<array<string, mixed>> a post's question section, its short answer holding a pinned note */
    private function design(): array
    {
        return [
            ['component' => 'heading', 'uid' => 'top00001', 'props' => ['text' => 'Title']],
            [
                'primitive' => 'box', 'uid' => 'sect0001', 'part' => 'section',
                'children' => [
                    ['component' => 'heading', 'uid' => 'head0001', 'props' => ['text' => 'Who can apply?']],
                    [
                        'component' => 'callout', 'uid' => 'call0001', 'part' => 'short_answer',
                        'children' => [
                            ['comment' => self::PURPOSE, 'note' => ['for' => 'ans00001', 'author' => 'Template']],
                            ['component' => 'text', 'uid' => 'ans00001', 'props' => ['text' => 'Workers with an offer.']],
                        ],
                    ],
                ],
            ],
            ['component' => 'text', 'uid' => 'foot0001', 'props' => ['text' => 'Not legal advice.']],
        ];
    }

    /** The section as a model writes it back: new heading, the note dropped, the part marks lost, a new id on the block. */
    private function answer(): array
    {
        return [
            'primitive' => 'box', 'uid' => 'new00001',
            'children' => [
                ['component' => 'heading', 'uid' => 'head0001', 'props' => ['text' => 'Who can apply in 2026?']],
                [
                    'component' => 'callout', 'uid' => 'call0001',
                    'children' => [
                        ['component' => 'text', 'uid' => 'ans00001', 'props' => ['text' => 'Workers with an offer.']],
                    ],
                ],
            ],
        ];
    }

    public function test_a_note_the_model_dropped_inside_the_block_goes_back_before_its_element(): void
    {
        $landed = AimedRevision::land($this->design(), [1], $this->answer());
        $callout = $landed[1]['children'][1];

        $this->assertSame(self::PURPOSE, $callout['children'][0]['comment'] ?? null);
        $this->assertSame('ans00001', $callout['children'][1]['uid']);
        $this->assertSame('Who can apply in 2026?', $landed[1]['children'][0]['props']['text']);
    }

    public function test_the_block_keeps_its_id_and_part_marks(): void
    {
        $landed = AimedRevision::land($this->design(), [1], $this->answer());

        $this->assertSame('sect0001', $landed[1]['uid']);
        $this->assertSame('section', $landed[1]['part']);
        $this->assertSame('short_answer', $landed[1]['children'][1]['part']);
    }

    public function test_nothing_outside_the_block_changes(): void
    {
        $before = $this->design();
        $landed = AimedRevision::land($before, [1], $this->answer());

        $this->assertSame($before[0], $landed[0]);
        $this->assertSame($before[2], $landed[2]);
        $this->assertCount(3, $landed);
    }

    public function test_a_note_whose_element_is_gone_stays_inside_the_block_to_be_re_pinned(): void
    {
        $answer = $this->answer();
        // The model removed the short answer altogether.
        $answer['children'] = [$answer['children'][0]];

        $landed = AimedRevision::land($this->design(), [1], $answer);

        $this->assertCount(3, $landed, 'the orphan never lands beside the block');
        $last = end($landed[1]['children']);
        $this->assertSame(self::PURPOSE, $last['comment'] ?? null);
    }

    public function test_a_note_the_model_kept_is_not_doubled(): void
    {
        $answer = $this->answer();
        array_unshift($answer['children'][1]['children'], ['comment' => self::PURPOSE, 'note' => ['for' => 'ans00001', 'author' => 'Template']]);

        $landed = AimedRevision::land($this->design(), [1], $answer);
        $notes = array_filter($landed[1]['children'][1]['children'], static fn (array $n) => isset($n['comment']));

        $this->assertCount(1, $notes);
    }

    public function test_a_path_that_finds_nothing_changes_nothing(): void
    {
        $this->assertSame($this->design(), AimedRevision::land($this->design(), [9], $this->answer()));
    }
}
