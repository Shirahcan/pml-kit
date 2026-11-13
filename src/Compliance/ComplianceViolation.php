<?php

namespace Shirahcan\Pml\Compliance;

/**
 * One thing wrong with a page, stated the way a person would state it.
 *
 * The `explanation` is written for the consultant, not for a log file: they are a
 * licensed professional being told their marketing breaks a rule they are accountable
 * for, and "invalid content" would be both useless and insulting.
 */
final class ComplianceViolation
{
    /** Publishing is refused. Non-negotiable. */
    public const SEVERITY_BLOCK = 'block';

    /** Allowed through, but the consultant is told why it is risky. */
    public const SEVERITY_WARN = 'warn';

    public function __construct(
        public readonly string $rule,
        public readonly string $severity,
        public readonly string $excerpt,
        public readonly string $explanation,
        public readonly ?string $suggestedRewrite = null,
        public readonly ?string $blockType = null,
        public readonly ?string $field = null,
    ) {
    }

    public function blocks(): bool
    {
        return $this->severity === self::SEVERITY_BLOCK;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'rule' => $this->rule,
            'severity' => $this->severity,
            'excerpt' => $this->excerpt,
            'explanation' => $this->explanation,
            'suggested_rewrite' => $this->suggestedRewrite,
            'block_type' => $this->blockType,
            'field' => $this->field,
        ];
    }
}
