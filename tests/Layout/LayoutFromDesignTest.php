<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\Layout\PinnedNotes;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * Growth templates A1 (owner 2026-11-20: "templates could be created from a post"): a layout read back from a design.
 */
class LayoutFromDesignTest extends TestCase
{
    private function fullGuide(): LayoutDefinition
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/layouts/post.full-guide.json'), true);

        return LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));
    }

    /** @return list<array<string, mixed>> the parts as the layout's own words, without skeletons */
    private static function shape(LayoutDefinition $def): array
    {
        return array_map(static function (array $p) {
            unset($p['skeleton']);

            return $p;
        }, $def->parts);
    }

    private static function texts(array $nodes): array
    {
        $out = [];
        array_walk_recursive($nodes, function ($v, $k) use (&$out) {
            if ($k === 'value' && is_string($v)) {
                $out[] = $v;
            }
        });

        return $out;
    }

    public function test_a_fresh_post_read_back_is_its_layout(): void
    {
        $layout = $this->fullGuide();
        // As a new post opens: the starter with its notes pinned to their elements.
        $post = PinnedNotes::pin($layout->starter());

        $back = LayoutDefinition::fromDesign($post, 'full-guide', 'Full guide', 'post', 'keep', $layout);

        $this->assertSame(self::shape($layout), self::shape($back), 'parts, order, kinds, counts, rules');
        $this->assertSame(self::texts($layout->starter()), self::texts($back->starter()), 'the same words');
        $findings = static fn (array $r) => array_map(static fn ($f) => $f['severity'].' '.$f['rule'].' '.$f['part'], $r['findings']);
        $this->assertSame($findings(LayoutChecks::check($layout, $layout->starter())), $findings(LayoutChecks::check($back, $back->starter())), 'a fresh post from it is checked exactly as one from its layout');
    }

    public function test_a_fresh_newsletter_read_back_is_its_email_layout(): void
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/layouts/email.portify-weekly.json'), true);
        $layout = LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));

        $back = LayoutDefinition::fromDesign(PinnedNotes::pin($layout->starter()), $layout->key, $layout->name, 'email', 'keep', $layout);

        $this->assertSame(self::shape($layout), self::shape($back));
        $this->assertSame(self::texts($layout->starter()), self::texts($back->starter()));
    }

    public function test_the_words_are_kept_as_examples_or_turned_into_guidance(): void
    {
        $layout = $this->fullGuide();
        $post = PinnedNotes::pin($layout->starter());
        $hookAt = array_search('hook', array_column($post, 'part'), true);
        $post[$hookAt]['children'][0]['props']['value'] = 'An approved LMIA is not a work permit. Here is what to do next.';

        $kept = LayoutDefinition::fromDesign($post, 'from-post', 'From a post', 'post', 'keep', $layout);
        $this->assertSame('An approved LMIA is not a work permit. Here is what to do next.', $kept->part('hook')['skeleton'][1]['children'][0]['props']['value'] ?? $kept->part('hook')['skeleton'][0]['children'][0]['props']['value']);

        $guided = LayoutDefinition::fromDesign($post, 'from-post', 'From a post', 'post', 'guidance', $layout);
        $this->assertSame(self::texts($layout->part('hook')['skeleton']), self::texts($guided->part('hook')['skeleton']), 'the hook says what goes there again');
    }

    public function test_a_post_with_more_question_sections_keeps_one_repeating_part_and_its_counts(): void
    {
        $layout = $this->fullGuide();
        $post = PinnedNotes::pin($layout->starter());
        $sectionAt = array_search('section', array_column($post, 'part'), true);
        array_splice($post, $sectionAt, 0, [$post[$sectionAt], $post[$sectionAt]]);

        $back = LayoutDefinition::fromDesign($post, 'from-post', 'From a post', 'post', 'keep', $layout);

        $this->assertSame(1, count(array_filter($back->parts, static fn ($p) => $p['key'] === 'section')));
        $this->assertSame($layout->part('section')['min'], $back->part('section')['min']);
        $this->assertSame($layout->part('section')['max'], $back->part('section')['max']);
        $notes = array_filter($back->part('section')['skeleton'], static fn ($n) => isset($n['comment']));
        $this->assertCount(count(array_filter($layout->part('section')['skeleton'], static fn ($n) => isset($n['comment']))), $notes, 'its purpose note once, not once per section');
    }

    public function test_a_posts_own_checks_and_ids_are_left_out(): void
    {
        $layout = $this->fullGuide();
        $post = PinnedNotes::pin($layout->starter());
        $hookAt = array_search('hook', array_column($post, 'part'), true);
        array_splice($post, $hookAt, 0, [['comment' => 'VERIFY: the fee', 'note' => ['for' => $post[$hookAt]['uid'], 'author' => PinnedNotes::CHECK_AUTHOR]]]);

        $back = LayoutDefinition::fromDesign($post, 'from-post', 'From a post', 'post', 'keep', $layout);

        $json = (string) json_encode($back->toArray());
        $this->assertStringNotContainsString('VERIFY: the fee', $json);
        $this->assertStringNotContainsString('"uid"', $json);
        $this->assertStringNotContainsString('"note"', $json, 'notes are plain again; a new post pins them');
    }

    public function test_a_design_without_marks_becomes_ordered_sections(): void
    {
        $p = static fn (string $w) => ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => $w]]]];
        $nodes = [
            ['primitive' => 'heading', 'props' => ['text' => 'Fees', 'level' => 2]],
            $p('Biometrics cost 85 CAD.'),
        ];

        $back = LayoutDefinition::fromDesign($nodes, 'plain', 'Plain', 'post', 'guidance');

        $this->assertSame(['section-1'], array_column($back->parts, 'key'));
        $this->assertSame('fill', $back->parts[0]['kind']);
        $this->assertSame(['[The section 1 goes here]'], self::texts($back->parts[0]['skeleton']));
    }

    public function test_an_empty_design_or_an_unknown_word_choice_is_refused(): void
    {
        $this->expectException(InvalidPmlException::class);
        LayoutDefinition::fromDesign([], 'x', 'X', 'post', 'keep');
    }
}
