<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailBuild;
use Shirahcan\Pml\Email\EmailProfile;
use Shirahcan\Pml\Email\EmailSpec;

/**
 * One console Build on an email design (blog on growth-service N1h-4): the same for a newsletter and a PML email
 * template. Guards: notes never reach the model and come back pinned where they were; an aimed ask changes only its
 * block; the email profile gets ONE repair and a refusal changes nothing; the spec is derived, never restated.
 */
final class EmailBuildTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function design(): array
    {
        return [
            ['comment' => 'Purpose: the one big change this week.', 'note' => ['for' => 'hone01abc', 'author' => 'Template']],
            ['primitive' => 'heading', 'uid' => 'hone01abc', 'part' => 'lead', 'props' => ['text' => '[One headline]', 'level' => 2]],
            ['primitive' => 'callout', 'uid' => 'cone01abc', 'children' => [
                ['comment' => 'VERIFY: the cut-off score.', 'note' => ['for' => 'bone01abc']],
                ['primitive' => 'button', 'uid' => 'bone01abc', 'props' => ['label' => 'Read the guide', 'href' => 'https://example.org/guide']],
            ]],
        ];
    }

    public function test_the_whole_email_is_rewritten_without_its_notes_and_they_come_back_on_their_elements(): void
    {
        $seen = [];
        $result = (new EmailBuild)->run($this->design(), null, function (string $email, ?string $block, array $problems) use (&$seen) {
            $seen[] = compact('email', 'block', 'problems');

            return '<heading uid="hone01abc" part="lead" text="2,500 invitations this week" level="2"/>'."\n"
                .'<callout uid="cone01abc"><button uid="bone01abc" label="See the draw" href="https://example.org/draw"/></callout>'
                ."\n".EmailBuild::REPLY_MARK."\nI wrote the headline from the draw.\nIssue: check the cut-off against the rounds page.";
        });

        $this->assertCount(1, $seen, 'no repair was needed');
        $this->assertStringNotContainsString('Purpose', $seen[0]['email'], 'notes never go to the model');
        $this->assertStringNotContainsString('VERIFY', $seen[0]['email']);
        $this->assertStringContainsString('uid="hone01abc"', $seen[0]['email']);
        $this->assertNull($seen[0]['block']);

        $this->assertTrue($result['changed']);
        $this->assertFalse($result['refused']);
        $this->assertSame('page', $result['mode']);
        // The Purpose note is back before its heading; the VERIFY note back before its button, inside the callout.
        $this->assertSame('Purpose: the one big change this week.', $result['nodes'][0]['comment']);
        $this->assertSame('2,500 invitations this week', $result['nodes'][1]['props']['text']);
        $this->assertSame('lead', $result['nodes'][1]['part']);
        $this->assertSame('VERIFY: the cut-off score.', $result['nodes'][2]['children'][0]['comment']);
        $this->assertSame('See the draw', $result['nodes'][2]['children'][1]['props']['label']);
        $this->assertSame('I wrote the headline from the draw.', $result['reply']);
        $this->assertSame(['Issue: check the cut-off against the rounds page.'], $result['notes']);
    }

    public function test_an_aimed_ask_rewrites_only_its_block_and_the_block_keeps_its_note(): void
    {
        $result = (new EmailBuild)->run($this->design(), [2], function (string $email, ?string $block) {
            $this->assertNotNull($block);
            $this->assertStringContainsString('uid="cone01abc"', $block);
            $this->assertStringNotContainsString('VERIFY', $block);

            return '<callout uid="cone01abc"><button uid="bone01abc" label="Book a call" href="https://example.org/book"/></callout>'
                ."\n".EmailBuild::REPLY_MARK."\nI changed the button.";
        });

        $this->assertSame('element', $result['mode']);
        $this->assertSame('Book a call', $result['node']['children'][1]['props']['label']);
        $this->assertSame('VERIFY: the cut-off score.', $result['node']['children'][0]['comment']);
        // Everything outside the block is exactly as it was, its note included.
        $this->assertSame(array_slice($this->design(), 0, 2), array_slice($result['nodes'], 0, 2));
    }

    public function test_an_aimed_rewrite_keeps_the_templates_part_marks_the_answer_dropped(): void
    {
        // 0.31.1: an aimed answer comes back without `part` (its vocabulary has none); the email would then fail its layout
        // check for a missing part. The marks go back by uid, and the block's own mark on the block.
        $design = [
            ['uid' => 'sone01abc', 'primitive' => 'box', 'part' => 'section', 'children' => [
                ['uid' => 'hone01abc', 'primitive' => 'heading', 'part' => 'question', 'props' => ['text' => 'Can I work?', 'level' => 2]],
                ['uid' => 'pone01abc', 'primitive' => 'p', 'part' => 'answer', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Most can.']]]],
            ]],
        ];

        $result = (new EmailBuild)->run($design, [0], fn () => '<box uid="sone01abc"><heading uid="hone01abc" text="Can I work after I graduate?" level="2"/>'
            .'<p uid="pone01abc">Most graduates can apply.</p></box>'."
".EmailBuild::REPLY_MARK."
I made it clearer.");

        $this->assertTrue($result['changed']);
        $this->assertSame('section', $result['node']['part']);
        $this->assertSame('question', $result['node']['children'][0]['part']);
        $this->assertSame('answer', $result['node']['children'][1]['part']);
        $this->assertSame('section', $result['nodes'][0]['part'], 'the whole email carries them too: that is what a worker saves');
        $this->assertSame('Can I work after I graduate?', $result['nodes'][0]['children'][0]['props']['text']);
    }

    public function test_what_the_email_profile_refuses_gets_one_repair_and_a_second_refusal_changes_nothing(): void
    {
        $asks = [];
        $bad = '<heading uid="hone01abc" text="Big news" level="2"/>'."\n".'<video src="https://example.org/v.mp4"/>'."\n".EmailBuild::REPLY_MARK."\nDone.";
        $result = (new EmailBuild)->run($this->design(), null, function (string $email, ?string $block, array $problems) use (&$asks, $bad) {
            $asks[] = $problems;

            return $bad;
        });

        $this->assertCount(2, $asks, 'exactly one repair');
        $this->assertSame([], $asks[0]);
        $this->assertNotSame([], $asks[1], 'the repair is told what the profile refused');
        $this->assertTrue($result['refused']);
        $this->assertFalse($result['changed']);
        $this->assertSame($this->design(), $result['nodes'], 'a refusal changes nothing');
        $this->assertSame('A video cannot go in an email (mail clients cannot show it).', $result['reason'], "in the profile's own words");
    }

    public function test_an_unreadable_or_empty_answer_is_repaired_once(): void
    {
        $answers = ['', '<heading uid="hone01abc" text="Fine now" level="2"/>'."\n".EmailBuild::REPLY_MARK."\nFixed."];
        $result = (new EmailBuild)->run($this->design(), null, function () use (&$answers) {
            return array_shift($answers);
        });

        $this->assertTrue($result['repaired']);
        $this->assertFalse($result['refused']);
        $this->assertSame('Fine now', $result['nodes'][1]['props']['text'] ?? $result['nodes'][0]['props']['text'] ?? null);
    }

    public function test_an_element_the_answer_did_not_change_keeps_its_stored_form_so_only_real_edits_differ(): void
    {
        // As growth-service stores a template's starter: empty props arrays and the level as a string.
        $stored = [
            ['primitive' => 'box', 'uid' => 'xone01abc', 'part' => 'hero', 'props' => [], 'children' => [
                ['primitive' => 'heading', 'uid' => 'hone01abc', 'props' => ['text' => '[One headline]', 'level' => '1']],
            ]],
            ['primitive' => 'heading', 'uid' => 'htwo01abc', 'props' => ['text' => 'This week at a glance', 'level' => '2']],
        ];

        $result = (new EmailBuild)->run($stored, null, fn () => '<box uid="xone01abc" part="hero"><heading uid="hone01abc" text="Draw 330: 2,500 invitations" level="1"/></box>'
            ."\n".'<heading uid="htwo01abc" text="This week at a glance" level="2"/>'."\n".EmailBuild::REPLY_MARK."\nI wrote the headline.");

        $this->assertTrue($result['changed']);
        // Untouched elements are exactly as stored (comparison only; the stored shape is never rewritten).
        $this->assertSame($stored[1], $result['nodes'][1]);
        $this->assertSame([], $result['nodes'][0]['props']);
        $this->assertSame('hero', $result['nodes'][0]['part']);
        // The one real edit.
        $this->assertSame('Draw 330: 2,500 invitations', $result['nodes'][0]['children'][0]['props']['text']);
    }

    /**
     * Portify 2026-11-23 (a newsletter template's console, Loop 1): a paragraph the Build ADDED came back with no id, so
     * the console's "What changed" could not name it ("Changed the design"), and it could not be aimed at or pinned.
     * Every element keeps its id or gets one, as the page Build does; an id alone is never a change.
     */
    public function test_an_added_element_gets_its_permanent_id_and_an_id_alone_is_no_change(): void
    {
        $result = (new EmailBuild)->run($this->design(), null, fn () => '<heading uid="hone01abc" part="lead" text="[One headline]" level="2"/>'."\n"
            .'<callout uid="cone01abc"><button uid="bone01abc" label="Read the guide" href="https://example.org/guide"/></callout>'."\n"
            ."<p>Thanks for reading this week.</p>\n".EmailBuild::REPLY_MARK."\nI added a closing line.");

        $this->assertTrue($result['changed']);
        $added = end($result['nodes']);
        $this->assertSame('p', $added['primitive']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6,16}$/', (string) ($added['uid'] ?? ''), 'the added paragraph has its permanent id');
        $this->assertSame(['hone01abc', 'cone01abc'], [$result['nodes'][1]['uid'], $result['nodes'][2]['uid']], 'every other element keeps its own');
        $this->assertArrayNotHasKey('uid', $added['children'][0] ?? [], 'a word run carries no id');

        // An email stored before ids existed, answered unchanged: it gets its ids, and that alone is not a change.
        $bare = [['primitive' => 'heading', 'props' => ['text' => 'This week at a glance', 'level' => 2]]];
        $same = (new EmailBuild)->run($bare, null, fn () => '<heading text="This week at a glance" level="2"/>'."\n".EmailBuild::REPLY_MARK."\nNothing to change.");
        $this->assertFalse($same['changed']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6,16}$/', (string) ($same['nodes'][0]['uid'] ?? ''));

        // An aimed ask hands back its block WITH the id it has in the email.
        $aimed = (new EmailBuild)->run($bare, [0], fn () => '<heading text="This week, at a glance" level="2"/>'."\n".EmailBuild::REPLY_MARK."\nA comma.");
        $this->assertSame($aimed['nodes'][0]['uid'], $aimed['node']['uid']);
    }

    public function test_the_spec_is_the_email_profile_never_restated(): void
    {
        $spec = EmailSpec::describe();

        foreach (EmailProfile::ELEMENTS as $element) {
            if (in_array($element, ['header', 'footer'], true)) {
                $this->assertStringNotContainsString("  <{$element}>", $spec, 'the standard chrome is never written into a body');

                continue;
            }
            $this->assertStringContainsString("  <{$element}>", $spec);
        }
        $this->assertStringNotContainsString('<carousel>', $spec);
        $this->assertStringContainsString('KEEP every uid="..." and part="..."', $spec);
    }
}
