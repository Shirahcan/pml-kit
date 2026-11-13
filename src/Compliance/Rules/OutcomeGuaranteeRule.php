<?php

namespace Shirahcan\Pml\Compliance\Rules;

use Shirahcan\Pml\Compliance\ComplianceViolation;

/**
 * CICC Code of Professional Conduct, s.44: a licensee must not guarantee the success of
 * an application, expression of interest, or proceeding.
 *
 * This is the single most common breach in immigration marketing, and the most damaging:
 * it is exactly the promise a desperate applicant most wants to hear.
 *
 * The hard part is NOT catching "we guarantee approval". It is catching that WITHOUT
 * catching the honest sentences a compliant consultant should be free to write:
 *
 *   "No outcome can be guaranteed in an immigration matter."   <- the OPPOSITE claim
 *   "I guarantee you will always know where your file stands." <- a service promise
 *   "We have a strong track record with study permits."        <- honest, permitted
 *
 * A naive keyword match on "guarantee" blocks all three, and a consultant who is blocked
 * for writing the truth will (rightly) stop trusting the tool. So the rule matches a
 * GUARANTEE WORD bound to an APPLICATION-OUTCOME word, and stands down when the sentence
 * is a negation or when the guarantee is attached to a service rather than an outcome.
 */
class OutcomeGuaranteeRule implements ComplianceRule
{
    public const RULE = 'outcome_guarantee';

    /** Words that assert certainty about a result. */
    private const GUARANTEE_WORDS = [
        'guarantee', 'guaranteed', 'guaranteeing', 'guarantees',
        'assured', 'assure', 'promise', 'promised', 'certainty',
        '100% success', '100 percent success', 'always approved', 'never refused',
        'never rejected', 'no risk of refusal', 'zero refusals',
    ];

    /** The things a licensee may not guarantee: the RESULT of a government decision. */
    private const OUTCOME_WORDS = [
        'approval', 'approved', 'approve', 'success', 'successful',
        'pr', 'permanent residence', 'permanent residency', 'citizenship',
        'visa', 'permit', 'work permit', 'study permit', 'application',
        'nomination', 'invitation', 'itа', 'ita', 'landing', 'status',
        'refusal', 'rejection', 'acceptance', 'accepted',
    ];

    /** A sentence carrying one of these is denying a guarantee, not making one. */
    private const NEGATIONS = [
        'no guarantee', 'not guarantee', 'cannot guarantee', "can't guarantee",
        'never guarantee', 'no one can guarantee', 'nobody can guarantee',
        'cannot be guaranteed', 'can not be guaranteed', 'is not guaranteed',
        'are not guaranteed', 'no outcome can be', 'we do not promise',
        'cannot promise', "can't promise", 'no promise',
    ];

    /**
     * A guarantee attached to the SERVICE (responsiveness, care, transparency) is not a
     * guarantee of the application's outcome, and consultants legitimately write these.
     */
    private const SERVICE_OBJECTS = [
        'response', 'responsive', 'reply', 'communication', 'transparency',
        'honesty', 'care', 'attention', 'confidentiality', 'privacy',
        'update', 'updates', 'know where', 'satisfaction', 'availability',
    ];

    public function check(string $text, ?string $blockType = null, ?string $field = null): array
    {
        $violations = [];

        foreach ($this->sentences($text) as $sentence) {
            $lower = mb_strtolower($sentence);

            if (! $this->containsWord($lower, self::GUARANTEE_WORDS)) {
                continue;
            }

            // "No outcome can be guaranteed" is the compliant sentence, not the breach.
            if ($this->containsAny($lower, self::NEGATIONS)) {
                continue;
            }

            if (! $this->containsWord($lower, self::OUTCOME_WORDS)) {
                continue;
            }

            // "I guarantee a response within 24 hours" promises a SERVICE, not a result.
            if ($this->containsAny($lower, self::SERVICE_OBJECTS)
                && ! $this->containsAny($lower, ['approval', 'approved', 'permanent residence', 'pr ', 'citizenship', 'visa', 'permit'])) {
                continue;
            }

            $violations[] = new ComplianceViolation(
                rule: self::RULE,
                severity: ComplianceViolation::SEVERITY_BLOCK,
                excerpt: trim($sentence),
                explanation: 'The College does not allow a licensee to guarantee the success of an application (Code of Professional Conduct, s.44). This sentence promises an outcome that no one, including IRCC, can promise in advance. Describe your experience and your process instead.',
                suggestedRewrite: null, // Porter fills this in (the advisory layer).
                blockType: $blockType,
                field: $field,
            );
        }

        return $violations;
    }

    /** @param list<string> $needles */
    /**
     * A whole word or phrase (its plural or past tense too), never a piece of another word: "pr" (permanent
     * residence) used to match inside "promise", "process" and "provide", so an honest sentence like "No
     * consultant can promise an outcome" was blocked as a guarantee (2026-11-11).
     */
    private function containsWord(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($needle, '/').'(?:s|es|d|ed)?(?![\p{L}\p{N}])/u', $haystack) === 1) {
                return true;
            }
        }

        return false;
    }

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
