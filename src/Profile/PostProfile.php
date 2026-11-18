<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A blog post as a profile: exactly the post save gate growth-service runs today (PostDocument::validated), and the
 * post's pieces, the `post_*` elements whose words are the POST's own details (its title, the line under it, its
 * cover), never text in the design.
 *
 * The gate, verbatim: each TOP-LEVEL block is a PML element, is not a component (saved sections are not available in
 * posts yet), and passes PrimitiveSchema::validateNode. That check does not look inside a block's children, so this
 * profile does not either (parity first; checking every depth is its own change, P1a-3).
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
        'post_meta' => ['fields' => []],
        'post_author' => ['fields' => ['author_name'], 'quickEdit' => 'author_name'],
        'post_date' => ['fields' => []],
        'post_read_time' => ['fields' => []],
        'post_reviewer' => ['fields' => []],
        'post_tags' => ['fields' => ['tags'], 'drafts' => ['tags']],
        'post_cover' => ['fields' => ['cover']],
        'post_related' => ['fields' => []],
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
        $found = [];

        foreach (array_values($nodes) as $i => $node) {
            $at = (string) ($i + 1);

            if (! is_array($node)) {
                $found[] = self::finding('invalid', $at, 'Each block of a post is a PML element.');

                continue;
            }
            if (isset($node['component'])) {
                $found[] = self::finding('component', $at, 'Saved sections (components) are not available in posts yet. Use the elements and the layout\'s own parts.');

                continue;
            }

            try {
                $schema->validateNode($node, "nodes.{$i}");
            } catch (InvalidPmlException $e) {
                $found[] = self::finding('invalid', $at, $e->getMessage());
            }
        }

        return $found;
    }

    public function pieces(): array
    {
        return self::PIECES;
    }

    /**
     * Whether a block shows only the post's details: a piece itself, or a block whose children are all such blocks (the
     * opening). An ask is never aimed at one unless it holds something Porter drafts ({@see draftsFor()}).
     *
     * @param  array<string, mixed>  $node
     */
    public function onlyPieces(array $node): bool
    {
        if (isset(self::PIECES[(string) ($node['primitive'] ?? '')])) {
            return true;
        }
        $children = array_values(array_filter((array) ($node['children'] ?? []), 'is_array'));

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

    /** @return array{code: string, at: string, text: string} */
    private static function finding(string $code, string $at, string $text): array
    {
        return ['code' => $code, 'at' => $at, 'text' => $text];
    }
}
