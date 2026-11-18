<?php

namespace Shirahcan\Pml\Tests\Profile;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\ElementsTypescript;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\Profile\PostProfile;
use Shirahcan\Pml\Profile\Profiles;

/**
 * The post profile is EXACTLY today's post rules (shared PML Studio P1a-2 / P1b): the save gate's verdict and first
 * reason (growth-service PostDocument::validated), the pieces pml-ui hard-coded (PIECE_FIELDS, PIECE_TEXT_FIELD) and
 * what an aimed ask drafts (Portify GrowthPostConsoleController::DRAFTS, onlyPostPieces, draftableFor). Each "today"
 * below is a verbatim copy of the code it replaces, so a difference is a failing test, not a silent change.
 */
final class PostProfileTest extends TestCase
{
    /** @return array<string, array{0: list<mixed>}> */
    public static function designs(): array
    {
        $title = ['primitive' => 'heading', 'props' => ['text' => 'Hello', 'level' => 2]];
        $words = ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi']]]];

        return [
            'a valid post' => [[$title, $words, ['primitive' => 'post_title'], ['comment' => 'Purpose: the opening']]],
            'empty' => [[]],
            'a component at the top' => [[$title, ['component' => 'hero', 'props' => []]]],
            'a component inside a box (today: saved)' => [[['primitive' => 'box', 'children' => [['component' => 'hero', 'props' => []]]]]],
            'an unknown element' => [[$title, ['primitive' => 'zzz']]],
            'an unknown element inside a box (today: saved)' => [[['primitive' => 'box', 'children' => [['primitive' => 'zzz']]]]],
            'a smuggled attribute' => [[['primitive' => 'button', 'props' => ['label' => 'Go', 'onclick' => 'x']]]],
            'not an element' => [['words']],
            'a comment with something on it' => [[['comment' => 'x', 'style' => []]]],
            'several problems' => [[['primitive' => 'zzz'], ['component' => 'hero'], 'words']],
        ];
    }

    #[DataProvider('designs')]
    public function test_the_verdict_and_first_reason_are_the_save_gates(array $nodes): void
    {
        $found = (new PostProfile())->violations($nodes);
        $reason = self::todaysGate($nodes);

        $this->assertSame($reason === null, $found === [], 'the profile refuses exactly what the gate refuses');
        if ($reason !== null) {
            $this->assertSame($reason, $found[0]['text']);
        }
    }

    public function test_it_finds_every_refused_block_and_says_where(): void
    {
        $found = (new PostProfile())->violations([['primitive' => 'zzz'], ['primitive' => 'heading', 'props' => ['text' => 'x', 'level' => 2]], ['component' => 'hero'], 'words']);

        $this->assertSame([['invalid', '1'], ['component', '3'], ['invalid', '4']], array_map(fn ($f) => [$f['code'], $f['at']], $found));
    }

    public function test_every_post_element_is_a_piece_and_every_piece_is_an_element(): void
    {
        $posts = array_values(array_filter(array_keys(ElementCatalog::data()['elements']), fn (string $name) => str_starts_with($name, 'post_')));

        $this->assertEqualsCanonicalizing($posts, array_keys((new PostProfile())->pieces()));
    }

