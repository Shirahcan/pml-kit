<?php

namespace Shirahcan\Pml\Card;

/** No product rules: nothing pinned, nothing refused, a 300 KB budget, own pictures allowed. */
final class DefaultCardPolicy implements CardPolicy
{
    public function __construct(private readonly int $maxBytes = 300_000) {}

    public function pinsIdentity(): bool
    {
        return false;
    }

    public function scan(string $words): ?string
    {
        return null;
    }

    public function maxBytes(): int
    {
        return $this->maxBytes;
    }

    public function allowsOwnPicture(): bool
    {
        return true;
    }
}
