<?php

namespace Shirahcan\Pml\Tests\Card;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardFrame;
use Shirahcan\Pml\ElementCatalog;

/** The card subset (shared program E2b): the PHP mirror of @shirahcan/pml's CARD_ELEMENTS. */
class CardFrameTest extends TestCase
{
    public function test_every_card_element_is_a_real_element(): void
    {
        foreach (CardFrame::ELEMENTS as $element) {
            $this->assertTrue(ElementCatalog::has($element), $element);
        }
    }

    public function test_a_card_drops_and_names_what_it_cannot_hold(): void
    {
        $nodes = [
            ['primitive' => 'heading', 'props' => ['text' => 'Hi']],
            ['primitive' => 'form', 'props' => ['key' => 'ask'], 'children' => [['primitive' => 'field', 'props' => []]]],
            ['primitive' => 'box', 'props' => [], 'children' => [['primitive' => 'script', 'props' => []], ['primitive' => 'text', 'props' => ['value' => 'Kept']]]],
            ['props' => ['comment' => 'a note']],
        ];

        $this->assertSame(['heading', 'box'], array_column(CardFrame::nodes($nodes), 'primitive'));
        $this->assertSame(['text'], array_column(CardFrame::nodes($nodes)[1]['children'], 'primitive'));

        $violations = CardFrame::violations($nodes);
        $this->assertCount(2, $violations);
        $this->assertStringContainsString('<form> (at 2)', $violations[0]);
        $this->assertStringContainsString('<script> (at 3.1)', $violations[1]);
    }

    /**
     * The TypeScript side draws exactly this subset. Checked against the sibling pml-ui checkout when it is there
     * (a developer box); a product with both packages installed checks it in its own parity test (E4d).
     */
    public function test_the_subset_matches_the_renderer_when_its_source_is_beside_this_package(): void
    {
        $ts = dirname(__DIR__, 3).'/pml-ui/src/card/cardFrame.ts';

        if (! is_file($ts)) {
            $this->markTestSkipped('No pml-ui checkout beside this package.');
        }

        preg_match('/CARD_ELEMENTS[^=]*=\s*\[(.*?)\];/s', (string) file_get_contents($ts), $m);
        preg_match_all("/'([a-z_]+)'/", $m[1] ?? '', $names);

        $this->assertSame(CardFrame::ELEMENTS, $names[1]);
    }
}
