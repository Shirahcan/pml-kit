<?php

namespace Shirahcan\Pml\Tests\Html;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Html\HtmlParity;
use Shirahcan\Pml\Html\HtmlToPml;

/**
 * The generic HTML-to-PML converter's PAGE profile (pml-kit 0.24.0, designer-sole-surface P3), on the shapes a written
 * blog post really holds after HTMLPurifier: h2/h3 sections, marked-up paragraphs with links, lists (nested too), a
 * quote, a figure with its caption, a code block, a table and a divider. The EMAIL profile is proven by the email
 * tests, which stayed unchanged.
 */
final class HtmlToPmlTest extends TestCase
{
    private const POST = '<p>Your <strong>study permit</strong> is <em>not</em> a visa. Read <a href="https://www.canada.ca/en/immigration.html">IRCC\'s guide</a> first.</p>'
        .'<h2>What you need</h2>'
        .'<ul><li><strong>Proof of funds</strong> for the first year</li><li>A letter of acceptance<ul><li>from a DLI</li></ul></li></ul>'
        .'<h3>Fees</h3>'
        .'<ol><li>The application fee</li><li>Biometrics</li></ol>'
        .'<blockquote><p>Apply as early as you can.</p></blockquote>'
        .'<figure><img src="https://media.example/permit.png" alt="A study permit"><figcaption>A sample permit, front side.</figcaption></figure>'
        .'<p><img src="https://media.example/line.png" alt="A timeline"></p>'
        .'<pre><code>Fee: $150</code></pre>'
        .'<table><tr><th>Item</th><th>Cost</th></tr><tr><td>Permit</td><td><code>$150</code></td></tr></table>'
        .'<hr>'
        .'<h4>Small print</h4><h5>Even smaller</h5>';

    /** @return list<string> */
    private static function primitives(array $nodes): array
    {
        return array_map(fn (array $n) => (string) $n['primitive'], $nodes);
    }

    public function test_a_written_posts_real_shapes_become_page_elements_with_no_words_or_links_lost(): void
    {
        $result = (new HtmlToPml())->convert(self::POST);
        $nodes = $result['nodes'];

        $this->assertSame([], $result['unconverted']);
        $this->assertSame([], HtmlParity::differences(self::POST, $nodes));
        $this->assertSame(
            ['p', 'heading', 'list', 'heading', 'list', 'blockquote', 'image', 'image', 'pre', 'table', 'divider', 'heading', 'heading'],
            self::primitives($nodes),
        );

        // Headings keep levels 1-4; a fifth level becomes the deepest a page uses, and says so.
        $this->assertSame([2, 3, 4, 4], array_values(array_map(fn (array $n) => $n['props']['level'], array_filter($nodes, fn (array $n) => $n['primitive'] === 'heading'))));
        $this->assertContains('A level-5 heading became level 4, the deepest a page uses.', $result['notes']);

        // A figure is an image with its caption; an image alone in a paragraph is that image.
        $this->assertSame(['src' => 'https://media.example/permit.png', 'alt' => 'A study permit', 'caption' => 'A sample permit, front side.'], $nodes[6]['props']);
        $this->assertSame(['src' => 'https://media.example/line.png', 'alt' => 'A timeline'], $nodes[7]['props']);

        // A code block keeps its text exactly; inline marks and the link keep their place in the paragraph.
        $this->assertSame(['text' => 'Fee: $150'], $nodes[8]['props']);
        $this->assertSame(['text', 'strong', 'text', 'emphasis', 'text', 'link', 'text'], self::primitives($nodes[0]['children']));
        $this->assertSame('https://www.canada.ca/en/immigration.html', $nodes[0]['children'][5]['props']['href']);

        // A bold opening label is the item's title; a nested list stays a list inside its item (0.25.0).
        $this->assertSame(['title' => 'Proof of funds', 'text' => 'for the first year'], $nodes[2]['children'][0]['props']);
        $this->assertSame(['text' => 'A letter of acceptance'], $nodes[2]['children'][1]['props']);
        $this->assertSame('list', $nodes[2]['children'][1]['children'][0]['primitive']);
        $this->assertSame(['text' => 'from a DLI'], $nodes[2]['children'][1]['children'][0]['children'][0]['props']);
        $this->assertSame('numbered', $nodes[4]['props']['type']);
    }

