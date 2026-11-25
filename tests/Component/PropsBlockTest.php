<?php

namespace Shirahcan\Pml\Tests\Component;

use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSyntaxException;
use Shirahcan\Pml\Component\PropsBlock;
use Shirahcan\Pml\Component\ParamSchema;
use PHPUnit\Framework\TestCase;

/**
 * Component props p03a (2026-11-11): a component's settings read and written as a `<props>` block at the top
 * of its code, so the Code view says where `props.title` comes from.
 */
final class PropsBlockTest extends TestCase
{
    private const BLOCK = <<<'PML'
        <props>
          <prop name="title" kind="text" label="Title" required default="Counter"/>
          <prop name="intro" kind="rich-text" label="Introduction" default="Pick a **plan**, \"now\"."/>
          <prop name="start" kind="number" label="Starts at" default={0}/>
          <prop name="show_reset" kind="yes-no" label="Show reset" default={true}/>
          <prop name="size" kind="enum" label="Size" options="small|large" default="small"/>
          <prop name="tone" kind="style" label="Background" style="background" default="surface"/>
          <prop name="tags" kind="list" label="Tags" of="text" default={["Study", "Work"]}/>
          <prop name="plans" kind="list" label="Plans" default={[{name: "Starter", price: 49}]}>
            <prop name="name" kind="text" label="Name"/>
            <prop name="price" kind="number" label="Price"/>
          </prop>
          <prop name="contact" kind="map" label="Contact">
            <prop name="email" kind="text" label="Email"/>
            <prop name="hours" kind="list" label="Hours" of="text"/>
          </prop>
        </props>
        PML;

    private function block(): PropsBlock
    {
        return new PropsBlock(new PmlParser(new PmlScanner(), new MarkdownToDoc()), new ParamSchema());
    }

    public function test_every_kind_reads_and_writes_back_the_same(): void
    {
        $read = $this->block()->read(self::BLOCK."\n\n<box><slot/></box>");

        $this->assertSame("<box><slot/></box>", $read['body']);
        $this->assertSame(['title', 'intro', 'start', 'show_reset', 'size', 'tone', 'tags', 'plans', 'contact'], array_column($read['params'], 'name'));
        $this->assertSame('Pick a **plan**, "now".', $read['params'][1]['default']);
        $this->assertSame(['small', 'large'], $read['params'][4]['options']);
        $this->assertSame(['name', 'price'], array_column($read['params'][7]['fields'], 'name'));
        $this->assertTrue($read['params'][0]['required']);

        $this->assertSame(self::BLOCK, $this->block()->write($read['params']));
    }

    public function test_code_without_a_block_has_no_settings(): void
    {
        $this->assertSame(['params' => [], 'body' => '<box/>'], $this->block()->read('<box/>'));
        $this->assertSame('', $this->block()->write([]));
    }

    public function test_a_fault_points_at_the_setting_line(): void
    {
        try {
            $this->block()->read("<props>\n  <prop name=\"title\" kind=\"text\" label=\"Title\"/>\n  <prop name=\"count\" kind=\"counter\" label=\"Count\"/>\n</props>\n<box/>");
            $this->fail('An unknown kind was read.');
        } catch (PmlSyntaxException $e) {
            $this->assertStringStartsWith('In <props>, "count": Choose a kind:', $e->getMessage());
            $this->assertSame(3, $e->atLine);
        }
    }

    public function test_only_props_go_in_the_block(): void
    {
        $this->expectException(PmlSyntaxException::class);
        $this->expectExceptionMessage('Only <prop> elements go inside <props>');

        $this->block()->read("<props>\n  <heading text=\"No\"/>\n</props>\n<box/>");
    }
}
