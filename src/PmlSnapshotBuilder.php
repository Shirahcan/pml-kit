<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Compliance\ComplianceResult;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\Compliance\ComplianceViolation;
use Shirahcan\Pml\Compliance\LicenseeIdentity;
use Shirahcan\Pml\Compliance\Rules\LicenseeIdentityRequiredRule;

/**
 * Turns a stored PML component tree into an INERT primitive snapshot ready to render (the
 * publish/preview path; the retired block system had a SiteSnapshotBuilder for the same job).
 *
 * The anti-bypass is preserved. Std-lib components expand to primitives; module primitives
 * (packages/credentials/testimonials) are baked with the consultant's REAL resolved data; then
 * compliance is scanned on the RESOLVED tree, INCLUDING the baked package copy and the baked
 * testimonials (both the s.45 attestation structure and each quote's prose), so a consultant
 * cannot publish a clean page and then edit a package or testimonial into a prohibited claim.
 *
 * A product adds an advisory (AI) pass by extending this class; the package itself makes no model call.
 *
 * This class is pure (no DB): the caller resolves the module data from the database and passes
 * it in. That keeps the snapshot logic unit-testable and puts the one DB dependency at the
 * edge, where the publish/render controller already has the consultant loaded.
 */
class PmlSnapshotBuilder
{
    public function __construct(
        protected readonly PmlCompiler $compiler,
        protected readonly PmlComponentExpander $expander,
        protected readonly StandardLibrary $library,
        protected readonly ComplianceRulePack $compliance,
    ) {
    }

    /**
     * Compile a component tree to a validated primitive tree, resolving bindings when a context is
     * given. The order is EXPAND -> resolve bindings -> validate, so a `{consultant.city}` inside a
     * component template is realised before it resolves, and the full gate runs on the RESOLVED
     * tree (compliance therefore scans real bound data - anti-bypass). With no context (tests, or a
     * page that uses no bindings) it is the original single-pass compile, unchanged.
     *
     * @param  list<array<string, mixed>>  $componentTree
     * @param  array<string, callable>  $partials
     * @return list<array<string, mixed>>
     *
     * @throws InvalidPmlException
     */
    private function resolveTree(array $componentTree, array $partials): array
    {
        $components = $this->components($partials);
        $expanded = $this->compiler->expand($componentTree, $components);

        // A bare self-sustaining primitive - <header/>, <nav/>, <footer/> - fills with its declared
        // default body, so the concise source the consultant wrote becomes the full identity + nav +
        // disclaimer the renderer draws. One generic pass keyed off PrimitiveDefaultBody.
        $expanded = $this->expander->fillDefaultBodies($expanded);

        // Expressions stay expressions (PML v3 L3): the browser evaluates them against the PSL natives;
        // the server never runs PSL. The finished tree is pure primitives, validated once here.
        return $this->compiler->compile($expanded, []);
    }

    /**
     * @param  list<array<string, mixed>>  $componentTree
     * @param  array{packages?: list<array<string, mixed>>, credentials?: array<string, mixed>, testimonials?: list<array<string, mixed>>}  $moduleData
     * @param  array<string, callable>  $partials  the site's partial library (handle => expander)
     * @param  ?LicenseeIdentity  $identity  when given (the publish/preflight path), the s.44
     *                                       required-elements check runs on the resolved page. Null
     *                                       for the live canvas/preview, which is work in progress.
     * @return array{tree: list<array<string, mixed>>, compliance: ComplianceResult}
     *
     * @throws InvalidPmlException  when the tree fails the compile gate
     */
    public function snapshot(array $componentTree, array $moduleData = [], array $partials = [], ?LicenseeIdentity $identity = null): array
    {
        $tree = $this->resolveTree($componentTree, $partials);

        // A foreach, not array_map: bake() accumulates baked copy through by-reference
        // parameters, and an arrow fn would capture them by VALUE and lose every entry.
        $bakedCopy = [];
        $testimonialItems = [];
        $resolved = [];

        foreach ($tree as $node) {
            $resolved[] = $this->bake($node, $moduleData, $bakedCopy, $testimonialItems);
        }

        $tree = $resolved;

        // Compliance on the resolved tree: the primitive prose, plus the baked package copy
        // (name + description) that the public will actually read.
        $result = $this->compiler->scanCompliance($tree);

        foreach ($bakedCopy as $text) {
            $result = $result->merge($this->compliance->scanText($text, 'packages'));
        }

        // Testimonials get BOTH gates: the structural s.45 attestation check (an unattested quote
        // is a block regardless of wording), and the ordinary prose scan on each quote (a quote is
        // still public copy, so "guaranteed PR" is a block there too).
        if ($testimonialItems !== []) {
            $result = $result->merge($this->compliance->scanTestimonials($testimonialItems));

            foreach ($testimonialItems as $i => $item) {
                $quote = is_string($item['quote'] ?? null) ? $item['quote'] : '';

                if (trim($quote) !== '') {
                    $result = $result->merge($this->compliance->scanText($quote, 'testimonials', "items.{$i}.quote"));
                }
            }
        }

        // s.44 required elements: on the publish/preflight path the page must carry the licensee
        // identity + the no-guarantee notice. The check reads the resolved prose, so a native
        // header/footer or a consultant's own footer (built from the placeholders) satisfies it the
        // same way. No identity given (the live canvas) means work-in-progress: not gated here.
        if ($identity !== null) {
            $result = $result->merge(
                $this->compliance->scanRequiredIdentity($this->compiler->extractProse($tree)."\n".$this->identityShown($tree, $identity), $identity)
            );
        }

        return ['tree' => $tree, 'compliance' => $result];
    }

