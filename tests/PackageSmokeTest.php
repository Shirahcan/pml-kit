<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\Psl\Psl;

/**
 * The package stands on its own (shirahcan/pml 0.1.0, shared program E2a): the ONE element definition and the PSL
 * standard library ship inside it, and the language runs with no framework booted.
 */
class PackageSmokeTest extends TestCase
{
    public function test_the_element_definition_ships_with_the_package(): void
    {
        $this->assertFileExists(ElementCatalog::path());
        $this->assertStringContainsString('pml-kit', str_replace('\\', '/', (string) realpath(ElementCatalog::path())));
        $this->assertTrue(ElementCatalog::has('heading'));
        $this->assertTrue(ElementCatalog::isContainer('box'));
    }

    public function test_psl_compiles_without_a_booted_app(): void
    {
        $out = (new Psl)->compile('state.count + 1');

        $this->assertIsArray($out);
    }
}
