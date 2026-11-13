<?php

namespace Shirahcan\Pml\Card;

/**
 * What a product hands in for a document's social card (shared program E2b). Facts only: the product reads them
 * from the document and its own records; nothing here is invented, and no AI writes them.
 */
final class CardFacts
{
    public function __construct(
        /** The document's own title (its search title, page title or post title). */
        public readonly string $title,
        /** One line under the title (the search description, an intro, a timeline). */
        public readonly ?string $subtitle = null,
        /** A short label above the title (a category, the organisation, a purpose). */
        public readonly ?string $kicker = null,
        /** The main picture, an https:// or root-relative address the product already serves. */
        public readonly ?string $picture = null,
        /** The product's or owner's logo address. */
        public readonly ?string $logo = null,
        /** A price already formatted for its reader ("From $2,500"): a fact from the record, never written. */
        public readonly ?string $price = null,
        /** Draw the host's pinned identity line (e.g. a regulated professional's name and licence number). */
        public readonly bool $pinIdentity = false,
        /** The picture's alt text; the title when not given. */
        public readonly ?string $alt = null,
    ) {}

    /** @param array<string, mixed> $facts */
    public static function fromArray(array $facts): self
    {
        $text = static fn (string $key): ?string => isset($facts[$key]) && is_scalar($facts[$key]) && trim((string) $facts[$key]) !== ''
            ? trim((string) $facts[$key])
            : null;

        return new self(
            title: $text('title') ?? '',
            subtitle: $text('subtitle'),
            kicker: $text('kicker'),
            picture: $text('picture'),
            logo: $text('logo'),
            price: $text('price'),
            pinIdentity: (bool) ($facts['pin_identity'] ?? false),
            alt: $text('alt'),
        );
    }

    /** @return array<string, string> the values a starter's {{placeholders}} read; an absent fact is an empty string */
    public function placeholders(): array
    {
        return [
            'title' => $this->title,
            'subtitle' => (string) $this->subtitle,
            'kicker' => (string) $this->kicker,
            'picture' => (string) $this->picture,
            'logo' => (string) $this->logo,
            'price' => (string) $this->price,
            'alt' => $this->alt ?? $this->title,
        ];
    }
}
