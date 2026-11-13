<?php

namespace Shirahcan\Pml\Compliance\Rules;

use Shirahcan\Pml\Compliance\ComplianceViolation;
use Shirahcan\Pml\Compliance\LicenseeIdentity;

/**
 * CICC Code of Professional Conduct, s.44: a licensee's advertising must display their name
 * as registered with the College and their registration number, prominently and at or near
 * the beginning, and must not guarantee an outcome. A public consultant page IS regulated
 * advertising, so EVERY published page has to carry the licensee identity and the mandated
 * "no outcome can be guaranteed" notice.
 *
 * Unlike the per-sentence rules, this one is about ABSENCE, not a prohibited phrase, so it
 * reads the whole resolved page at once. There is no special home-vs-subpage carve-out: a
 * visitor can land on any page from a search result, so each one advertises on its own.
 *
 * This replaces the old shell-rendered guarantee. The shell no longer prints the identity
 * outside the page; instead a native header/footer component bakes it INTO the page prose,
 * or a consultant builds their own footer from the placeholders below - and this rule, running
 * on the resolved tree, confirms the required elements actually made it onto the page. It is
 * satisfied identically whichever way they got there, because it reads the baked prose, not
 * the components that produced it.
 *
 * It is deliberately a HARD block: a page that would advertise a licensee without their
 * registered identity, or without the no-guarantee notice, does not go public.
 */
class LicenseeIdentityRequiredRule
{
    public const RULE = 'licensee_identity_required';

    /**
     * The exact, mandated notice. This is the single source of the `{site.legal_disclaimer}`
     * native ({@see \App\Services\Sites\PslNatives}) and of the native footer
     * component's disclaimer line, so what a consultant can drop in and what the rule requires can
     * never drift. Lifted verbatim from the shell that used to render it, so nothing regresses.
     */
    public const LEGAL_DISCLAIMER = 'No immigration outcome can be guaranteed. Nothing on this site is legal advice, and reading it does not create a client relationship.';

    /**
     * The s.44-critical clause the page must carry, normalised. It is the opening sentence of
     * {@see LEGAL_DISCLAIMER}, so the full notice (native or via the placeholder) contains it, but
     * a consultant paraphrasing in their own words does NOT - they are told to use the real notice.
     */
    private const DISCLAIMER_CORE = 'no immigration outcome can be guaranteed';

    /**
     * @return list<ComplianceViolation>
     */
    public function check(string $prose, LicenseeIdentity $identity): array
    {
        $haystack = $this->normalise($prose);
        $violations = [];

        // The registered name. Skipped only when we do not know it (an unverified consultant, who
        // is stopped earlier by the licence gate anyway); when known, its absence is a block.
        if (is_string($identity->registeredName) && trim($identity->registeredName) !== ''
            && ! str_contains($haystack, $this->normalise($identity->registeredName))) {
            $violations[] = $this->missing(
                'registered name',
                'This page does not show your name as it appears on the CICC register, which s.44 requires on every public page. Add the site header, or show {owner.registered_name} in a footer you build.',
            );
        }

        // The registration number.
        if (is_string($identity->registrationNumber) && trim($identity->registrationNumber) !== ''
            && ! str_contains($haystack, $this->normalise($identity->registrationNumber))) {
            $violations[] = $this->missing(
                'registration number',
                'This page does not show your CICC registration number, which s.44 requires on every public page. Add the site header, or show {owner.registration_number} in a footer you build.',
            );
        }

        // The mandated no-guarantee notice.
        if (! str_contains($haystack, self::DISCLAIMER_CORE)) {
            $violations[] = $this->missing(
                'legal disclaimer',
                'This page is missing the required notice that no immigration outcome can be guaranteed (s.44). Add the site footer, or drop {site.legal_disclaimer} into a footer you build.',
            );
        }

        return $violations;
    }

    private function missing(string $element, string $explanation): ComplianceViolation
    {
        return new ComplianceViolation(
            rule: self::RULE,
            severity: ComplianceViolation::SEVERITY_BLOCK,
            excerpt: "Missing: {$element}",
            explanation: $explanation,
        );
    }

    /** Lower-case and collapse whitespace so a line-break or double space never hides a match. */
    private function normalise(string $text): string
    {
        return trim(preg_replace('/\s+/', ' ', mb_strtolower($text)) ?? mb_strtolower($text));
    }
}
