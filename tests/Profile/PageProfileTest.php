<?php

namespace Shirahcan\Pml\Tests\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PmlComponentExpander;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSnapshotBuilder;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\Profile\PageProfile;
use Shirahcan\Pml\Profile\Profiles;
use Shirahcan\Pml\StandardLibrary;

/**
 * The page profile is EXACTLY today's page save gate (shared PML Studio P1a-2): the verdict and reason of
 * PmlSnapshotBuilder::validate with the site's saved sections, the call Portify's SitePageWorkspace::validateTree makes.
 */
final class PageProfileTest extends TestCase
{
    /** @return array<string, array{0: list<array<string, mixed>>}> */
    public static function pages(): array
    {
        $title = ['primitive' => 'heading', 'props' => ['text' => 'Hello', 'level' => 2]];

        return [
            'a valid page' => [[$title, ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi']]]]]],
            'empty' => [[]],
            'notes anywhere' => [[['comment' => 'Purpose: x'], ['primitive' => 'box', 'children' => [['comment' => 'VERIFY: y']]]]],
            'the site\'s saved section' => [[['component' => 'site_band', 'props' => []]]],
            'a section the site does not have' => [[$title, ['component' => 'no_such_section']]],
            'an unknown element, nested' => [[$title, ['primitive' => 'box', 'children' => [['primitive' => 'zzz']]]]],
            'a smuggled attribute, nested' => [[['primitive' => 'box', 'children' => [['primitive' => 'button', 'props' => ['label' => 'Go', 'onclick' => 'x']]]]]],
            'a post piece (today: allowed on a page)' => [[['primitive' => 'post_title']]],
        ];
    }

    #[DataProvider('pages')]
    public function test_the_verdict_and_reason_are_the_save_gates(array $nodes): void
    {
        $found = (new PageProfile(self::partials()))->violations($nodes);

        try {
            self::builder()->validate($nodes, self::partials());
            $this->assertSame([], $found);
        } catch (InvalidPmlException $e) {
            $this->assertCount(1, $found, 'the gate stops at its first problem');
            $this->assertSame(['invalid', $e->getMessage()], [$found[0]['code'], $found[0]['text']]);
        }
    }

    public function test_it_says_which_top_level_element_holds_the_problem(): void
    {
        $title = ['primitive' => 'heading', 'props' => ['text' => 'Hello', 'level' => 2]];
        $found = (new PageProfile())->violations([$title, $title, ['primitive' => 'box', 'children' => [['primitive' => 'zzz']]]]);

        $this->assertSame('3', $found[0]['at']);
    }

    public function test_without_the_sites_sections_a_page_using_one_is_refused(): void
    {
        $page = [['component' => 'site_band', 'props' => []]];

        $this->assertSame([], (new PageProfile(self::partials()))->violations($page));
        $this->assertNotSame([], Profiles::for('page')->violations($page), 'Profiles::for(\'page\') knows only the standard library');
    }

    public function test_a_page_may_hold_the_whole_catalog_and_has_no_pieces(): void
    {
        $this->assertNull(Profiles::for('page')->elements());
        $this->assertSame([], Profiles::for('page')->pieces());
    }

    /** @return array<string, callable> */
    private static function partials(): array
    {
        return ['site_band' => fn (array $props, array $children): array => ['primitive' => 'box', 'children' => [['primitive' => 'heading', 'props' => ['text' => 'Band', 'level' => 2]]]]];
    }

    private static function builder(): PmlSnapshotBuilder
    {
        $blocks = new BlockSchema();

        return new PmlSnapshotBuilder(
            new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack()),
            new PmlComponentExpander(new PmlParser(new PmlScanner(), new MarkdownToDoc())),
            new StandardLibrary(),
            new ComplianceRulePack(),
        );
    }
}
