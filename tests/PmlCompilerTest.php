<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\ComplianceRulePack;
use Shirahcan\Pml\Natives;
use Shirahcan\Pml\PmlCompiler;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * The compiler in the package (shirahcan/pml 0.8.0, shared program E2g): it compiles a tree with no framework and
 * scans its visible words with the one rule pack, so a guarantee in a heading is found and a url is not.
 */
class PmlCompilerTest extends TestCase
{
    private function compiler(): PmlCompiler
    {
        $blocks = new BlockSchema;

        return new PmlCompiler(new PrimitiveSchema($blocks), $blocks, new ComplianceRulePack);
    }

    protected function setUp(): void
    {
        Natives::declare(['owner' => ['description' => 'Owner.', 'fields' => []]]);
    }

    public function test_a_tree_compiles_and_its_words_are_scanned_with_the_one_pack(): void
    {
        $compiled = $this->compiler()->compile([
            ['uid' => 'heading1', 'primitive' => 'heading', 'props' => ['text' => 'We guarantee your visa approval', 'level' => '2']],
        ]);

        $this->assertNotSame([], $compiled);
        $this->assertNotSame([], $this->compiler()->scanCompliance($compiled)->violations());
    }

    public function test_honest_copy_passes_the_scan(): void
    {
        $compiled = $this->compiler()->compile([
            ['uid' => 'heading1', 'primitive' => 'heading', 'props' => ['text' => 'A licensed consultant files; IRCC decides', 'level' => '2']],
        ]);

        $this->assertSame([], $this->compiler()->scanCompliance($compiled)->violations());
    }
}
