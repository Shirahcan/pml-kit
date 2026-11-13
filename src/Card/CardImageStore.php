<?php

namespace Shirahcan\Pml\Card;

/**
 * Where card pictures live (shared program E2e): the DocumentAdapter's media port for cards. growth-service stores
 * its documents' cards; Portify stores consultant sites' cards on its own media disk.
 */
interface CardImageStore
{
    /**
     * Stores the picture under a content-addressed name (`cards/{document}-{hash}.{ext}`) and returns its ABSOLUTE
     * public https address on the product's own domain: crawlers need a full address, and a new hash is a new
     * address, which is what makes LinkedIn and WhatsApp show the new card.
     */
    public function store(string $documentKey, string $hash, string $bytes, string $extension, string $mimeType): string;

    /** Whether a stored card picture is still there (a deleted file means the card is drawn again). */
    public function exists(string $url): bool;
}
