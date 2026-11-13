<?php

namespace Shirahcan\Pml\Compliance\Rules;

use Shirahcan\Pml\Compliance\ComplianceViolation;

/**
 * CICC Code, s.44: a licensee must not imply that they have a relationship with the
 * Government of Canada or a provincial government.
 *
 * The line to hold: an RCIC IS authorized to represent clients before IRCC, and saying
 * so is both true and permitted. What is forbidden is implying they are PART of, or
 * endorsed BY, the government.
 *
 *   PERMITTED: "Licensed to represent you before IRCC."
 *   PERMITTED: "I am an RCIC in good standing with the CICC."
 *   FORBIDDEN: "An official IRCC partner."
 *   FORBIDDEN: "Authorized by the Government of Canada."
 *   FORBIDDEN: "Working with IRCC to get you approved."
 *
 * So this rule does not look for "IRCC". It looks for AFFILIATION language bound to a
 * government body.
 */
class GovernmentAffiliationRule implements ComplianceRule
{
    public const RULE = 'government_affiliation';

    /** Words that assert a relationship WITH the body, rather than practice before it. */
    private const AFFILIATION_WORDS = [
        'official', 'partner', 'partnership', 'partnered', 'affiliated', 'affiliate',
        'endorsed', 'endorsement', 'approved by', 'authorized by', 'authorised by',
        'accredited by', 'certified by', 'appointed by', 'on behalf of the government',
        'work with the government', 'working with the government', 'insider',
        'connections at', 'contacts at', 'friends at', 'we work at',
        'government agent', 'agent of', 'representative of the government',
    ];

    private const GOVERNMENT_BODIES = [
        'ircc', 'cic', 'cbsa', 'esdc', 'immigration canada', 'government of canada',
        'federal government', 'the government', 'canadian government', 'visa office',
        'embassy', 'consulate', 'province of', 'provincial government',
    ];

    /**
     * A licensee IS entitled to say they may represent a client before these bodies, and
     * a rule that blocks that would block the single truest thing on the page.
     */
    private const PERMITTED_PRACTICE_PHRASES = [
        'licensed to represent', 'authorized to represent', 'authorised to represent',
        'represent you before', 'represent clients before', 'act for you before',
        'regulated by the college', 'in good standing with the college',
        'member in good standing', 'licensed by the college',
    ];

    public function check(string $text, ?string $blockType = null, ?string $field = null): array
    {
        $violations = [];

        foreach ($this->sentences($text) as $sentence) {
            $lower = mb_strtolower($sentence);

            if (! $this->containsAny($lower, self::GOVERNMENT_BODIES)) {
                continue;
            }

            // Saying you are licensed to appear before IRCC is the truth, not a breach.
            if ($this->containsAny($lower, self::PERMITTED_PRACTICE_PHRASES)) {
                continue;
            }

            if (! $this->containsAny($lower, self::AFFILIATION_WORDS)) {
                continue;
            }

            $violations[] = new ComplianceViolation(
                rule: self::RULE,
                severity: ComplianceViolation::SEVERITY_BLOCK,
                excerpt: trim($sentence),
                explanation: 'The College does not allow marketing that implies a relationship with the Government of Canada or a provincial government (Code of Professional Conduct, s.44). You may say you are licensed to represent clients before IRCC. You may not suggest you are affiliated with, endorsed by, or connected inside it.',
                suggestedRewrite: null,
                blockType: $blockType,
                field: $field,
            );
        }

        return $violations;
    }

    /** @param list<string> $needles */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function sentences(string $text): array
    {
        $parts = preg_split('/(?<=[.!?;:])\s+|\n+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
