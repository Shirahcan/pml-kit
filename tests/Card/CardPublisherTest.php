<?php

namespace Shirahcan\Pml\Tests\Card;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardFacts;
use Shirahcan\Pml\Card\CardImageStore;
use Shirahcan\Pml\Card\CardPolicy;
use Shirahcan\Pml\Card\CardPublisher;
use Shirahcan\Pml\Card\CardRender;
use Shirahcan\Pml\Card\CardRenderer;
use Shirahcan\Pml\Card\DefaultCardPolicy;

/** The card publisher (shared program E2e): drawn at most once per change, never losing the last good card. */
class CardPublisherTest extends TestCase
{
    /** @var list<array{url: string, width: int, height: int, wait: string, max: int}> */
    private array $snapshots = [];

    private ?CardRender $next = null;

    /** @var array<string, string> url => bytes */
    private array $files = [];

    private function publisher(): CardPublisher
    {
        $renderer = new class($this) implements CardRenderer
        {
            public function __construct(private CardPublisherTest $t) {}

            public function snapshot(string $url, int $width, int $height, string $waitFor, int $maxBytes): CardRender
            {
                return $this->t->draw($url, $width, $height, $waitFor, $maxBytes);
            }
        };
        $store = new class($this) implements CardImageStore
        {
            public function __construct(private CardPublisherTest $t) {}

            public function store(string $documentKey, string $hash, string $bytes, string $extension, string $mimeType): string
            {
                return $this->t->keep("https://p.test/media/cards/{$documentKey}-{$hash}.{$extension}", $bytes);
            }

            public function exists(string $url): bool
            {
                return $this->t->has($url);
            }
        };

        return new CardPublisher($renderer, $store);
    }

    public function draw(string $url, int $width, int $height, string $waitFor, int $maxBytes): CardRender
    {
        $this->snapshots[] = ['url' => $url, 'width' => $width, 'height' => $height, 'wait' => $waitFor, 'max' => $maxBytes];

        return $this->next ?? CardRender::drawn('PNGBYTES', 'png');
    }

    public function keep(string $url, string $bytes): string
    {
        $this->files[$url] = $bytes;

        return $url;
    }

    public function has(string $url): bool
    {
        return isset($this->files[$url]);
    }

    private function publish(?array $current, CardFacts $facts, ?CardPolicy $policy = null, ?array $designed = null, string $theme = 'theme-a'): array
    {
        return $this->publisher()->publish('page-1', $current, $designed, $facts, $policy ?? new DefaultCardPolicy(), $theme, static fn (string $hash) => "https://p.test/share-card/page-1?v={$hash}");
    }

    public function test_a_first_publish_draws_a_1200_by_630_card_under_the_budget_and_stores_it_by_hash(): void
    {
        $out = $this->publish(null, new CardFacts('Spring webinar'), new DefaultCardPolicy(250_000));

        $this->assertTrue($out['rendered']);
        $this->assertSame('rendered', $out['card']['status']);
        $this->assertSame(['width' => 1200, 'height' => 630, 'wait' => '[data-card-ready="true"]', 'max' => 250_000],
            array_intersect_key($this->snapshots[0], array_flip(['width', 'height', 'wait', 'max'])));
        $this->assertStringContainsString($out['card']['hash'], $out['card']['url']);
        $this->assertStringContainsString('v='.$out['card']['hash'], $this->snapshots[0]['url']);
        $this->assertSame('Spring webinar', $out['card']['alt']);
        $this->assertSame('image/png', $out['card']['type']);
    }

    public function test_publishing_again_unchanged_draws_nothing(): void
    {
        $first = $this->publish(null, new CardFacts('Spring webinar'));
        $again = $this->publish($first['card'], new CardFacts('Spring webinar'));

        $this->assertFalse($again['rendered']);
        $this->assertCount(1, $this->snapshots);
        $this->assertSame($first['card']['url'], $again['card']['url']);
    }

    public function test_a_changed_fact_or_theme_draws_a_new_card_at_a_new_address(): void
    {
        $first = $this->publish(null, new CardFacts('Spring webinar'));
        $retitled = $this->publish($first['card'], new CardFacts('Summer webinar'));
        $rethemed = $this->publish($retitled['card'], new CardFacts('Summer webinar'), theme: 'theme-b');

        $this->assertCount(3, $this->snapshots);
        $this->assertNotSame($first['card']['url'], $retitled['card']['url']);
        $this->assertNotSame($retitled['card']['hash'], $rethemed['card']['hash']);
    }

    public function test_a_picture_missing_from_the_store_is_drawn_again(): void
    {
        $first = $this->publish(null, new CardFacts('Spring webinar'));
        $this->files = [];

        $this->assertTrue($this->publish($first['card'], new CardFacts('Spring webinar'))['rendered']);
    }

    public function test_a_busy_browser_keeps_the_last_good_card_and_is_retryable(): void
    {
        $first = $this->publish(null, new CardFacts('Spring webinar'));
        $this->next = CardRender::failed('The browser is busy with another page.', true);
        $out = $this->publish($first['card'], new CardFacts('Summer webinar'));

        $this->assertSame('pending', $out['card']['status']);
        $this->assertSame($first['card']['url'], $out['card']['url']);
        $this->assertStringContainsString('busy', (string) $out['card']['error']);
    }

    public function test_a_setup_fault_is_failed_with_its_reason_and_no_card_is_invented(): void
    {
        $this->next = CardRender::failed('host_not_registered', false);
        $out = $this->publish(null, new CardFacts('Spring webinar'));

        $this->assertSame('failed', $out['card']['status']);
        $this->assertNull($out['card']['url']);
        $this->assertSame('host_not_registered', $out['card']['error']);
    }

    public function test_a_dry_run_counts_what_would_be_drawn_without_drawing(): void
    {
        $publisher = $this->publisher();
        $policy = new DefaultCardPolicy();
        $first = $this->publish(null, new CardFacts('Spring webinar'));

        $this->assertFalse($publisher->needsDrawing($first['card'], null, new CardFacts('Spring webinar'), $policy, 'theme-a'));
        $this->assertTrue($publisher->needsDrawing($first['card'], null, new CardFacts('Summer webinar'), $policy, 'theme-a'));
        $this->assertTrue($publisher->needsDrawing(null, null, new CardFacts('Spring webinar'), $policy, 'theme-a'));
        $this->assertTrue($publisher->needsDrawing(['status' => 'pending'] + $first['card'], null, new CardFacts('Spring webinar'), $policy, 'theme-a'));
        $this->assertCount(1, $this->snapshots);
    }

    public function test_a_jpeg_is_stored_as_jpg(): void
    {
        $this->next = CardRender::drawn('JPGBYTES', 'jpeg');
        $out = $this->publish(null, new CardFacts('Photo'));

        $this->assertStringEndsWith('.jpg', (string) $out['card']['url']);
        $this->assertSame('image/jpeg', $out['card']['type']);
    }
}
