<?php

namespace Shirahcan\Pml\Tests\Profile;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Component\ParamSchema;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\Profile\ComponentProfile;
use Shirahcan\Pml\Profile\Profiles;

/**
 * A component's design as a profile (shared PML Studio P3c-5, 2026-11-25): one outer element, only its declared
 * settings, slots where a page adds its own, and a design that compiles with every setting holding a sample.
 */
final class ComponentProfileTest extends TestCase
{
    protected function setUp(): void
    {
        \Shirahcan\Pml\Natives::declare(['owner' => ['description' => 'Owner.', 'fields' => []]]);
    }

    /** @return list<array<string, mixed>> */
    private static function nodes(string $pml): array
    {
        return array_values((new PmlParser(new PmlScanner(), new MarkdownToDoc()))->parse($pml)['nodes'] ?? []);
    }

    /** @param list<array<string, mixed>> $raw */
    private static function profile(array $raw = []): ComponentProfile
    {
        return new ComponentProfile((new ParamSchema())->normalise($raw));
    }

    public function test_it_is_known_by_its_key_and_holds_the_whole_catalog(): void
    {
        $profile = Profiles::for('component');

        $this->assertSame('component', $profile->key());
        $this->assertNull($profile->elements());
        $this->assertSame([], $profile->pieces());
        $this->assertStringContainsString('{props.name}', (string) $profile->spec());
        $this->assertStringContainsString('long-text', (string) $profile->spec(), 'the kinds come from the shape rules');
    }

    public function test_a_design_is_one_outer_element_and_a_note_beside_it_is_not_a_second(): void
    {
        $two = self::profile()->violations(self::nodes('<heading text="A" level="2"/><text value="B"/>'));
        $this->assertSame([['code' => 'one_root', 'at' => '', 'text' => ComponentProfile::ONE_ROOT]], $two);

        $this->assertSame([], self::profile()->violations([['comment' => 'Purpose: the hero'], ...self::nodes('<box><text value="B"/></box>')]));
    }

    public function test_it_reads_only_the_settings_it_declares(): void
    {
        $profile = self::profile([['name' => 'title', 'label' => 'Title', 'default' => 'Counter']]);

        $this->assertSame([], $profile->violations(self::nodes('<box><heading text={props.title} level="2"/></box>')));

        $bare = $profile->violations(self::nodes('<box><heading text={title} level="2"/></box>'));
        $this->assertSame('unknown_setting', $bare[0]['code']);
        $this->assertStringContainsString('props.title', $bare[0]['text']);

        $typo = $profile->violations(self::nodes('<box><heading text={props.titel} level="2"/></box>'));
        $this->assertSame('unknown_setting', $typo[0]['code']);
    }

    public function test_a_list_setting_slots_and_a_named_slot_compile_with_samples(): void
    {
        $profile = self::profile([
            ['name' => 'plans', 'label' => 'Plans', 'kind' => 'list', 'fields' => [['name' => 'name', 'kind' => 'text'], ['name' => 'price', 'kind' => 'number']]],
        ]);
        $design = '<box><box each={plan in props.plans}><text value={plan.name}/></box><slot/><box><slot name="actions"/></box></box>';

        $this->assertSame([], $profile->violations(self::nodes($design)));
    }

    public function test_a_design_that_does_not_compile_says_why(): void
    {
        $out = self::profile()->violations(self::nodes('<box><nope/></box>'));

        $this->assertSame('invalid', $out[0]['code'] ?? null);
        $this->assertNotSame('', $out[0]['text']);
    }
}
