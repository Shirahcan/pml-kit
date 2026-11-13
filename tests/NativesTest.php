<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\Natives;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\Blocks\BlockSchema;

/**
 * The PSL natives contract (shirahcan/pml 0.4.0, shared program E2f).
 *
 * ⚠ WHAT THIS GUARDS: the language owns the browser natives and a product cannot redeclare one; the product's server
 * natives come first in the roots; the items a list repeats over are the product's; and a page parses against the
 * declared natives.
 */
class NativesTest extends TestCase
{
    protected function setUp(): void
    {
        Natives::declare([
            'owner' => ['description' => 'Who owns the site.', 'fields' => ['name' => ['description' => 'Their name.', 'example' => 'Acme']]],
            'site' => ['description' => 'The site.', 'fields' => []],
        ], ['p' => ['title' => ['description' => 'A page title.', 'example' => 'Home']]]);
    }

    public function test_server_natives_come_first_then_the_browsers(): void
    {
        $this->assertSame(['owner', 'site', 'state', 'location', 'device', 'now', 'props', 'self'], Natives::roots());
        $this->assertTrue(Natives::manifest()['state']['browser']);
        $this->assertFalse(Natives::manifest()['owner']['browser']);
        $this->assertSame(['p'], array_keys(Natives::items()));
    }

    public function test_a_product_cannot_redeclare_a_browser_native(): void
    {
        $this->expectException(RuntimeException::class);

        Natives::declare(['state' => ['description' => 'Mine.', 'fields' => []]]);
    }

    public function test_a_page_parses_against_the_declared_natives(): void
    {
        $parser = new PmlParser(new PmlScanner, new MarkdownToDoc(new BlockSchema));
        $nodes = $parser->parse('<heading text={owner.name} />')['nodes'];

        $this->assertCount(1, $nodes);
        $this->assertSame('heading', $nodes[0]['primitive'] ?? $nodes[0]['component'] ?? null);
    }
}