    /**
     * The save-time gate: compile the tree (expand components, validate every primitive, check
     * container depth, deep-validate every rich-text doc) and throw if anything is invalid.
     *
     * No module data and no compliance scan here by design: a consultant's PRIVATE draft saves
     * even when it holds a prohibited claim (the block system's save behaved the same way), and
     * only {@see publish} exposes it to the public, where the scan runs and can refuse.
     *
     * @param  list<array<string, mixed>>  $componentTree
     * @param  array<string, callable>  $partials  the site's partial library (handle => expander)
     *
     * @throws InvalidPmlException
     */
    public function validate(array $componentTree, array $partials = []): void
    {
        // With a context, bindings are resolved-then-validated, so a save catches an unknown data
        // path (a bad binding) with a real reason, exactly as it catches a bad primitive.
        $this->resolveTree($componentTree, $partials);
    }

    /**
     * The component map for a compile: the standard library plus this site's partials. Std-lib
     * names WIN on a collision, so a consultant's partial can never shadow a built-in section
     * (the store gate also rejects a handle that collides with a built-in; this is defense in
     * depth). array_merge keeps the LATER value for a duplicate string key, so std-lib is second.
     *
     * @param  array<string, callable>  $partials
     * @return array<string, callable>
     */
    private function components(array $partials): array
    {
        return array_merge($partials, $this->library->components($this->expander));
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array<string, mixed>  $moduleData
     * @param  list<string>  $bakedCopy
     * @param  list<array<string, mixed>>  $testimonialItems
     * @return array<string, mixed>
     */
    private function bake(array $node, array $moduleData, array &$bakedCopy, array &$testimonialItems): array
    {
        $primitive = $node['primitive'] ?? null;

        if ($primitive === 'packages') {
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $packages = is_array($moduleData['packages'] ?? null) ? $moduleData['packages'] : [];
            $props['resolved_packages'] = $packages;

            foreach ($packages as $package) {
                foreach (['name', 'description'] as $key) {
                    if (! empty($package[$key]) && is_string($package[$key])) {
                        $bakedCopy[] = $package[$key];
                    }
                }
            }

            $node['props'] = $props;
        } elseif ($primitive === 'credentials') {
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $props['resolved'] = is_array($moduleData['credentials'] ?? null) ? $moduleData['credentials'] : [];
            $node['props'] = $props;
        } elseif ($primitive === 'testimonials') {
            $props = is_array($node['props'] ?? null) ? $node['props'] : [];
            $items = is_array($moduleData['testimonials'] ?? null) ? $moduleData['testimonials'] : [];
            $props['resolved'] = $items;

            // Accumulate the items so snapshot() can run the structural s.45 rule + scan the quotes.
            foreach ($items as $item) {
                if (is_array($item)) {
                    $testimonialItems[] = $item;
                }
            }

            $node['props'] = $props;
        }

        if (isset($node['children']) && is_array($node['children'])) {
            // foreach, not array_map: keep the by-reference accumulation intact.
            $children = [];

            foreach ($node['children'] as $child) {
                $children[] = is_array($child) ? $this->bake($child, $moduleData, $bakedCopy, $testimonialItems) : $child;
            }

            $node['children'] = $children;
        }

        return $node;
    }

    /**
     * The identity a page shows THROUGH expressions, by syntax (PML v3 L3, owner 2026-11-10: compliance is
     * a guide for static PML, nothing on PSL). A prose prop whose expression is exactly
     * `owner.registered_name`, `owner.registration_number` or `site.legal_disclaimer` shows that value on
     * the live page, so the s.44 check counts it. Anything else an expression builds is not read.
     *
     * @param  list<array<string, mixed>>  $tree
     */
    private function identityShown(array $tree, LicenseeIdentity $identity): string
    {
        $known = [
            'owner.registered_name' => (string) $identity->registeredName,
            'owner.registration_number' => (string) $identity->registrationNumber,
            'site.legal_disclaimer' => LicenseeIdentityRequiredRule::LEGAL_DISCLAIMER,
        ];
        $shown = [];
        $walk = function (array $nodes) use (&$walk, &$shown, $known): void {
            foreach ($nodes as $node) {
                if (! is_array($node)) {
                    continue;
                }

                // Rich text names them the same way, in braces in its Markdown (PML v3 L4).
                if (($node['primitive'] ?? null) === 'rich_text' && is_string($node['props']['markdown'] ?? null)) {
                    foreach ($known as $source => $value) {
                        if (preg_match('/(?<!\\\\)\{\s*'.preg_quote($source, '/').'\s*\}/', $node['props']['markdown']) === 1) {
                            $shown[$source] = $value;
                        }
                    }
                }

                foreach ((array) ($node['props'] ?? []) as $key => $value) {
                    $source = PrimitiveSchema::isExpression($value) ? trim((string) $value['$expr']) : null;

                    if ($source !== null && isset($known[$source]) && ElementCatalog::isProse($node['primitive'] ?? null, (string) $key)) {
                        $shown[$source] = $known[$source];
                    }
                }

                if (isset($node['children']) && is_array($node['children'])) {
                    $walk($node['children']);
                }
            }
        };
        $walk($tree);

        return implode("\n", $shown);
    }
}
