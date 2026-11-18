<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\PmlTree;

/**
 * Walking a design by path, shared (0.32.0): a worker that saves an aimed result rebuilds the whole design with it, so
 * the walk must touch exactly the aimed element and keep the template's part marks the rewrite dropped.
 */
class PmlTreeTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private function design(): array
    {
        return [
            ['uid' => 'hone01abc', 'primitive' => 'heading', 'props' => ['text' => 'Title']],
            ['uid' => 'sone01abc', 'primitive' => 'box', 'part' => 'section', 'children' => [
                ['uid' => 'qone01abc', 'primitive' => 'heading', 'part' => 'question', 'props' => ['text' => 'Q?']],
                ['uid' => 'aone01abc', 'primitive' => 'p', 'part' => 'answer', 'children' => [['primitive' => 'text', 'props' => ['value' => 'A.']]]],
            ]],
        ];
    }

    public function test_node_at_follows_a_path_and_answers_null_where_it_leads_nowhere(): void
    {
        $this->assertSame('qone01abc', PmlTree::nodeAt($this->design(), [1, 0])['uid']);
        $this->assertNull(PmlTree::nodeAt($this->design(), [1, 5]));
        $this->assertNull(PmlTree::nodeAt($this->design(), [0, 0]), 'a heading has no children');
        $this->assertNull(PmlTree::nodeAt($this->design(), []));
    }

    public function test_replace_at_changes_only_the_aimed_element(): void
    {
        $next = PmlTree::replaceAt($this->design(), [1, 1], ['uid' => 'aone01abc', 'primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Better.']]]]);

        $this->assertSame('Better.', $next[1]['children'][1]['children'][0]['props']['value']);
        $this->assertSame($this->design()[0], $next[0]);
        $this->assertSame($this->design()[1]['children'][0], $next[1]['children'][0]);
        $this->assertSame('section', $next[1]['part']);
        $this->assertSame($this->design(), PmlTree::replaceAt($this->design(), [], ['primitive' => 'p']), 'an empty path changes nothing');
    }

    public function test_keep_parts_puts_back_the_marks_a_rewrite_dropped_by_uid_and_on_the_block(): void
    {
        $revised = ['uid' => 'sone01abc', 'primitive' => 'box', 'children' => [
            ['uid' => 'qone01abc', 'primitive' => 'heading', 'props' => ['text' => 'Can I?']],
            ['uid' => 'aone01abc', 'primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Yes.']]]],
        ]];

        $kept = PmlTree::keepParts($this->design()[1], $revised);

        $this->assertSame('section', $kept['part']);
        $this->assertSame('question', $kept['children'][0]['part']);
        $this->assertSame('answer', $kept['children'][1]['part']);
        $this->assertSame('Can I?', $kept['children'][0]['props']['text'], 'the words are the rewrite\'s');
    }

    public function test_keep_parts_never_overrides_a_mark_the_rewrite_set_itself(): void
    {
        $kept = PmlTree::keepParts(['uid' => 'x1', 'part' => 'section'], ['uid' => 'x1', 'part' => 'intro']);

        $this->assertSame('intro', $kept['part']);
    }
}
