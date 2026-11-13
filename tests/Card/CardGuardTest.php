<?php

namespace Shirahcan\Pml\Tests\Card;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardChecks;
use Shirahcan\Pml\Card\CardFacts;
use Shirahcan\Pml\Card\CardGuard;
use Shirahcan\Pml\Card\CardPolicy;
use Shirahcan\Pml\Card\DefaultCardComposer;
use Shirahcan\Pml\Card\DefaultCardPolicy;

/** Card checks and the product's card policy (shared program E2c). */
class CardGuardTest extends TestCase
{
    /** A product that pins its licensee's identity and refuses a guarantee, as Portify's rule pack does. */
    private function strict(): CardPolicy
    {
        return new class implements CardPolicy
        {
            public function pinsIdentity(): bool
            {
                return true;
            }

            public function scan(string $words): ?string
            {
                return preg_match('/guarantee/i', $words) === 1 ? 'Promising an outcome is not allowed on a regulated card.' : null;
            }

            public function maxBytes(): int
            {
                return 250_000;
            }

            public function allowsOwnPicture(): bool
            {
                return true;
            }
        };
    }

    private function primitives(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = $n['primitive'];
            $out = [...$out, ...$this->primitives($n['children'] ?? [])];
        }

        return $out;
    }

    public function test_a_card_that_lost_its_pinned_identity_gets_it_back(): void
    {
        $designed = [['primitive' => 'box', 'props' => [], 'children' => [['primitive' => 'heading', 'props' => ['text' => 'Hello', 'level' => 1], 'style' => ['heading_size' => 'lg']]]]];

        $out = (new CardGuard())->guard($designed, new CardFacts('Home'), $this->strict());

        $this->assertTrue($out['restored_identity']);
        $this->assertSame(['box', 'heading', 'credentials'], $this->primitives($out['nodes']));
        $this->assertFalse($out['fell_back']);
    }

    public function test_refused_words_fall_back_to_the_automatic_card_with_the_reason(): void
    {
        $designed = [['primitive' => 'heading', 'props' => ['text' => 'Approval guaranteed in 30 days', 'level' => 1]]];

        $out = (new CardGuard())->guard($designed, new CardFacts('Spousal sponsorship'), $this->strict());

        $this->assertTrue($out['fell_back']);
        $this->assertStringContainsString('not allowed', (string) $out['reason']);
        $this->assertStringNotContainsString('guaranteed', CardChecks::allWords($out['nodes']));
        $this->assertContains('credentials', $this->primitives($out['nodes']), 'the fallback keeps the pinned identity');
    }

    public function test_what_a_card_cannot_hold_is_dropped_and_named(): void
    {
        $designed = [['primitive' => 'heading', 'props' => ['text' => 'Hi', 'level' => 1]], ['primitive' => 'form', 'props' => []]];

        $out = (new CardGuard())->guard($designed, new CardFacts('Hi'), new DefaultCardPolicy());

        $this->assertSame(['heading'], $this->primitives($out['nodes']));
        $this->assertCount(1, $out['dropped']);
    }

    public function test_the_default_policy_changes_nothing(): void
    {
        $designed = [['primitive' => 'heading', 'props' => ['text' => 'Hi', 'level' => 1]]];
        $out = (new CardGuard())->guard($designed, new CardFacts('Hi'), new DefaultCardPolicy());

        $this->assertSame($designed, $out['nodes']);
        $this->assertFalse($out['restored_identity']);
        $this->assertFalse($out['fell_back']);
        $this->assertNull($out['reason']);
        $this->assertSame(300_000, (new DefaultCardPolicy())->maxBytes());
    }

    public function test_small_text_and_crowded_cards_are_flagged_in_plain_words(): void
    {
        $small = [['primitive' => 'text', 'props' => ['value' => 'Fine print'], 'style' => ['body_size' => 'sm']]];
        $this->assertSame('text_too_small', CardChecks::findings($small)[0]['code']);
        $this->assertSame(25, CardChecks::px($small[0]));

        $crowded = array_fill(0, 5, ['primitive' => 'text', 'props' => ['value' => str_repeat('word ', 7)], 'style' => ['body_size' => 'lg']]);
        $this->assertSame(['too_many_blocks', 'too_many_words'], array_column(CardChecks::findings($crowded), 'code'));
    }

    public function test_the_automatic_card_passes_its_own_checks_even_with_long_facts(): void
    {
        $facts = new CardFacts(
            str_repeat('Immigration ', 12),
            subtitle: str_repeat('detail ', 25),
            kicker: 'Portify guides',
            price: 'From $2,500',
            pinIdentity: true,
        );

        foreach (DefaultCardComposer::STARTERS as $starter) {
            $this->assertSame([], CardChecks::findings((new DefaultCardComposer())->compose($facts, $starter)), $starter);
        }
    }
}
