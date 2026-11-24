<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\StandardLibrary;

/**
 * A blog post as a profile: exactly the post save gate growth-service runs today (PostDocument::validated), and the
 * post's pieces, the `post_*` elements whose words are the POST's own details (its title, the line under it, its
 * cover), never text in the design.
 *
 * The gate: every block, at EVERY depth, is a PML element, a note or a STANDARD section (a website's own saved sections
 * are not available in posts), each element passing PrimitiveSchema::validateNode; then the whole design compiles as
 * its bake will (P1a-3a, 0.37.0; standard sections allowed 0.38.0). Until 0.37.0 only the top-level blocks were checked,
 * so a nested problem saved and failed later, and only a top-level section was refused.
 *
 * The pieces are ONE authority for what the Studio, the console and Porter used to keep three copies of (pml-ui's
 * PIECE_FIELDS and PIECE_TEXT_FIELD, GrowthPostConsoleController's DRAFTS and onlyPostPieces, PostConsole's DRAFTABLE).
 */
final class PostProfile implements Profile
{
    /**
     * Every post piece: the post fields it shows and edits in its panel (`fields`), the one field a double-click types
     * in place (`quickEdit`), and the details an ask aimed at it drafts (`drafts`). A piece with no fields shows a record
     * the post points at or a value the platform keeps (the date, the reading time, related posts): it changes nowhere
     * in the design. Every `post_*` element of the catalog is here (PostProfileTest fails on one that is not).
     *
     * @var array<string, array{fields: list<string>, quickEdit?: string, drafts?: list<string>}>
     */
    public const PIECES = [
        'post_header' => ['fields' => []],
        'post_breadcrumbs' => ['fields' => []],
        'post_category' => ['fields' => ['category_id'], 'drafts' => ['category_id']],
        'post_badges' => ['fields' => []],
        'post_reviewer_badge' => ['fields' => []],
        'post_sources_badge' => ['fields' => ['facts']],
        'post_title' => ['fields' => ['title'], 'quickEdit' => 'title', 'drafts' => ['title', 'meta_title', 'focus_keyword']],
        'post_dek' => ['fields' => ['dek'], 'quickEdit' => 'dek', 'drafts' => ['dek', 'answer']],
        'post_summary' => ['fields' => ['answer'], 'quickEdit' => 'answer', 'drafts' => ['answer']],
        'post_meta' => ['fields' => []],
        'post_author' => ['fields' => ['author_name'], 'quickEdit' => 'author_name'],
        'post_date' => ['fields' => []],
        'post_read_time' => ['fields' => []],
        'post_reviewer' => ['fields' => []],
        'post_tags' => ['fields' => ['tags'], 'drafts' => ['tags']],
        'post_cover' => ['fields' => ['cover']],
        'post_related' => ['fields' => []],
        'post_sources' => ['fields' => ['facts']],
        'post_disclaimer' => ['fields' => []],
        'post_cta' => ['fields' => ['cta_kind', 'cta_ref']],
        'post_story' => ['fields' => ['story_id']],
    ];

    /** What an ask aimed at the whole opening (two or more drafting pieces at once) drafts as well: the search details. */
    public const OPENING_DRAFTS = ['meta_description', 'secondary_keywords'];

    public function key(): string
    {
        return 'post';
    }

    /** A post may hold any element of the catalog; its layout (per post, data) says where. */
    public function elements(): ?array
    {
        return null;
    }

    public function violations(array $nodes): array
    {
        $schema = new PrimitiveSchema(new BlockSchema());
        $nodes = array_values($nodes);
        $found = [];

        foreach ($nodes as $i => $node) {
            $this->check($schema, $node, (string) ($i + 1), "nodes.{$i}", $found);
        }

        /*
         * ⚠ EVERY DEPTH, THEN THE WHOLE DESIGN (P1a-3a, 2026-11-24). The gate this profile copied checked each TOP-LEVEL
         * block alone, so a nested unknown element, a nested smuggled attribute or a nested component was SAVED, and the
         * post failed later at its preview or publish bake (a standard-library component even expanded silently in a
         * post that says components are not available). The walk above finds each of those where it is; once every
         * element is sound, the design is compiled as a whole, exactly as the bake compiles it, so what only the whole
         * tree can show (an element inside a parent it cannot sit in, an `else` with no `if`) is refused at save too.
         */
        if ($found === []) {
            try {
                (PageProfile::standardBuilder())->validate($nodes);
            } catch (InvalidPmlException $e) {
                $found[] = self::finding('invalid', '', $e->getMessage());
            }
        }

        return $found;
    }

