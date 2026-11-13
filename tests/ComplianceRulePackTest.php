<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Compliance\ComplianceRulePack;

/**
 * The one rule pack every product's public copy passes (shirahcan/pml 0.6.0, shared program E2g).
 *
 * ⚠ WHAT THIS GUARDS: a guaranteed outcome and a claimed government tie are found in plain prose (what a newsletter
 * or a post body sends); an unattested testimonial blocks even when nested inside a container; machine values (urls,
 * layout tokens) are never read as claims; plain honest copy passes.
 */
class ComplianceRulePackTest extends TestCase
{
    public function test_a_guarantee_and_a_government_tie_are_found_in_plain_prose(): void
    {
        $pack = new ComplianceRulePack;

        $this->assertNotSame([], $pack->scanText('We guarantee your visa approval in 30 days.')->violations());
        $this->assertNotSame([], $pack->scanText('We are an official IRCC partner.')->violations());
        $this->assertSame([], $pack->scanText('A licensed consultant prepares and files your application. IRCC decides.')->violations());
    }

    public function test_an_unattested_testimonial_blocks_even_when_nested(): void
    {
        $blocks = [['type' => 'grid', 'config' => ['items' => [
            ['type' => 'testimonials', 'config' => ['items' => [['quote' => 'They were wonderful.', 'author' => 'A client']]]],
        ]]]];

        $this->assertTrue((new ComplianceRulePack)->scanBlocks($blocks)->isBlocked());
    }

    public function test_machine_values_are_never_read_as_claims(): void
    {
        $this->assertFalse(ComplianceRulePack::looksLikeProse('href', 'https://example.com/guaranteed-approval'));
        $this->assertFalse(ComplianceRulePack::looksLikeProse('padding', 'spacious'));
        $this->assertTrue(ComplianceRulePack::looksLikeProse('headline', 'Guaranteed approval'));
    }
}
