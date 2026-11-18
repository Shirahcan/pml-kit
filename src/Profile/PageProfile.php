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
        $this->builder = $builder ?? self::builder();
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

    /** The snapshot builder as the hosts' containers build it (the same parts, nothing host-specific). */
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
