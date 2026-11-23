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

    public function test_a_moved_element_or_reordered_keys_are_not_a_change(): void
    {
        $after = [self::PAGE[1], ['primitive' => 'section', 'uid' => 'sec1', 'children' => array_reverse(self::PAGE[0]['children']), 'props' => []]];
        $after[1]['children'][1]['props'] = ['level' => 2, 'text' => 'Pricing'];

        $this->assertSame([], DesignChanges::between(self::PAGE, $after)['changes']);
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
