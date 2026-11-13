<?php

namespace Shirahcan\Pml\Card;

/**
 * Publishing a document leaves it with a stored, versioned card picture, drawn at most once per change (shared program
 * E2e). Framework-free: the product calls it from its own queued job and backfill command, and supplies the renderer,
 * the store and the signed address of its card route.
 *
 * - The card is guarded first ({@see CardGuard}): what a card cannot hold is dropped, a pinned identity line comes
 *   back, refused words fall back to the automatic card.
 * - Its hash covers the guarded card, the facts, the theme the card is drawn with, the card scale and this
 *   renderer's version. Unchanged hash + a picture still in the store = nothing is drawn.
 * - A failed render never loses the last good card: `pending` (retryable) or `failed` (a setup or design fault)
 *   keeps the previous address, with the reason in `error`.
 *
 * The renderer reports a failure as a {@see CardRender}, never by throwing. A store that throws leaves the document's
 * card exactly as it was (nothing is returned to save), so the product's job simply retries.
 *
 * No AI runs here: a card is drawn because something was published, never on a timer.
 */
final class CardPublisher
{
    /** Bump when the card's drawing changes in a way every stored card should be redrawn for. */
    public const RENDERER_VERSION = 'pml-card-1';

    public const STATUS_RENDERED = 'rendered';

    public const STATUS_PENDING = 'pending';

    public const STATUS_FAILED = 'failed';

    public function __construct(
        private readonly CardRenderer $renderer,
        private readonly CardImageStore $store,
        private readonly CardGuard $guard = new CardGuard(),
    ) {}

    /**
     * @param  array<string, mixed>|null  $current  the document's stored card ref, if any
     * @param  list<array<string, mixed>>|null  $designed  the person's own card, or null for the automatic card
     * @param  string  $themeKey  a fingerprint of the theme and fonts the card is drawn with (a theme change redraws)
     * @param  callable(string $hash): string  $previewUrl  the signed address of the product's card route for this card
     * @return array{card: array{url: ?string, width: int, height: int, type: ?string, alt: string, hash: string, status: string, error: ?string}, guard: array<string, mixed>, rendered: bool}
     */
    public function publish(string $documentKey, ?array $current, ?array $designed, CardFacts $facts, CardPolicy $policy, string $themeKey, callable $previewUrl): array
    {
        $guard = $this->guard->guard($designed, $facts, $policy);
        $hash = self::hash($guard['nodes'], $facts, $themeKey);
        $alt = $facts->alt ?? $facts->title;

        if ($this->isCurrent($current, $hash)) {
            return ['card' => self::kept($current, $hash, $alt, self::STATUS_RENDERED, null), 'guard' => $guard, 'rendered' => false];
        }

        $drawn = $this->renderer->snapshot($previewUrl($hash), CardFrame::WIDTH, CardFrame::HEIGHT, CardFrame::READY_SELECTOR, $policy->maxBytes());

        if (! $drawn->succeeded()) {
            $status = $drawn->retryable ? self::STATUS_PENDING : self::STATUS_FAILED;

            return ['card' => self::kept($current, $hash, $alt, $status, $drawn->error), 'guard' => $guard, 'rendered' => false];
        }

        $url = $this->store->store($documentKey, $hash, (string) $drawn->bytes, $drawn->extension(), $drawn->mimeType());

        return [
            'card' => [
                'url' => $url,
                'width' => $drawn->width,
                'height' => $drawn->height,
                'type' => $drawn->mimeType(),
                'alt' => $alt,
                'hash' => $hash,
                'status' => self::STATUS_RENDERED,
                'error' => null,
            ],
            'guard' => $guard,
            'rendered' => true,
        ];
    }

    /**
     * Whether publishing would draw a card: what a backfill's dry run counts, and what lets a re-run skip every
     * document that is already current. Draws nothing and stores nothing.
     *
     * @param  array<string, mixed>|null  $current
     * @param  list<array<string, mixed>>|null  $designed
     */
    public function needsDrawing(?array $current, ?array $designed, CardFacts $facts, CardPolicy $policy, string $themeKey): bool
    {
        $guard = $this->guard->guard($designed, $facts, $policy);

        return ! $this->isCurrent($current, self::hash($guard['nodes'], $facts, $themeKey));
    }

    /** @param  array<string, mixed>|null  $current */
    private function isCurrent(?array $current, string $hash): bool
    {
        return ($current['hash'] ?? null) === $hash
            && ($current['status'] ?? null) === self::STATUS_RENDERED
            && is_string($current['url'] ?? null)
            && $this->store->exists($current['url']);
    }

    /** The card's version: 16 hex characters of what decides how it looks. */
    public static function hash(array $nodes, CardFacts $facts, string $themeKey): string
    {
        return substr(hash('sha256', (string) json_encode([
            self::RENDERER_VERSION,
            CardFrame::TYPE_SCALE,
            $themeKey,
            $nodes,
            $facts->placeholders(),
            $facts->pinIdentity,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), 0, 16);
    }

    /**
     * The stored picture kept as it is (its address, size and type describe that picture), under the new hash and
     * status: a `pending` or `failed` card still serves the last good picture while the reason is shown.
     *
     * @param  array<string, mixed>|null  $current
     * @return array{url: ?string, width: int, height: int, type: ?string, alt: string, hash: string, status: string, error: ?string}
     */
    private static function kept(?array $current, string $hash, string $alt, string $status, ?string $error): array
    {
        return [
            'url' => is_string($current['url'] ?? null) ? $current['url'] : null,
            'width' => (int) ($current['width'] ?? CardFrame::WIDTH),
            'height' => (int) ($current['height'] ?? CardFrame::HEIGHT),
            'type' => is_string($current['type'] ?? null) ? $current['type'] : null,
            'alt' => $alt,
            'hash' => $hash,
            'status' => $status,
            'error' => $error,
        ];
    }
}
