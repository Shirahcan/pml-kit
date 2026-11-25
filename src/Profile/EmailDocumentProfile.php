<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\Email\EmailProfile;

/**
 * An email as a profile: exactly {@see EmailProfile} (its elements and its refusals, verbatim). The email rules stay in
 * EmailProfile; this only gives them the shape every purpose shares.
 */
final class EmailDocumentProfile implements Profile
{
    public function key(): string
    {
        return 'email';
    }

    public function elements(): ?array
    {
        return EmailProfile::ELEMENTS;
    }

    public function violations(array $nodes): array
    {
        return EmailProfile::violations($nodes);
    }

    public function pieces(): array
    {
        return [];
    }

    public function spec(): ?string
    {
        return \Shirahcan\Pml\Email\EmailSpec::describe();
    }
}
