<?php

namespace Shirahcan\Pml\Profile;

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
use Shirahcan\Pml\StandardLibrary;

/**
 * A website page as a profile: exactly the page save gate the hosts run today (Portify's
 * SitePageWorkspace::validateTree calls PmlSnapshotBuilder::validate): the page compiles, its components (the standard
 * library plus the site's own saved sections) expanding to valid elements. A page may hold the whole element catalog.
 *
 * Left to the host, on purpose:
 * - the site's saved sections (partials) are the host's data: pass them, or a page that uses one reads as holding an
 *   unknown component (Profiles::for('page') knows only the standard library);
 * - which elements a site OWNER may not have (a licensee module on the platform's own site) depends on who owns the
 *   site, so the host checks that after this (Portify: OwnerVocabulary).
 *
 * The gate stops at the first problem, so a page has at most one finding. It is placed at the top-level element that
 * holds the problem, or at "" when only the page as a whole fails (an `else` with no `if` before it).
 */
final class PageProfile implements Profile
{
    private readonly PmlSnapshotBuilder $builder;

    /**
     * @param  array<string, callable>  $partials  the site's saved sections (handle => expander)
     */
    public function __construct(private readonly array $partials = [], ?PmlSnapshotBuilder $builder = null)
    {
        $this->builder = $builder ?? self::standardBuilder();
    }

    public function key(): string
    {
        return 'page';
    }

    public function elements(): ?array
    {
        return null;
    }

    public function violations(array $nodes): array
    {
        $nodes = array_values($nodes);

        // ⚠ A post's pieces show a POST's own details (its title, its cover, its author), and a page has none, so a
        // page holding one compiled and then showed nothing, or another record's words (P1a-3a, 0.37.0).
        $piece = self::postPiece($nodes, '');
        if ($piece !== null) {
            [$at, $element] = $piece;
            $what = str_replace('_', ' ', substr($element, strlen('post_')));

            return [['code' => 'post_piece', 'at' => $at, 'text' => "A website page cannot hold a post's {$what} ({$element}): it shows a post's own details, and a page has none."]];
        }

        try {
            $this->builder->validate($nodes, $this->partials);

            return [];
        } catch (InvalidPmlException $e) {
            return [['code' => 'invalid', 'at' => $this->locate($nodes, $e->getMessage()), 'text' => $e->getMessage()]];
        }
    }

    public function pieces(): array
    {
        return [];
    }

    /** A website page is taught the host's own language alone (its site's sections are the host's to describe). */
    public function spec(): ?string
    {
        return null;
    }

    /**
     * The first post piece in the design, at any depth, with where it is ("2.1").
     *
     * @param  list<mixed>  $nodes
     * @return array{0: string, 1: string}|null
     */
    private static function postPiece(array $nodes, string $prefix): ?array
    {
        foreach (array_values($nodes) as $i => $node) {
            if (! is_array($node)) {
                continue;
            }
            $at = $prefix === '' ? (string) ($i + 1) : "{$prefix}.".($i + 1);
            $primitive = (string) ($node['primitive'] ?? '');
            if (isset(PostProfile::PIECES[$primitive])) {
                return [$at, $primitive];
            }
            $inside = self::postPiece((array) ($node['children'] ?? []), $at);
            if ($inside !== null) {
                return $inside;
            }
        }

        return null;
    }

    /**
     * Where the gate's one problem is: the first top-level element that fails on its own with the same reason. The
     * compiler's own path names only the kind of thing it refused ("node", "component"), not where it is.
     *
     * @param  list<mixed>  $nodes
     */
    private function locate(array $nodes, string $reason): string
    {
        foreach ($nodes as $i => $node) {
            try {
                $this->builder->validate([$node], $this->partials);
            } catch (InvalidPmlException $e) {
                if ($e->getMessage() === $reason) {
                    return (string) ($i + 1);
                }
            }
        }

        return '';
    }

    /**
     * The snapshot builder as the hosts' containers build it (the same parts, nothing host-specific). Public so the post
     * profile compiles a design exactly as a page is compiled (P1a-3a), never with a second copy of these parts.
     */
    public static function standardBuilder(): PmlSnapshotBuilder
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
