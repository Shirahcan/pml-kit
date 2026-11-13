<?php

namespace Shirahcan\Pml\Compliance;

/**
 * The verdict on a page, a block, or a package's public copy.
 *
 * The important distinction, and the reason this is not a boolean:
 *
 *   isBlocked()          - a deterministic rule found a prohibited claim. Nothing may
 *                          publish. An AI outage cannot cause this, and an AI opinion
 *                          cannot clear it.
 *   advisoryUnavailable() - Porter could not be reached. The rules still ran.
 *   mayGoPublic()        - blocked OR unscannable. Copy we could not fully check does
 *                          not get exposed to the public, but a PRIVATE save is never
 *                          blocked by our provider having a bad day.
 */
final class ComplianceResult
{
    /**
     * @param  list<ComplianceViolation>  $violations
     */
    public function __construct(
        private readonly array $violations = [],
        private readonly bool $advisoryUnavailable = false,
    ) {
    }

    /** @return list<ComplianceViolation> */
    public function violations(): array
    {
        return $this->violations;
    }

    /** @return list<ComplianceViolation> */
    public function blockers(): array
    {
        return array_values(array_filter($this->violations, fn (ComplianceViolation $v) => $v->blocks()));
    }

    /** @return list<ComplianceViolation> */
    public function warnings(): array
    {
        return array_values(array_filter($this->violations, fn (ComplianceViolation $v) => ! $v->blocks()));
    }

    public function isBlocked(): bool
    {
        return $this->blockers() !== [];
    }

    public function hasWarnings(): bool
    {
        return $this->warnings() !== [];
    }

    public function advisoryUnavailable(): bool
    {
        return $this->advisoryUnavailable;
    }

    /**
     * May this copy be exposed to the public right now?
     *
     * No, if a rule blocked it. Also no, if we could not scan it at all: unscanned copy
     * on a regulated advertising page is a risk we do not take just because a provider
     * timed out. The consultant's private draft still saves either way.
     */
    public function mayGoPublic(): bool
    {
        return ! $this->isBlocked() && ! $this->advisoryUnavailable;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'blocked' => $this->isBlocked(),
            'may_go_public' => $this->mayGoPublic(),
            'advisory_unavailable' => $this->advisoryUnavailable,
            'violations' => array_map(fn (ComplianceViolation $v) => $v->toArray(), $this->violations),
        ];
    }

    public function merge(self $other): self
    {
        return new self(
            array_merge($this->violations, $other->violations),
            $this->advisoryUnavailable || $other->advisoryUnavailable,
        );
    }
}
