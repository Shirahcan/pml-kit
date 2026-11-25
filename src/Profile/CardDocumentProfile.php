<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\Card\CardFrame;

/**
 * A social card as a profile: exactly {@see CardFrame} (its subset and its findings). The card rules stay in CardFrame;
 * this only gives them the shape every purpose shares.
 */
final class CardDocumentProfile implements Profile
{
    public function key(): string
    {
        return 'card';
    }

    public function elements(): ?array
    {
        return CardFrame::ELEMENTS;
    }

    public function violations(array $nodes): array
    {
        return CardFrame::findings($nodes);
    }

    public function pieces(): array
    {
        return [];
    }

    public function spec(): ?string
    {
        return \Shirahcan\Pml\Card\CardSpec::describe();
    }
}
