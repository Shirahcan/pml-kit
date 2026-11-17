<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\Layout\PinnedNotes;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;

/**
 * A template's notes become studio comments pinned to their elements (owner 2026-11-17: "PML rendered comments (comment
 * element) that are attached to the relevant elements"): the next element in the same list, given an id if it has none;
 * a Purpose note is the template's, a VERIFY note a check; nothing pinned twice; a note with nothing after it stays plain;
 * a resolved check no longer blocks publishing.
 */
class PinnedNotesTest extends TestCase
{
    private static function p(string $words): array
    {
        return ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => $words]]]];
    }

    public function test_each_note_is_pinned_to_the_element_after_it_with_the_templates_or_a_checks_look(): void
    {
        $nodes = PinnedNotes::pin([
            ['comment' => 'Purpose: why it matters.'],
            ['comment' => 'VERIFY: the $85 fee (IRCC fee list)'],
            ['primitive' => 'box', 'part' => 'hook', 'children' => [
                ['comment' => 'Purpose: the first paragraph.'],
                self::p('Hook words'),
            ]],
            ['primitive' => 'heading', 'uid' => 'keepme12', 'props' => ['text' => 'Q', 'level' => 2]],
            ['comment' => 'Purpose: the end, with nothing after it.'],
        ]);

        $box = $nodes[2]['uid'];
        $this->assertMatchesRegularExpression('/^[a-z0-9]{6,16}$/', $box, 'the element got its permanent id');
        $this->assertSame(['for' => $box, 'placement' => 'top-right', 'color' => 'blue', 'author' => 'Template'], $nodes[0]['note']);
        $this->assertSame(['for' => $box, 'placement' => 'top-right', 'color' => 'yellow', 'author' => 'Porter'], $nodes[1]['note']);
        $this->assertSame($nodes[2]['children'][1]['uid'], $nodes[2]['children'][0]['note']['for'], 'a nested note pins to its own next element');
        $this->assertSame('keepme12', $nodes[3]['uid'], 'an existing id is kept');
        $this->assertArrayNotHasKey('note', $nodes[4], 'nothing after it: it stays a plain note');
        $this->assertFalse(PinnedNotes::hasLoose($nodes));
        $this->assertTrue(PinnedNotes::hasLoose([['comment' => 'Purpose: x'], self::p('y')]));

        $this->assertSame($nodes, PinnedNotes::pin($nodes), 'pinning twice changes nothing');
    }

    public function test_a_pinned_note_round_trips_through_code_as_a_comment_element(): void
    {
        $nodes = PinnedNotes::pin([['comment' => 'Purpose: why it matters.'], ['primitive' => 'heading', 'uid' => 'abc12345', 'props' => ['text' => 'Q', 'level' => 2]]]);
        $source = (new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]);

        $this->assertStringContainsString('<comment for="abc12345"', $source);
        $back = (new PmlParser(new PmlScanner(), new MarkdownToDoc()))->parse($source)['nodes'] ?? [];
        $this->assertSame('abc12345', $back[0]['note']['for'] ?? null);
        $this->assertSame('Purpose: why it matters.', $back[0]['comment'] ?? null);
    }

    public function test_a_resolved_check_no_longer_blocks_publishing(): void
    {
        $layout = LayoutDefinition::fromArray(['key' => 'mini', 'name' => 'Mini', 'kind' => 'post', 'parts' => [
            ['key' => 'hook', 'label' => 'Hook', 'kind' => 'fill'],
        ]]);
        $nodes = PinnedNotes::pin([['comment' => 'VERIFY: the fee'], ['primitive' => 'box', 'part' => 'hook', 'children' => [self::p('The fee is listed.')]]]);

        $this->assertFalse(LayoutChecks::check($layout, $nodes)['mayPublish']);

        $nodes[0]['note']['resolved'] = true;
        $this->assertTrue(LayoutChecks::check($layout, $nodes)['mayPublish'], 'resolving the check settles it, as deleting it does');
    }
}