    public function test_the_pieces_are_what_the_studio_hard_coded(): void
    {
        $pieces = Profiles::for('post')->pieces();
        $fields = array_filter(array_map(fn (array $p) => $p['fields'], $pieces));
        $quick = array_map(fn (array $p) => $p['quickEdit'], array_filter($pieces, fn (array $p) => isset($p['quickEdit'])));
        $drafts = array_map(fn (array $p) => $p['drafts'], array_filter($pieces, fn (array $p) => isset($p['drafts'])));

        // pml-ui src/builder/editor/PieceFields.tsx PIECE_FIELDS and PIECE_TEXT_FIELD, Portify GrowthPostConsoleController::DRAFTS.
        $this->assertEqualsCanonicalizing([
            'post_title' => ['title'], 'post_dek' => ['dek'], 'post_author' => ['author_name'], 'post_category' => ['category_id'],
            'post_cover' => ['cover'], 'post_tags' => ['tags'], 'post_story' => ['story_id'], 'post_sources_badge' => ['facts'],
            'post_cta' => ['cta_kind', 'cta_ref'],
        ], $fields);
        $this->assertEqualsCanonicalizing(['post_title' => 'title', 'post_dek' => 'dek', 'post_author' => 'author_name'], $quick);
        $this->assertEqualsCanonicalizing([
            'post_title' => ['title', 'meta_title', 'focus_keyword'], 'post_dek' => ['dek', 'answer'],
            'post_category' => ['category_id'], 'post_tags' => ['tags'],
        ], $drafts);
    }

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function aims(): array
    {
        $piece = fn (string $p) => ['primitive' => $p];

        return [
            'the title' => [$piece('post_title')],
            'the cover' => [$piece('post_cover')],
            'the author' => [$piece('post_author')],
            'the opening' => [['primitive' => 'post_header', 'children' => [$piece('post_category'), $piece('post_title'), $piece('post_dek'), $piece('post_author')]]],
            'a box of title and dek' => [['primitive' => 'box', 'children' => [$piece('post_title'), $piece('post_dek')]]],
            'a box of date and read time' => [['primitive' => 'box', 'children' => [$piece('post_date'), $piece('post_read_time')]]],
            'a paragraph' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'x']]]]],
            'an empty box' => [['primitive' => 'box', 'children' => []]],
            'a section holding the title and words' => [['primitive' => 'box', 'children' => [$piece('post_title'), ['primitive' => 'p']]]],
        ];
    }

    #[DataProvider('aims')]
    public function test_what_an_aimed_ask_may_do_is_the_consoles(array $node): void
    {
        $profile = new PostProfile();

        $this->assertSame(self::todaysOnlyPostPieces($node), $profile->onlyPieces($node));
        $this->assertSame(self::todaysDraftableFor($node), $profile->draftsFor($node));
    }

    public function test_the_studio_reads_the_pieces_from_the_generated_module(): void
    {
        $ts = ElementsTypescript::render();

        $this->assertStringContainsString("export const PROFILE_KEYS = ['email', 'card', 'page', 'post'] as const;", $ts);
        $this->assertStringContainsString("    post_title: { fields: ['title'], quickEdit: 'title', drafts: ['title', 'meta_title', 'focus_keyword'] },", $ts);
        $this->assertStringContainsString("  page: {},", $ts);
        $this->assertStringContainsString("export const POST_OPENING_DRAFTS = ['meta_description', 'secondary_keywords'] as const;", $ts);
        $this->assertSame($ts, ElementsTypescript::render(), 'deterministic');
    }

    public function test_a_comment_pinned_inside_the_opening_does_not_make_it_design_text(): void
    {
        $opening = ['primitive' => 'box', 'children' => [['comment' => 'Check the title', 'note' => ['id' => 'n1']], ['primitive' => 'post_date'], ['primitive' => 'post_author']]];
        $profile = new PostProfile();

        $this->assertTrue($profile->onlyPieces($opening), 'a note is never an element (0.30.1)');
        $this->assertFalse(self::todaysOnlyPostPieces($opening), 'the copy it replaces counted the note: the one deliberate difference');
        $this->assertSame([], $profile->draftsFor($opening));
    }

    public function test_the_whole_opening_drafts_the_search_details_too(): void
    {
        $opening = ['primitive' => 'post_header', 'children' => [['primitive' => 'post_title'], ['primitive' => 'post_dek']]];

        $this->assertSame(['title', 'meta_title', 'focus_keyword', 'dek', 'answer', 'meta_description', 'secondary_keywords'], (new PostProfile())->draftsFor($opening));
    }

    /** Today's gate, verbatim from growth-service PostDocument::validated: the reason it refuses, or null. */
    private static function todaysGate(array $nodes): ?string
    {
        $schema = new PrimitiveSchema(new BlockSchema());

        try {
            foreach (array_values($nodes) as $i => $node) {
                if (! is_array($node)) {
                    throw new InvalidPmlException('Each block of a post is a PML element.', "nodes.{$i}");
                }
                if (isset($node['component'])) {
                    throw new InvalidPmlException('Saved sections (components) are not available in posts yet. Use the elements and the layout\'s own parts.', "nodes.{$i}.component");
                }
                $schema->validateNode($node, "nodes.{$i}");
            }
        } catch (InvalidPmlException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /** Verbatim from Portify GrowthPostConsoleController::onlyPostPieces. */
    private static function todaysOnlyPostPieces(array $node): bool
    {
        if (str_starts_with((string) ($node['primitive'] ?? ''), 'post_')) {
            return true;
        }
        $children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));

        return $children !== [] && array_reduce($children, fn (bool $all, array $child) => $all && self::todaysOnlyPostPieces($child), true);
    }

    /** Verbatim from Portify GrowthPostConsoleController::draftableFor (with its DRAFTS). */
    private static function todaysDraftableFor(array $node): array
    {
        $drafts = [
            'post_title' => ['title', 'meta_title', 'focus_keyword'],
            'post_dek' => ['dek', 'answer'],
            'post_category' => ['category_id'],
            'post_tags' => ['tags'],
        ];
        $primitives = [];
        $walk = function (array $n) use (&$walk, &$primitives): void {
            $primitives[] = (string) ($n['primitive'] ?? '');
            foreach ((array) ($n['children'] ?? []) as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($node);

        $fields = [];
        foreach ($primitives as $primitive) {
            array_push($fields, ...($drafts[$primitive] ?? []));
        }
        if ($fields !== [] && count(array_intersect(array_keys($drafts), $primitives)) > 1) {
            array_push($fields, 'meta_description', 'secondary_keywords');
        }

        return array_values(array_unique($fields));
    }
}
