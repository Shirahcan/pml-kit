<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\PrimitiveSchema;

/** A layout's one validated shape (post layouts A1b): what a layout may say, never whether a post follows it. */
class LayoutDefinitionTest extends TestCase
{
    /** @return array<string, mixed> */
    private function fullGuideShaped(): array
    {
        return [
            'key' => 'full-guide',
            'name' => 'Full guide',
            'kind' => 'post',
            'allowed_formats' => ['explainer', 'how-to-guide', 'explainer'],
            'parts' => [
                ['key' => 'header', 'label' => 'Post header', 'kind' => 'bound', 'locked' => true],
                ['key' => 'hook', 'label' => 'Hook', 'kind' => 'fill', 'rules' => ['words' => ['max' => 200, 'min' => 150]],
                    'skeleton' => [['primitive' => 'p', 'part' => 'hook', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Start with why this matters now.']]]]]],
                ['key' => 'cta-awareness', 'label' => 'Call to action: awareness', 'kind' => 'cta', 'stage' => 'awareness', 'locked' => true],
                ['key' => 'body', 'label' => 'Question sections', 'kind' => 'repeat', 'min' => 4],
                ['key' => 'cta-consideration', 'label' => 'Call to action: consideration', 'kind' => 'cta', 'stage' => 'consideration', 'moves_within' => 'body'],
                ['key' => 'story', 'label' => 'Client story', 'kind' => 'record', 'required' => false],
                ['key' => 'cta-decision', 'label' => 'Call to action: decision', 'kind' => 'cta', 'stage' => 'decision', 'locked' => true],
                ['key' => 'faq', 'label' => 'FAQs', 'kind' => 'fill'],
                ['key' => 'faq-item', 'label' => 'FAQ', 'kind' => 'repeat', 'min' => 5, 'max' => 5, 'inside' => 'faq', 'rules' => ['words' => ['min' => 40, 'max' => 60]]],
                ['key' => 'closing', 'label' => 'Closing banner', 'kind' => 'cta', 'stage' => 'decision', 'variant' => 'banner', 'locked' => true],
            ],
        ];
    }

    public function test_a_full_guide_shaped_layout_reads_and_writes_one_canonical_form(): void
    {
        $layout = LayoutDefinition::fromArray($this->fullGuideShaped(), new PrimitiveSchema(new BlockSchema()));

        $this->assertSame(['explainer', 'how-to-guide'], $layout->allowedFormats);
        $this->assertSame(['max' => 200, 'min' => 150], $layout->part('hook')['rules']['words']);
        $this->assertSame([5, 5, 'faq'], [$layout->part('faq-item')['min'], $layout->part('faq-item')['max'], $layout->part('faq-item')['inside']]);
        $this->assertSame(['inline', 'body'], [$layout->part('cta-consideration')['variant'], $layout->part('cta-consideration')['moves_within']]);
        $this->assertFalse($layout->part('story')['required']);
        $this->assertTrue($layout->part('header')['required']);
        $this->assertNull($layout->part('nope'));

        $again = LayoutDefinition::fromArray($layout->toArray());
        $this->assertSame($layout->toArray(), $again->toArray());
    }

    /** @return array<string, array{0: callable(array): array, 1: string, 2: string}> */
    public static function mistakes(): array
    {
        return [
            'unknown kind' => [fn ($d) => ['kind' => 'page'] + $d, 'layout.kind', 'post'],
            'no parts' => [fn ($d) => ['parts' => []] + $d, 'layout.parts', 'at least one'],
            'unknown part kind' => [function ($d) { $d['parts'][1]['kind'] = 'freeform'; return $d; }, 'layout.parts.1.kind', 'bound, fill'],
            'two parts, one name' => [function ($d) { $d['parts'][2]['key'] = 'hook'; return $d; }, 'layout.parts.2.key', 'Two parts'],
            'cta without a stage' => [function ($d) { unset($d['parts'][2]['stage']); return $d; }, 'layout.parts.2.stage', 'one stage'],
            'min above max' => [function ($d) { $d['parts'][8]['min'] = 6; return $d; }, 'layout.parts.8', 'how many'],
            'min on a fill part' => [function ($d) { $d['parts'][1]['min'] = 2; return $d; }, 'layout.parts.1', 'Only a repeating part'],
            'moves within nowhere' => [function ($d) { $d['parts'][4]['moves_within'] = 'sidebar'; return $d; }, 'layout.parts.4.moves_within', 'another part'],
            'inside two levels' => [function ($d) { $d['parts'][3]['inside'] = 'faq-item'; return $d; }, 'layout.parts.3.inside', 'one level'],
            'rule min above max' => [function ($d) { $d['parts'][1]['rules']['words'] = ['min' => 300, 'max' => 200]; return $d; }, 'layout.parts.1.rules.words', 'cannot be above'],
            'bad skeleton element' => [function ($d) { $d['parts'][1]['skeleton'] = [['primitive' => 'p', 'part' => '9 bad']]; return $d; }, 'layout.parts.1.skeleton.0.part', 'part'],
        ];
    }

    #[DataProvider('mistakes')]
    public function test_each_mistake_names_its_part_and_the_reason(callable $break, string $path, string $words): void
    {
        try {
            LayoutDefinition::fromArray($break($this->fullGuideShaped()), new PrimitiveSchema(new BlockSchema()));
            $this->fail('The layout should have been refused.');
        } catch (InvalidPmlException $e) {
            $this->assertStringContainsString($words, $e->getMessage());
            $this->assertSame($path, $e->path);
        }
    }
}
