<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailProfile;
use Shirahcan\Pml\Email\EmailRenderer;
use Shirahcan\Pml\Email\NullBrand;
use Shirahcan\Pml\Email\SemanticParity;
use Shirahcan\Pml\Email\SemanticTokens;
use Shirahcan\Pml\Email\SemanticToPml;

/**
 * The semantic-to-PML converter (pml-email B2a), on the shapes real email bodies use: several block tokens in one
 * paragraph, markup inside an alert, a variable inside a token's value, bold-labelled list items, prepared HTML.
 */
final class SemanticToPmlTest extends TestCase
{
    private const BODY = '<h2>Booking Confirmed {{badge_success:Confirmed}}</h2>'
        .'<p>Hi {{recipient_name}}, see <a href="{{case_url}}">your case</a> and <strong>act now</strong>.</p>'
        .'<p>{{alert_info:Expires on <strong>{{expires_at}}</strong>.}}{{action_button}}{{divider}}</p>'
        .'<p>{{button:{{action_label}} = {{action_url}}}}</p>'
        .'<p>{{button:Reschedule = {{reschedule_url}}}}</p>'
        .'<p>{{code:Your code = {{code}}}}{{alert_error:Do not share it.}}</p>'
        .'<p>{{summary_html}}</p>'
        .'<aside><p>A note card.</p></aside>'
        .'<ul><li><p><strong>Payments</strong> – Accept payments</p></li><li><p>Plain item</p></li></ul>'
        .'<table><tbody><tr><td><p>Date</p></td><td><p><strong>{{meeting_date}}</strong></p></td></tr></tbody></table>'
        .'<p><small>Sent by {{platform_name}}.</small></p>';

    /** @return list<string> */
    private static function primitives(array $nodes): array
    {
        return array_map(fn (array $n) => (string) $n['primitive'], $nodes);
    }

    public function test_tokens_split_by_counting_braces(): void
    {
        $pieces = SemanticTokens::split('Hi {{name}} {{alert_info:A <b>{{x}}</b>}}{{action_button}}{{code:Code = {{code}}}} end');

        $this->assertSame(['html', 'token', 'token', 'token', 'html'], array_column($pieces, 'kind'));
        $this->assertSame('A <b>{{x}}</b>', $pieces[1]['body']);
        $this->assertSame(['Code', '{{code}}'], SemanticTokens::pair($pieces[3]['body']));
        $this->assertSame('Hi {{name}} ', $pieces[0]['html'], 'a plain variable stays in the words');
    }

    public function test_every_semantic_block_and_token_becomes_its_email_element(): void
    {
        $result = (new SemanticToPml())->convert(self::BODY);
        $nodes = $result['nodes'];

        $this->assertSame(
            ['heading', 'p', 'callout', 'button', 'divider', 'button', 'button', 'box', 'callout', 'text', 'callout', 'list', 'table', 'p'],
            self::primitives($nodes),
        );
        $this->assertSame(['text' => 'Booking Confirmed Confirmed', 'level' => 2], $nodes[0]['props']);
        $this->assertSame('{{case_url}}', $nodes[1]['children'][1]['props']['href'], 'a variable address survives re-serialising');
        $this->assertSame('info', $nodes[2]['props']['tone']);
        $this->assertSame('strong', $nodes[2]['children'][0]['children'][1]['primitive'], 'markup inside an alert is kept');
        $this->assertSame(['label' => '{{action_label}}', 'href' => '{{action_url}}', 'variant' => 'primary'], $nodes[3]['props']);
        $this->assertSame(['align' => 'center'], $nodes[3]['style'], 'buttons draw filled and centred, as today');
        $this->assertSame(['label' => 'Reschedule', 'href' => '{{reschedule_url}}', 'variant' => 'primary'], $nodes[6]['props']);
        $this->assertSame('{{code}}', $nodes[7]['children'][1]['props']['text']);
        $this->assertSame('danger', $nodes[8]['props']['tone']);
        $this->assertSame(['value' => '{{summary_html}}'], $nodes[9]['props'], 'prepared HTML stands alone, never in a paragraph');
        $this->assertArrayNotHasKey('props', $nodes[10], 'a note card has no tone');
        $this->assertSame(['title' => 'Payments', 'text' => '– Accept payments'], $nodes[11]['children'][0]['props']);
        $this->assertSame('p', $nodes[12]['children'][0]['children'][1]['children'][0]['primitive'], 'a cell holds a paragraph');
        $this->assertSame('small', $nodes[13]['children'][0]['primitive'], 'fine print is a small inside a paragraph, as PML nests it');
        $this->assertSame([], $result['unconverted']);
        $this->assertCount(1, $result['notes'], 'the heading badge is listed as a change');
    }

    public function test_the_conversion_keeps_every_word_variable_and_link_and_passes_the_email_profile(): void
    {
        $nodes = (new SemanticToPml())->convert(self::BODY)['nodes'];

        $this->assertSame([], SemanticParity::differences(self::BODY, $nodes));
        $this->assertSame([], EmailProfile::violations($nodes));
    }

    public function test_parity_catches_a_lost_word_a_lost_variable_and_a_lost_link(): void
    {
        $nodes = (new SemanticToPml())->convert(self::BODY)['nodes'];
        $nodes[1]['children'][0]['props']['value'] = 'Hello, see ';
        $nodes[1]['children'][1]['props']['href'] = 'https://elsewhere.example';

        $differences = SemanticParity::differences(self::BODY, $nodes);

        $this->assertCount(3, $differences);
        $this->assertStringContainsString('recipient_name', implode(' ', $differences));
    }

    public function test_a_converted_design_renders_with_no_token_left_and_no_table_in_a_paragraph(): void
    {
        $nodes = (new SemanticToPml())->convert(self::BODY)['nodes'];

        $html = (new EmailRenderer())->render($nodes, new NullBrand(), [], [], [], ['summary_html' => '<table><tr><td>S</td></tr></table>', 'action_url' => 'https://x.example', 'action_label' => 'Open', 'reschedule_url' => 'https://x.example/r']);

        $this->assertDoesNotMatchRegularExpression('/\{\{(?:alert_|badge_|button:|code:|action_button|divider)/', $html);
        $this->assertDoesNotMatchRegularExpression('#<p(?:\s[^>]*)?>(?:(?!</p>).)*<table#s', $html);
        $this->assertSame(3, substr_count($html, 'class="pml-email-button"'));
    }

    public function test_a_link_inside_a_list_item_is_not_converted(): void
    {
        $result = (new SemanticToPml())->convert('<ul><li><p>See <a href="https://x.example">this</a></p></li></ul>');

        $this->assertCount(1, $result['unconverted'], 'the caller keeps the old body rather than lose the link');
    }
}
