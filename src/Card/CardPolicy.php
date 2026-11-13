<?php

namespace Shirahcan\Pml\Card;

/**
 * A product's rules for its cards (shared program E2c): the port that keeps a product's regulator, wording and
 * limits out of this package. Portify's implementation pins a licensee's verified identity on a consultant's cards
 * and scans card words with the page's compliance rule pack.
 */
interface CardPolicy
{
    /** Whether every card must carry the host's identity line (the `credentials` element). */
    public function pinsIdentity(): bool;

    /** Null when the words may go on the card; otherwise the reason they may not, in words the person can act on. */
    public function scan(string $words): ?string;

    /** The largest card image in bytes (WhatsApp drops a preview image over about 300 KB). */
    public function maxBytes(): int;

    /** Whether the person may use their own picture as the card. */
    public function allowsOwnPicture(): bool;
}
