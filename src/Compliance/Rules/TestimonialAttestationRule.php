<?php

namespace Shirahcan\Pml\Compliance\Rules;

use Shirahcan\Pml\Compliance\ComplianceViolation;

/**
 * CICC Code, s.45: a licensee may publish a client endorsement ONLY IF it was actually
 * given by that client, is true and accurate, AND has been reviewed and approved for
 * public use by the client IN WRITING.
 *
 * This is the clause most likely to catch an honest consultant out, because the natural
 * thing to do is paste a nice Google review onto your website, and that is precisely what
 * s.45 forbids without written approval.
 *
 * Unlike the other rules, this one does not read prose: it inspects the STRUCTURE of a
 * testimonials block. A testimonial with no attestation on file cannot be published, and
 * no amount of careful wording fixes that.
 */
class TestimonialAttestationRule
{
    public const RULE = 'unattested_testimonial';

    /**
     * @param  array<string, mixed>  $config  the testimonials block's config
     * @return list<ComplianceViolation>
     */
    public function checkBlock(array $config): array
    {
        $violations = [];
        $items = $config['items'] ?? [];

        if (! is_array($items)) {
            return [];
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                continue;
            }

            $quote = trim((string) ($item['quote'] ?? ''));

            if ($quote === '') {
                continue; // an empty slot is not an endorsement
            }

            if ($this->isAttested($item)) {
                continue;
            }

            $violations[] = new ComplianceViolation(
                rule: self::RULE,
                severity: ComplianceViolation::SEVERITY_BLOCK,
                excerpt: mb_substr($quote, 0, 120),
                explanation: 'The College allows a client endorsement only if the client approved it for public use IN WRITING (Code of Professional Conduct, s.45). Confirm you have that written approval, and record who gave it, before this testimonial can be published. Pasting a public review is not enough on its own.',
                suggestedRewrite: null,
                blockType: 'testimonials',
                field: "items.{$index}",
            );
        }

        return $violations;
    }

    /**
     * An attestation is a record of a human confirming written approval exists: who
     * confirmed it and when. A bare boolean would be a checkbox nobody can be held to.
     *
     * @param  array<string, mixed>  $item
     */
    private function isAttested(array $item): bool
    {
        $attestation = $item['attestation'] ?? null;

        if (! is_array($attestation)) {
            return false;
        }

        return ! empty($attestation['attested_by_user_id'])
            && ! empty($attestation['attested_at'])
            && ! empty($attestation['attestation_text']);
    }
}
