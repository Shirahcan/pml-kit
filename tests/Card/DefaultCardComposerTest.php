<?php

namespace Shirahcan\Pml\Tests\Card;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardFacts;
use Shirahcan\Pml\Card\CardFrame;
use Shirahcan\Pml\Card\DefaultCardComposer;
use Shirahcan\Pml\ElementCatalog;

/**
 * The automatic social card (shared program E2b): chosen from the facts, filled with them, no AI, deterministic,
 * and only ever the card subset with real element props.
 */
class DefaultCardComposerTest extends TestCase
{
    private DefaultCardComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new DefaultCardComposer();
    }

    /** @return list<array<string, mixed>> every node of a tree, depth first */
    private function flat(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            $out[] = $node;
            $out = [...$out, ...$this->flat($node['children'] ?? [])];
        }

        return $out;
    }

    private function texts(array $nodes): array
    {
        return array_values(array_filter(array_map(
            static fn (array $n) => $n['props']['text'] ?? $n['props']['value'] ?? null,
            $this->flat($nodes),
        )));
    }

    public function test_the_starter_follows_the_facts(): void
    {
        $this->assertSame('headline', $this->composer->starterFor(new CardFacts('A guide')));
        $this->assertSame('photo', $this->composer->starterFor(new CardFacts('A guide', picture: 'https://p.test/hero.jpg')));
        $this->assertSame('offer', $this->composer->starterFor(new CardFacts('Study permit', picture: 'https://p.test/hero.jpg', price: 'From $2,500')));
    }

    public function test_it_fills_the_starter_with_the_facts_and_drops_what_is_missing(): void
    {
        $nodes = $this->composer->compose(new CardFacts('Spring LMIA webinar', kicker: 'Portify'));

        $this->assertSame(['Portify', 'Spring LMIA webinar'], $this->texts($nodes));
        $this->assertStringNotContainsString('{{', json_encode($nodes));
        $this->assertNotContains('image', array_column($this->flat($nodes), 'primitive'), 'no logo given, so no empty picture');
    }

    public function test_a_photo_card_puts_the_picture_behind_the_words(): void
    {
        $nodes = $this->composer->compose(new CardFacts('Moving to Halifax', subtitle: 'A nurse\'s story', picture: 'https://p.test/halifax.jpg'));

        $this->assertSame('https://p.test/halifax.jpg', $nodes[0]['style']['background_image_url']);
        $this->assertSame('image', $nodes[0]['style']['background']);
        $this->assertSame(['Moving to Halifax', 'A nurse\'s story'], $this->texts($nodes));
    }

    public function test_an_offer_card_shows_the_price_from_the_record(): void
    {
        $nodes = $this->composer->compose(new CardFacts('Study Visa Application', subtitle: '16 weeks', kicker: 'Study', price: 'From $2,500'));

        $this->assertSame(['Study', 'Study Visa Application', 'From $2,500', '16 weeks'], $this->texts($nodes));
    }

    public function test_a_long_title_is_cut_at_a_whole_word(): void
    {
        $title = 'How a nurse from Lagos brought her husband and two children to Halifax through the Atlantic Immigration Program';
        $heading = array_values(array_filter($this->flat($this->composer->compose(new CardFacts($title))), static fn ($n) => $n['primitive'] === 'heading'))[0];

        $this->assertLessThanOrEqual(90, mb_strlen($heading['props']['text']));
        $this->assertStringEndsWith('…', $heading['props']['text']);
        $this->assertStringStartsWith('How a nurse from Lagos', $heading['props']['text']);
        $this->assertSame('Supercalifragilistic…', DefaultCardComposer::cut('Supercalifragilisticexpialidocious', 21));
        $this->assertSame('Short', DefaultCardComposer::cut('Short', 21));
    }

    public function test_the_identity_line_appears_only_when_the_host_pins_it(): void
    {
        $this->assertNotContains('credentials', array_column($this->flat($this->composer->compose(new CardFacts('Home'))), 'primitive'));
        $this->assertContains('credentials', array_column($this->flat($this->composer->compose(new CardFacts('Home', pinIdentity: true))), 'primitive'));
    }

    public function test_the_same_facts_give_the_same_card_byte_for_byte(): void
    {
        $facts = CardFacts::fromArray(['title' => 'A', 'subtitle' => 'B', 'kicker' => 'C', 'picture' => 'https://p.test/x.jpg', 'pin_identity' => true]);

        $this->assertSame(json_encode($this->composer->compose($facts)), json_encode($this->composer->compose($facts)));
    }

    public function test_every_starter_holds_only_the_card_subset_with_real_element_props(): void
    {
        $full = new CardFacts('T', subtitle: 'S', kicker: 'K', picture: 'https://p.test/x.jpg', logo: 'https://p.test/logo.png', price: 'From $1', pinIdentity: true);

        foreach (DefaultCardComposer::STARTERS as $starter) {
            $nodes = $this->composer->compose($full, $starter);

            $this->assertSame([], CardFrame::violations($nodes), $starter);

            foreach ($this->flat($nodes) as $node) {
                $this->assertTrue(ElementCatalog::has($node['primitive']), "{$starter}: {$node['primitive']}");
                $this->assertSame([], array_values(array_diff(array_keys($node['props'] ?? []), ElementCatalog::propNames($node['primitive']) ?? [])), "{$starter}: {$node['primitive']} props");
                $this->assertArrayNotHasKey('card', $node, 'the composer strips its own slot notes');
            }
        }
    }

    public function test_an_unknown_starter_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->composer->compose(new CardFacts('T'), '../../etc/passwd');
    }
}
