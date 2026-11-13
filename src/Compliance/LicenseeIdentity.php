<?php

namespace Shirahcan\Pml\Compliance;

/**
 * The two facts CICC s.44 requires a licensee's advertising to carry: the name exactly as
 * the College register holds it, and the registration number. Both come from the matched
 * register record, never from what the consultant typed about themselves.
 *
 * A tiny immutable value so the snapshot builder (which is pure, no DB) can be told what the
 * page MUST contain without reaching for a model. The DB edge ({@see \App\Services\Sites\SiteModuleData})
 * resolves the register record into the credentials array this reads.
 */
final class LicenseeIdentity
{
    public function __construct(
        public readonly ?string $registeredName,
        public readonly ?string $registrationNumber,
    ) {
    }

    /**
     * Build from the credentials array {@see \App\Services\Sites\SiteModuleData::for} resolves
     * (its `registered_name` / `registration_number` keys come from the verified register record).
     *
     * @param  array<string, mixed>  $credentials
     */
    public static function fromCredentials(array $credentials): self
    {
        return new self(
            is_string($credentials['registered_name'] ?? null) ? $credentials['registered_name'] : null,
            is_string($credentials['registration_number'] ?? null) ? $credentials['registration_number'] : null,
        );
    }
}