    public function test_a_list_item_with_marks_or_links_holds_them_as_its_words(): void
    {
        $html = '<ul><li><strong>Fees</strong> – pay at <a href="https://ircc.example/pay">IRCC</a> <em>online</em></li><li>Plain words</li></ul>';
        $result = (new HtmlToPml())->convert($html);
        $items = $result['nodes'][0]['children'];

        $this->assertSame([], $result['unconverted']);
        $this->assertSame([], HtmlParity::differences($html, $result['nodes']));
        $this->assertSame(['title' => 'Fees'], $items[0]['props']);
        $this->assertSame(['text', 'link', 'text', 'emphasis'], self::primitives($items[0]['children']));
        $this->assertSame('– pay at ', $items[0]['children'][0]['props']['value'], 'the space after the label is not kept, as text never kept it');
        $this->assertSame(['primitive' => 'list_item', 'props' => ['text' => 'Plain words']], $items[1], 'a plain item is exactly what it always was');
    }

    public function test_lists_nest_two_levels_and_a_third_becomes_words(): void
    {
        $html = '<ol><li>Apply<ul><li>Online<ul><li>Use the portal</li></ul></li></ul></li></ol>';
        $result = (new HtmlToPml())->convert($html);
        $top = $result['nodes'][0];
        $inner = $top['children'][0]['children'][0];

        $this->assertSame('numbered', $top['props']['type']);
        $this->assertSame('bullet', $inner['props']['type']);
        $this->assertSame(['text' => 'Online Use the portal'], $inner['children'][0]['props']);
        $this->assertArrayNotHasKey('children', $inner['children'][0]);
        $this->assertContains('A list three levels deep became part of its item\'s words ("Online Use the portal").', $result['notes']);
        $this->assertSame([], HtmlParity::differences($html, $result['nodes']));
    }

    public function test_an_element_with_no_page_home_is_described_in_unconverted_never_dropped(): void
    {
        $html = '<p>Watch this first.</p><iframe src="https://www.youtube.com/embed/abc"></iframe><p>Then <video src="https://cdn.example/v.mp4"></video> read on.</p>';
        $result = (new HtmlToPml())->convert($html);

        $this->assertSame(['an embedded frame (youtube.com)', 'a video (cdn.example) inside a paragraph'], $result['unconverted']);
        $this->assertSame(['p', 'p'], self::primitives($result['nodes']));
    }

    public function test_the_email_profile_keeps_email_rules_without_any_tokens(): void
    {
        $converter = new HtmlToPml();
        $email = $converter->convert('<h4>Deep</h4><p><img src="https://media.example/a.png" alt="A"></p><figure><img src="https://media.example/b.png" alt="B"></figure>', HtmlToPml::EMAIL);

        $this->assertSame(3, $email['nodes'][0]['props']['level']);
        $this->assertSame(['<img> inside a paragraph: ', '<figure>: '], $email['unconverted']);

        // The same converter switches back cleanly: nothing from the email run leaks into a page run.
        $page = $converter->convert('<h4>Deep</h4>');
        $this->assertSame(4, $page['nodes'][0]['props']['level']);
        $this->assertSame([], $page['unconverted']);
    }

    public function test_a_paragraph_pasted_into_a_heading_after_a_break_becomes_its_own_paragraph(): void
    {
        $pasted = (new HtmlToPml())->convert('<h2><strong>What is an LMIA? <br></strong>An LMIA is a document an employer gets from ESDC before hiring a foreign worker.</h2>');

        $this->assertSame(['primitive' => 'heading', 'props' => ['text' => 'What is an LMIA?', 'level' => 2]], $pasted['nodes'][0]);
        $this->assertSame('An LMIA is a document an employer gets from ESDC before hiring a foreign worker.', $pasted['nodes'][1]['children'][0]['props']['value']);
        $this->assertNotEmpty(array_filter($pasted['notes'], fn ($n) => str_contains($n, 'pasted inside a heading')));

        $twoLines = (new HtmlToPml())->convert('<h2>Study permits<br>in 2026</h2>');
        $this->assertCount(1, $twoLines['nodes'], 'a short second line stays in the heading');
        $this->assertSame('Study permits in 2026', $twoLines['nodes'][0]['props']['text']);
    }

    public function test_parity_catches_a_lost_word_and_a_lost_link(): void
    {
        $nodes = (new HtmlToPml())->convert(self::POST)['nodes'];
        $nodes[0]['children'][5]['props']['href'] = 'https://elsewhere.example';
        unset($nodes[1]);

        $differences = HtmlParity::differences(self::POST, array_values($nodes));

        $this->assertCount(2, $differences);
        $this->assertStringContainsString('Whatyouneed', $differences[0]); // words are compared without spacing
        $this->assertStringContainsString('https://www.canada.ca/en/immigration.html', $differences[1]);
    }
}
