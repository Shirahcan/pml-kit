<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;

/**
 * 0.32.1 (2026-11-18, owner: "Generated post included HTML entities e.g. &quot;"): a Build wrote an FAQ question as
 * `question="What does &quot;LMIA requested&quot; mean?"` and readers saw the entity. PML escapes with a backslash, so an
 * HTML entity in a quoted value or in words is read as its character; code keeps what it shows, and a lone "&" stays.
 */
final class EntitiesAreReadAsCharactersTest extends TestCase
{
    private static function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    public function test_an_entity_in_a_value_or_in_words_is_its_character(): void
    {
        $nodes = self::parser()->parse(
            '<heading text="What does &quot;LMIA requested&quot; mean?" level="2"/>'
            ."\n".'<p>Tom&#39;s fee &amp; the employer&rsquo;s fee, Q&A and R&D.</p>'
        )['nodes'];

        $this->assertSame('What does "LMIA requested" mean?', $nodes[0]['props']['text']);
        $this->assertSame("Tom's fee & the employer\u{2019}s fee, Q&A and R&D.", $nodes[1]['children'][0]['props']['value']);
    }

    public function test_code_keeps_its_entities_and_the_words_round_trip(): void
    {
        $parser = self::parser();
        $nodes = $parser->parse('<pre>if (a &amp;&amp; b)</pre>'."\n".'<heading text="Say &quot;hi&quot;" level="2"/>')['nodes'];
        $this->assertSame('if (a &amp;&amp; b)', $nodes[0]['props']['text'], 'a raw body shows exactly what was written');
        $this->assertSame('Say "hi"', $nodes[1]['props']['text']);

        $again = $parser->parse((new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]))['nodes'];
        $this->assertSame($nodes, $again, 'written back with a backslash escape, it reads the same');
    }
}
