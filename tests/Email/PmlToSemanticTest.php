<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\PmlToSemantic;
use Shirahcan\Pml\Email\SemanticParity;
use Shirahcan\Pml\Email\SemanticToPml;

/**
 * The design-to-semantic converter (pml-email C2c), proven as the inverse of SemanticToPml: a design converted to a
 * body and back is the same design, and the body carries every word, variable and link of the design, in the closed
 * semantic vocabulary only.
 */
final class PmlToSemanticTest extends TestCase
{
    /** The real body shapes SemanticToPmlTest covers, as a design. */
    private const BODY = '<h2>Booking Confirmed</h2>'
        .'<p>Hi {{recipient_name}}, see <a href="{{case_url}}">your case</a> and <strong>act now</strong> &amp; <em>soon</em>.</p>'
        .'<p>{{alert_info:Expires on <strong>{{expires_at}}</strong>.}}</p>'
        .'<p>{{action_button}}</p>'
        .'<hr>'
        .'<p>{{button:Reschedule = {{reschedule_url}}}}</p>'
        .'<p>{{code:Your code = {{code}}}}</p>'
        .'<p>{{summary_html}}</p>'
        .'<aside><p>A note card.</p></aside>'
        .'<blockquote><p>A quote.</p></blockquote>'
        .'<ul><li><p><strong>Payments</strong> Accept payments</p></li><li><p>Plain item</p></li></ul>'
        .'<ol><li><p>First</p></li></ol>'
        .'<table><tbody><tr><th colspan="1" rowspan="1"><p>Date</p></th><td colspan="1" rowspan="1"><p><strong>{{meeting_date}}</strong></p></td></tr></tbody></table>'
        .'<img src="https://example.com/a.png" alt="A picture">'
        .'<p>Line one<br>line two</p>'
        .'<p><small>Sent by {{platform_name}}.</small></p>';

    public function test_a_design_round_trips_through_its_semantic_body(): void
    {
        $design = (new SemanticToPml())->convert(self::BODY);
        $this->assertSame([], $design['unconverted']);

        $back = (new PmlToSemantic())->convert($design['nodes']);
        $this->assertSame([], $back['unconverted']);
        $this->assertSame([], $back['notes'], 'nothing in this design loses styling');

        $again = (new SemanticToPml())->convert($back['html']);
        $this->assertSame($design['nodes'], $again['nodes'], 'design -> body -> design is the same design');
        $this->assertSame([], SemanticParity::differences($back['html'], $design['nodes']), 'the body carries every word, variable and link');
    }

    public function test_the_body_uses_only_the_semantic_vocabulary(): void
    {
        $html = (new PmlToSemantic())->convert((new SemanticToPml())->convert(self::BODY)['nodes'])['html'];

        $this->assertDoesNotMatchRegularExpression('/\s(style|class)=/i', $html);
        $this->assertDoesNotMatchRegularExpression('/<(div|span|font|center)[\s>]/i', $html);
    }

    public function test_styling_with_no_semantic_home_keeps_its_words_and_says_so(): void
    {
        $result = (new PmlToSemantic())->convert([
            ['primitive' => 'box', 'style' => ['background' => 'surface'], 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Boxed words']]]]]],
            ['primitive' => 'spacer', 'props' => ['size' => 'md']],
            ['primitive' => 'callout', 'props' => ['tone' => 'warning'], 'children' => [
                ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'One']]]],
                ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Two']]]],
            ]],
            ['primitive' => 'rich_text', 'props' => ['markdown' => "**Bold** words\n\n- an item"]],
        ]);

        $this->assertSame([], $result['unconverted']);
        $this->assertStringContainsString('<p>Boxed words</p>', $result['html']);
        $this->assertStringContainsString('<aside><p>One</p><p>Two</p></aside>', $result['html']);
        $this->assertStringContainsString('<strong>Bold</strong> words', $result['html']);
        $this->assertStringContainsString('<li><p>an item</p></li>', $result['html']);
        $this->assertCount(4, $result['notes']);
    }

    public function test_what_an_html_body_cannot_hold_is_unconverted(): void
    {
        $result = (new PmlToSemantic())->convert([
            ['primitive' => 'carousel', 'children' => []],
            ['primitive' => 'button', 'props' => ['label' => 'a = b', 'href' => 'https://x.test']],
        ]);

        $this->assertCount(2, $result['unconverted']);
    }
}
