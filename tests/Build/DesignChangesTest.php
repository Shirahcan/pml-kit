<?php

namespace Shirahcan\Pml\Tests\Build;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Build\DesignChanges;

/**
 * "What changed" (owner 2026-11-09): the elements an ask changed, by id, in the person's words.
 */
final class DesignChangesTest extends TestCase
{
    private const PAGE = [
        ['primitive' => 'section', 'uid' => 'sec1', 'props' => [], 'children' => [
            ['primitive' => 'heading', 'uid' => 'h1', 'props' => ['text' => 'Pricing', 'level' => 2]],
            ['primitive' => 'text', 'uid' => 't1', 'props' => ['value' => 'Plans for every size']],
        ]],
        ['component' => 'cta-banner', 'uid' => 'cta', 'props' => ['body' => '{site.name}']],
    ];

    public function test_nothing_changed_is_an_empty_list(): void
    {
        $this->assertSame(['changes' => [], 'total' => 0, 'counts' => ['added' => 0, 'changed' => 0, 'removed' => 0]], DesignChanges::between(self::PAGE, self::PAGE));
    }

    public function test_a_changed_child_lists_the_child_not_its_section(): void
    {
        $after = self::PAGE;
        $after[0]['children'][0]['props']['text'] = 'Simple pricing';

        $this->assertSame([['uid' => 'h1', 'label' => 'heading “Simple pricing”', 'how' => 'changed']], DesignChanges::between(self::PAGE, $after)['changes']);
    }

