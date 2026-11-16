<?php

namespace Shirahcan\Pml\Email;

/** A brand with no colours, for drawing rich text only to read its words. */
final class NullBrand implements EmailBrand
{
    public function productName(): string
    {
        return '';
    }

    public function colors(): array
    {
        return [];
    }

    public function fontStack(): string
    {
        return '';
    }
}