    /**
     * One block and everything inside it. A finding stops the walk into that block (its children are not trusted).
     *
     * @param  list<array{code: string, at: string, text: string}>  $found
     */
    private function check(PrimitiveSchema $schema, mixed $node, string $at, string $path, array &$found): void
    {
        if (! is_array($node)) {
            $found[] = self::finding('invalid', $at, 'Each block of a post is a PML element.');

            return;
        }
        /*
         * ⚠ STANDARD SECTIONS YES, A SITE'S OWN SECTIONS NO (owner 2026-11-24, "Allow library sections"). A standard
         * section (hero, process_timeline, stats...) rendered in posts all along, because the bake expands it; only the
         * top-level check refused one, so a nested one was saved and shown. It is allowed at every depth now, and the
         * whole-design compile below checks it exactly as the bake expands it. A website's own saved sections live on
         * the website, not in the post's service, so a post cannot hold one.
         */
        if (isset($node['component'])) {
            $name = (string) $node['component'];
            if (! in_array($name, self::standardSections(), true)) {
                $found[] = self::finding('component', $at, "A post can hold the standard sections, but \"{$name}\" is not one of them: a website's own saved sections are not available in posts. Use the elements, a standard section or the layout's own parts.");

                return;
            }
        } else {
            try {
                $schema->validateNode($node, $path);
            } catch (InvalidPmlException $e) {
                $found[] = self::finding('invalid', $at, $e->getMessage());

                return;
            }
        }

        foreach (array_values((array) ($node['children'] ?? [])) as $j => $child) {
            $this->check($schema, $child, "{$at}.".($j + 1), "{$path}.children.{$j}", $found);
        }
    }

    public function pieces(): array
    {
        return self::PIECES;
    }

    /**
     * Whether a block shows only the post's details: a piece itself, or a block whose children are all such blocks (the
     * opening). An ask is never aimed at one unless it holds something Porter drafts ({@see draftsFor()}). A comment
     * pinned inside it is the author's note, never an element, so it does not make the opening design text (0.30.1:
     * the console server and the host's canAskAbout counted it; the Studio's bar did not).
     *
     * @param  array<string, mixed>  $node
     */
    public function onlyPieces(array $node): bool
    {
        if (isset(self::PIECES[(string) ($node['primitive'] ?? '')])) {
            return true;
        }
        $children = array_values(array_filter((array) ($node['children'] ?? []), fn ($child) => is_array($child) && ! PmlParser::isCommentNode($child)));

        return $children !== [] && array_reduce($children, fn (bool $all, array $child) => $all && $this->onlyPieces($child), true);
    }

    /**
     * The post details an ask aimed at this block drafts, in the order its pieces appear: each drafting piece's own,
     * and the search details too when it holds two or more of them (the whole opening). Empty when it holds none.
     *
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    public function draftsFor(array $node): array
    {
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
            array_push($fields, ...(self::PIECES[$primitive]['drafts'] ?? []));
        }
        $drafting = array_keys(array_filter(self::PIECES, fn (array $piece) => isset($piece['drafts'])));
        if ($fields !== [] && count(array_intersect($drafting, $primitives)) > 1) {
            array_push($fields, ...self::OPENING_DRAFTS);
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string> the standard library's section names, the only components a post may hold */
    private static function standardSections(): array
    {
        static $names = null;

        return $names ??= (new StandardLibrary())->names();
    }

    /** @return array{code: string, at: string, text: string} */
    private static function finding(string $code, string $at, string $text): array
    {
        return ['code' => $code, 'at' => $at, 'text' => $text];
    }
}