    public function test_a_new_part_is_listed_once_at_its_top_and_a_removed_one_likewise(): void
    {
        $after = self::PAGE;
        $after[] = ['primitive' => 'row', 'uid' => 'row9', 'props' => [], 'children' => [['primitive' => 'button', 'uid' => 'b9', 'props' => ['label' => 'Book a call']]]];
        unset($after[0]);
        $after = array_values($after);

        $result = DesignChanges::between(self::PAGE, $after);

        $this->assertSame([
            ['uid' => 'row9', 'label' => 'row', 'how' => 'added'],
            ['uid' => 'sec1', 'label' => 'section “Pricing”', 'how' => 'removed'],
        ], $result['changes']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(['added' => 1, 'changed' => 0, 'removed' => 1], $result['counts']);
    }

    public function test_a_compare_lists_every_change_and_the_console_keeps_twelve(): void
    {
        $before = [];
        for ($i = 0; $i < 15; $i++) {
            $before[] = ['primitive' => 'heading', 'uid' => 'h'.$i, 'props' => ['text' => 'Question '.$i]];
        }

        $this->assertCount(12, DesignChanges::between($before, [])['changes']);
        $all = DesignChanges::between($before, [], [], null);
        $this->assertCount(15, $all['changes']);
        $this->assertSame(15, $all['counts']['removed']);
    }

    public function test_the_sentence_names_what_was_added_and_removed_and_counts_what_changed(): void
    {
        $after = self::PAGE;
        $after[0]['children'][0]['props']['text'] = 'Simple pricing';
        $after[] = ['primitive' => 'button', 'uid' => 'b9', 'props' => ['label' => 'Book a call']];
        unset($after[1]);
        $after = array_values($after);

        $this->assertSame(
            'added 1 element (the button “Book a call”), changed 1 element, removed 1 element (the cta banner)',
            DesignChanges::sentence(DesignChanges::between(self::PAGE, $after)),
        );
        $this->assertSame('', DesignChanges::sentence(DesignChanges::between(self::PAGE, self::PAGE)));
    }

    public function test_the_sentence_says_there_are_more_than_it_names(): void
    {
        $before = [];
        for ($i = 0; $i < 8; $i++) {
            $before[] = ['primitive' => 'heading', 'uid' => 'h'.$i, 'props' => ['text' => 'Q'.$i]];
        }

        $words = DesignChanges::sentence(DesignChanges::between($before, [], [], null));

        $this->assertStringStartsWith('removed 8 elements (the heading “Q0”', $words);
        $this->assertStringEndsWith('the heading “Q5”, ...)', $words);
    }

    public function test_a_moved_element_or_reordered_keys_are_not_a_change(): void
    {
        $after = [self::PAGE[1], ['primitive' => 'section', 'uid' => 'sec1', 'children' => array_reverse(self::PAGE[0]['children']), 'props' => []]];
        $after[1]['children'][1]['props'] = ['level' => 2, 'text' => 'Pricing'];

        $this->assertSame([], DesignChanges::between(self::PAGE, $after)['changes']);
    }

    public function test_empty_settings_written_or_left_out_are_the_same_element(): void
    {
        // A stored post writes `props: []`; the same design parsed back leaves `props` out. Neither is a change.
        $stored = [['primitive' => 'box', 'part' => 'header', 'props' => [], 'uid' => 'box001ab', 'children' => [
            ['primitive' => 'post_breadcrumbs', 'props' => [], 'uid' => 'crumb01a'],
        ]]];
        $parsed = [['primitive' => 'box', 'uid' => 'box001ab', 'part' => 'header', 'children' => [
            ['primitive' => 'post_breadcrumbs', 'uid' => 'crumb01a'],
        ]]];

        $this->assertSame([], DesignChanges::between($stored, $parsed)['changes']);
        $parsed[0]['children'][0]['props'] = ['separator' => '/'];
        $this->assertSame([['uid' => 'crumb01a', 'label' => 'post breadcrumbs', 'how' => 'changed']], DesignChanges::between($stored, $parsed)['changes'], 'a real setting is still a change');
    }

    public function test_a_setting_stored_as_words_or_a_number_and_an_empty_text_run_are_not_changes(): void
    {
        // Portify TEST 2026-11-23: the stored post had level "2" and an empty paragraph holding an empty text run; the
        // same design parsed back has level 2 and a paragraph with no runs. "Changed 19 elements" for one added line.
        $stored = [
            ['primitive' => 'heading', 'props' => ['text' => 'Fees', 'level' => '2'], 'uid' => 'head01ab'],
            ['primitive' => 'p', 'props' => [], 'uid' => 'para01ab', 'children' => [['primitive' => 'text', 'props' => ['value' => '']]]],
            ['primitive' => 'p', 'uid' => 'para02ab', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi', 'bold' => 'true'], 'children' => []]]],
        ];
        $parsed = [
            ['primitive' => 'heading', 'uid' => 'head01ab', 'props' => ['level' => 2, 'text' => 'Fees']],
            ['primitive' => 'p', 'uid' => 'para01ab'],
            ['primitive' => 'p', 'uid' => 'para02ab', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi', 'bold' => true]]]],
        ];

        $this->assertSame([], DesignChanges::between($stored, $parsed)['changes']);

        $changed = $parsed;
        $changed[0]['props']['level'] = 3;
        $changed[1]['children'] = [['primitive' => 'text', 'props' => ['value' => 'Now it says something']]];
        $this->assertSame(['head01ab', 'para01ab'], array_column(DesignChanges::between($stored, $changed)['changes'], 'uid'), 'a real change of level or words is still a change');
    }

    public function test_a_binding_is_not_used_as_a_name_and_long_words_are_cut(): void
    {
        $this->assertSame('cta banner', DesignChanges::label(self::PAGE[1]));
        $this->assertSame('text “'.str_repeat('a', 39).'…”', DesignChanges::label(['primitive' => 'text', 'props' => ['value' => str_repeat('a', 60)]]));
    }

    public function test_an_element_that_shows_a_components_setting_is_named_by_its_words(): void
    {
        // Owner 2026-11-10: "Changed the heading" said nothing for a heading bound to the Title setting.
        $before = [['primitive' => 'box', 'uid' => 'b1', 'props' => [], 'children' => [
            ['primitive' => 'heading', 'uid' => 'h1', 'props' => ['text' => \Shirahcan\Pml\PmlExpression::of('props.title'), 'level' => 2]],
        ]]];
        $after = $before;
        $after[0]['children'][0]['props']['level'] = 3;
        $settings = DesignChanges::settingsOf(
            [['name' => 'title', 'label' => 'Title', 'default' => 'Old plan']],
            [['name' => 'title', 'label' => 'Title', 'default' => 'Pricing plan'], ['name' => 'note', 'label' => 'Note', 'default' => '']],
        );

        $this->assertSame(['title' => 'Pricing plan', 'note' => 'Note'], $settings, 'the later list wins; no default falls back to the setting\'s name');
        $this->assertSame([['uid' => 'h1', 'label' => 'heading “Pricing plan”', 'how' => 'changed']], DesignChanges::between($before, $after, $settings)['changes']);
        $this->assertSame('box “Pricing plan”', DesignChanges::label($before[0], $settings), 'a box is still named by the heading inside it');
        $this->assertSame('heading', DesignChanges::label($before[0]['children'][0]), 'without the settings it stays unnamed, as before');
        $this->assertSame('text', DesignChanges::label(['primitive' => 'text', 'props' => ['value' => \Shirahcan\Pml\PmlExpression::of('site.name')]], $settings), 'a page binding is not a setting');
        $this->assertSame([], DesignChanges::settingsOf([['label' => 'No name'], 'junk', ['name' => 'blank', 'label' => '', 'default' => '  ']]));
    }

    public function test_the_list_is_capped_but_the_total_is_not(): void
    {
        $after = self::PAGE;

        for ($i = 0; $i < 20; $i++) {
            $after[] = ['primitive' => 'text', 'uid' => 'n'.$i, 'props' => ['value' => 'Line '.$i]];
        }

        $result = DesignChanges::between(self::PAGE, $after);

        $this->assertCount(DesignChanges::MAX, $result['changes']);
        $this->assertSame(20, $result['total']);
        $this->assertSame(20, $result['counts']['added'], 'the counts are over every change, not the listed dozen');
    }

    public function test_prose_is_named_in_plain_words_by_its_own_text(): void
    {
        // An email's paragraph read "Changed the p" (N1h-4d Loop 1, 2026-11-18): its words are its text runs.
        $greeting = ['primitive' => 'p', 'uid' => 'p1', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi {{first_name}},']]]];
        $this->assertSame('paragraph “Hi {{first_name}},”', DesignChanges::label($greeting));

        $mixed = ['primitive' => 'small', 'children' => [
            ['primitive' => 'text', 'props' => ['value' => 'Questions? Email ']],
            ['primitive' => 'link', 'props' => ['href' => 'mailto:x'], 'children' => [['primitive' => 'text', 'props' => ['value' => 'us']]]],
        ]];
        $this->assertSame('fine print “Questions? Email us”', DesignChanges::label($mixed));
        $this->assertSame('paragraph', DesignChanges::label(['primitive' => 'p', 'children' => []]));
        $this->assertSame('callout “Bring your passport”', DesignChanges::label(['primitive' => 'callout', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Bring your passport']]]]]]));

        $after = [['primitive' => 'p', 'uid' => 'p1', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hello again,']]]]];
        $this->assertSame([['uid' => 'p1', 'label' => 'paragraph “Hello again,”', 'how' => 'changed']], DesignChanges::between([$greeting], $after)['changes']);
    }
}
