<?php

namespace Shirahcan\Pml\Card;

/**
 * Draws one page at an exact size under a byte budget (shared program E2e). The product supplies it: Portify wraps
 * the kit's `AiRender::snapshot()`, which only draws the product's own registered hosts.
 */
interface CardRenderer
{
    public function snapshot(string $url, int $width, int $height, string $waitFor, int $maxBytes): CardRender;
}
