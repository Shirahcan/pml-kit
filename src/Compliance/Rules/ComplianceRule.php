<?php

namespace Shirahcan\Pml\Compliance\Rules;

use Shirahcan\Pml\Compliance\ComplianceViolation;

/**
 * A deterministic check against the CICC Code.
 *
 * These rules are AUTHORITATIVE for the hard blocks. Porter advises on top of them, and
 * can add warnings, but it can never downgrade what a rule blocked: a non-deterministic
 * model is not the thing that stands between a live product and a regulatory breach.
 */
interface ComplianceRule
{
    /**
     * @return list<ComplianceViolation>
     */
    public function check(string $text, ?string $blockType = null, ?string $field = null): array;
}
