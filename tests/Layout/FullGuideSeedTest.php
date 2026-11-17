<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * The "Full guide" post layout (post layouts A2): the owner's default, transcribed from the Express Entry reference.
 * A fresh post built from it already follows it, so New post starts on the right structure.
 */
class FullGuideSeedTest extends TestCase
{
    private function layout(): LayoutDefinition
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/resources/layouts/post.full-guide.json'), true);

        return LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));
    }

    /** @param list<array<string, mixed>> $nodes @return list<string> */
    private static function parts(array $nodes): array
    {
        $out = [];

        foreach ($nodes as $node) {
            if (isset($node['part'])) {
                $out[] = $node['part'];
            }
            array_push($out, ...self::parts($node['children'] ?? []));
        }

        return $out;
    }

    public function test_the_seed_is_a_valid_layout_with_the_formats_it_allows(): void
    {
        $layout = $this->layout();

        $this->assertSame('full-guide', $layout->key);
        $this->assertSame(['explainer', 'how-to', 'document-checklist', 'comparison', 'qa-roundup'], $layout->allowedFormats);
        $this->assertSame(['max' => 200, 'min' => 150], $layout->part('hook')['rules']['words']);
        $this->assertSame([5, 5, 'faq'], [$layout->part('faq-item')['min'], $layout->part('faq-item')['max'], $layout->part('faq-item')['inside']]);
        $this->assertFalse($layout->part('story')['required'], 'a story only from the consented bank, so it is optional');
    }

    public function test_a_fresh_post_from_the_layout_already_follows_it(): void
    {
        $layout = $this->layout();
        $starter = $layout->starter();
        $parts = self::parts($starter);

        $this->assertSame(4, count(array_keys($parts, 'section')));
        $this->assertSame(4, count(array_keys($parts, 'answer')));
        $this->assertSame(2, count(array_keys($parts, 'data')));
        $this->assertSame(5, count(array_keys($parts, 'faq-item')));
        $this->assertSame(['cta-awareness', 'cta-consideration', 'cta-decision', 'ask', 'closing'], array_values(array_filter($parts, static fn ($p) => in_array($p, ['cta-awareness', 'cta-consideration', 'cta-decision', 'ask', 'closing'], true))));

        $result = LayoutChecks::check($layout, $starter);

        $this->assertTrue($result['mayPublish'], 'the structure of a fresh post passes: '.json_encode(array_values(array_filter($result['findings'], static fn ($f) => $f['severity'] === 'block'))));
    }

    public function test_the_seed_never_writes_a_figure_or_a_story(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__, 2).'/resources/layouts/post.full-guide.json');

        $this->assertStringNotContainsString(LayoutChecks::MARKER, $raw, 'guidance must not trip the lock it describes');
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d{2,},\d{3}/', $raw, 'no fees or figures in a skeleton');
        // The story part holds only the element that draws a consented story from the bank: never story words.
        $this->assertSame([['primitive' => 'post_story', 'part' => 'story', 'props' => ['heading' => 'Client story']]], $this->layout()->part('story')['skeleton']);
    }
}
