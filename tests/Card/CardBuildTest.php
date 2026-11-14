<?php

namespace Shirahcan\Pml\Tests\Card;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardBuild;
use Shirahcan\Pml\Card\CardDesign;
use Shirahcan\Pml\Card\CardDocView;
use Shirahcan\Pml\Card\CardFacts;
use Shirahcan\Pml\Card\CardPolicy;
use Shirahcan\Pml\Card\CardSpec;
use Shirahcan\Pml\Card\DefaultCardPolicy;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A designed card, the console's Build on it, the card language it is taught, and the document a builder loads
 * (shared program E4c): the same for every product.
 */
class CardBuildTest extends TestCase
{
    private const CARD = '<box width="full" min-height="lg" justify="center" background="accent"><heading text="Study permit checklist" level="1" heading-size="lg" text-color="on-accent"/></box>';

    private function pinning(): CardPolicy
    {
        return new class implements CardPolicy
        {
            public function pinsIdentity(): bool
            {
                return true;
            }

            public function scan(string $words): ?string
            {
                return preg_match('/guarantee/i', $words) === 1 ? 'Promising an outcome is not allowed.' : null;
            }

            public function maxBytes(): int
            {
                return 300_000;
            }

            public function allowsOwnPicture(): bool
            {
                return true;
            }
        };
    }

    private function current(): array
    {
        return [['primitive' => 'heading', 'props' => ['text' => 'Old title', 'level' => 1]]];
    }

    public function test_a_design_keeps_only_the_card_subset_and_refuses_an_empty_or_huge_one(): void
    {
        $kept = CardDesign::accept([['primitive' => 'box', 'children' => [
            ['primitive' => 'heading', 'props' => ['text' => 'Hi', 'level' => 1]],
            ['primitive' => 'form', 'props' => []],
        ]]]);
        $this->assertNull($kept['error']);
        $this->assertCount(1, $kept['nodes'][0]['children']);
        $this->assertNotSame([], $kept['dropped'], 'the dropped element is named');

        $this->assertSame(CardDesign::EMPTY, CardDesign::accept([['primitive' => 'form']])['error']);
        $this->assertSame(CardDesign::TOO_LARGE, CardDesign::accept(array_fill(0, CardDesign::MAX_TOP_NODES + 1, ['primitive' => 'spacer']))['error']);
        $this->assertSame(CardDesign::TOO_LARGE, CardDesign::accept([['primitive' => 'text', 'props' => ['value' => str_repeat('x', CardDesign::MAX_BYTES)]]])['error']);
    }

    public function test_a_build_reads_the_card_the_reply_and_the_notes(): void
    {
        $asked = [];
        $out = (new CardBuild)->run($this->current(), new CardFacts('Study permit checklist'), new DefaultCardPolicy, function (string $source, array $problems) use (&$asked) {
            $asked[] = [$source, $problems];

            return "```\n".self::CARD."\n```\n".CardBuild::REPLY_MARK."\nI made the title the whole card.\nSuggestion: add the page's photo.";
        });

        $this->assertCount(1, $asked, 'a clean answer needs no repair');
        $this->assertStringContainsString('Old title', $asked[0][0], 'the model reads the card as it is now');
        $this->assertTrue($out['changed']);
        $this->assertFalse($out['refused']);
        $this->assertSame('heading', $out['nodes'][0]['children'][0]['primitive']);
        $this->assertSame('I made the title the whole card.', $out['reply']);
        $this->assertSame(["Suggestion: add the page's photo."], $out['notes']);
    }

    public function test_a_failing_check_gets_one_repair_with_its_problems(): void
    {
        $answers = [
            '<box><text value="Tiny words" body-size="sm"/><form/></box>'."\n".CardBuild::REPLY_MARK."\nDone.",
            self::CARD."\n".CardBuild::REPLY_MARK."\nFixed the size.",
        ];
        $problems = [];
        $out = (new CardBuild)->run($this->current(), new CardFacts('Study'), new DefaultCardPolicy, function (string $source, array $p) use (&$answers, &$problems) {
            $problems[] = $p;

            return array_shift($answers);
        });

        $this->assertTrue($out['repaired']);
        $this->assertSame([], $problems[0]);
        $this->assertNotSame([], $problems[1], 'the repair is told what failed');
        $this->assertSame('Fixed the size.', $out['reply']);
        $this->assertFalse($out['refused']);
    }

    public function test_an_unreadable_answer_changes_nothing_and_says_why(): void
    {
        $out = (new CardBuild)->run($this->current(), new CardFacts('Study'), new DefaultCardPolicy, fn () => CardBuild::REPLY_MARK."\nI could not.");

        $this->assertTrue($out['refused']);
        $this->assertFalse($out['changed']);
        $this->assertSame($this->current(), $out['nodes'], 'the card stays as it was');
        $this->assertSame(CardDesign::EMPTY, $out['reason']);
    }

    public function test_a_removed_pin_and_refused_words_are_reported_never_hidden(): void
    {
        $out = (new CardBuild)->run($this->current(), new CardFacts('Study'), $this->pinning(), fn () => '<heading text="Guaranteed approval" level="1"/>'."\n".CardBuild::REPLY_MARK."\nDone.");

        $this->assertFalse($out['refused'], 'the design is kept for the person to fix');
        $this->assertTrue($out['fell_back'], 'but the picture falls back to the automatic card');
        $this->assertSame('Promising an outcome is not allowed.', $out['reason']);
        $this->assertTrue($out['restored_identity'], 'the pinned line it dropped comes back');
    }

    public function test_the_card_language_names_only_what_the_parser_accepts(): void
    {
        foreach (CardSpec::STYLE_KEYS as $key) {
            $this->assertNotNull(PrimitiveSchema::styleChoices($key), "{$key} is a real style token");
        }

        $spec = CardSpec::describe();
        $this->assertStringContainsString('<credentials>', $spec);
        $this->assertStringNotContainsString('<form>', $spec);
        $this->assertDoesNotMatchRegularExpression('/[\x{2013}\x{2014}]/u', $spec);
    }

    public function test_the_builder_document_reopens_a_refused_design_with_its_reason(): void
    {
        $stored = ['source' => 'designed', 'nodes' => [['primitive' => 'heading', 'props' => ['text' => 'Guarantee', 'level' => 1]]], 'alt' => ' '];
        $doc = (new CardDocView)->present($stored, new CardFacts('Study'), $this->pinning());

        $this->assertSame('designed', $doc['source']);
        $this->assertTrue($doc['fell_back']);
        $this->assertSame('Guarantee', $doc['design'][0]['props']['text']);
        $this->assertNull($doc['alt'], 'a blank alt is no alt');

        $automatic = (new CardDocView)->present([], new CardFacts('Study'), new DefaultCardPolicy);
        $this->assertSame('automatic', $automatic['source']);
        $this->assertNull($automatic['design']);
    }
}
