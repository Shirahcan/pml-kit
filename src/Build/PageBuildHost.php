<?php

namespace Shirahcan\Pml\Build;

/**
 * What a product supplies to a page Build (shared-page-build S2a, 2026-11-23). {@see PageBuild} does the orchestration
 * (place, mend, check, faint text, repairs, rewrite, canonical source, what changed) the same for every product; the
 * product answers only these. Nothing here is stored by the kit.
 */
interface PageBuildHost
{
    /**
     * One model call on ai-service's shared prompts, under the product's own key.
     *
     * @param  'edit'|'edit_repair'  $prompt  `shared.pml.edit` or `shared.pml.edit_repair`
     * @param  array<string, string>  $vars  `payload`, and for a repair `error` and `prior_output`; the host adds its
     *                                       own `owner`, `spec` and `house_rules`, and the person's attached pictures
     * @param  bool  $strong  the second repair rung: the stronger route the product names in its task routing
     * @return string|null  the answer, or null when the model could not be reached or wrote nothing
     */
    public function ask(string $prompt, array $vars, bool $strong = false): ?string;

    /**
     * Pictures the person wants shown become real addresses the product stores (once each: `$stored` carries what was
     * already stored across the repairs of one Build).
     *
     * @param  array<int, string>  $stored
     * @return array{pml: string, error: ?string}
     */
    public function place(string $pml, array &$stored): array;

    /**
     * The product's save check on a source: parse it and run everything a saved page must pass. Null error = it saves.
     *
     * @return array{nodes: list<array<string, mixed>>, imports: array<int, mixed>, error: ?string}
     */
    public function check(string $pml): array;

    /** The page's theme, for the readability check (null when it has none). */
    public function theme(): ?array;

    /** Progress the person watches: a fault being fixed (`$what`) under a step name (`$label`, or the default step). */
    public function said(string $what, ?string $label = null): void;
}
