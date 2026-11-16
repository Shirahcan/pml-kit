<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailBrand;
use Shirahcan\Pml\Email\EmailRenderer;

/**
 * The PML email renderer (pml-email A3a): every email element draws as table-and-inline-style HTML, the standard
 * header and footer wrap the email unless it opts out, and values fill once, escaped.
 */
final class EmailRendererTest extends TestCase
{
    private function brand(): EmailBrand
    {
        return new class implements EmailBrand
        {
            public function productName(): string
            {
                return 'Portify';
            }

            public function colors(): array
            {
                return ['accent' => '#4361EE', 'nonsense' => 'red'];
            }

            public function fontStack(): string
            {
                return 'Arial, sans-serif';
            }
        };
    }

    private const DESIGN = [
        ['primitive' => 'heading', 'props' => ['text' => 'Your meeting has moved', 'level' => 2]],
        ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi {{recipient_name}}, ']], ['primitive' => 'strong', 'children' => [['primitive' => 'text', 'props' => ['value' => 'see below']]]]]],
        ['primitive' => 'callout', 'props' => ['tone' => 'warning', 'title' => 'Heads up'], 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Bring your passport.']]]]]],
        ['primitive' => 'button', 'props' => ['label' => '{{action_text}}', 'href' => '{{action_url}}', 'variant' => 'primary'], 'style' => ['align' => 'center']],
        ['primitive' => 'image', 'props' => ['src' => 'https://portify.co/x.png', 'alt' => 'A picture', 'size' => 'sm']],
        ['primitive' => 'divider'],
        ['primitive' => 'grid', 'props' => ['columns' => 2], 'children' => [['primitive' => 'box', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Left']]]]]], ['primitive' => 'box', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Right']]]]]]]],
        ['primitive' => 'rich_text', 'props' => ['markdown' => "A **bold** word and a [link](https://portify.co)."]],
    ];

    private const HEADER = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'PORTIFY HEADER']]]]];

    private const FOOTER = [['primitive' => 'small', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Sent by {{platform_name}}.']]]]];

    public function test_every_element_draws_as_email_html(): void
    {
        $html = (new EmailRenderer())->render(self::DESIGN, $this->brand(), ['subject' => 'Moved'], self::HEADER, self::FOOTER);

        $this->assertStringContainsString('<h2 style="', $html);
        $this->assertStringContainsString('<strong>see below</strong>', $html);
        $this->assertStringContainsString('class="pml-email-box"', $html, 'a callout carries its dark-mode class');
        $this->assertStringContainsString('Heads up', $html);
        $this->assertStringContainsString('class="pml-email-button" bgcolor="#4361EE"', $html, 'a bulletproof button');
        $this->assertStringContainsString('alt="A picture" width="240"', $html);
        $this->assertSame(2, substr_count($html, 'class="pml-email-col"'), 'two columns');
        $this->assertStringContainsString('<strong>bold</strong>', $html);
        $this->assertStringContainsString('href="https://portify.co"', $html);
        $this->assertStringContainsString('max-width:600px', $html);
        $this->assertStringNotContainsString('nonsense', $html, 'a brand colour that is not a colour is ignored');
    }

    public function test_real_mail_clients_get_dark_mode_a_preheader_outlook_and_a_phone_layout(): void
    {
        $html = (new EmailRenderer())->render(self::DESIGN, $this->brand(), ['preheader' => 'Your new time is inside'], self::HEADER, self::FOOTER);

        $this->assertStringContainsString('<meta name="color-scheme" content="light dark">', $html);
        $this->assertMatchesRegularExpression('/prefers-color-scheme:dark\)\{.*\.pml-email-box\{background-color:#1F2937!important\}/', $html, 'boxes recolour with the text in dark mode');
        $this->assertStringContainsString('Your new time is inside', $html);
        $this->assertStringContainsString('display:none;max-height:0', $html, 'the preheader is hidden in the email itself');
        $this->assertStringContainsString('<!--[if mso]><table role="presentation" width="600"', $html, 'Outlook gets a fixed width');
        $this->assertStringContainsString('.pml-email-col{display:block!important', $html, 'columns stack on a phone');
    }

    public function test_the_plain_text_part_carries_every_word_and_every_address(): void
    {
        $text = (new EmailRenderer())->text(self::DESIGN, [], self::HEADER, self::FOOTER, ['recipient_name' => 'Ada', 'action_text' => 'View', 'action_url' => 'https://portify.co/c/1', 'platform_name' => 'Portify']);

        $this->assertStringContainsString('PORTIFY HEADER', $text);
        $this->assertStringContainsString('Your meeting has moved', $text);
        $this->assertStringContainsString('Hi Ada, see below', $text);
        $this->assertStringContainsString("Heads up\nBring your passport.", $text);
        $this->assertStringContainsString('View: https://portify.co/c/1', $text);
        $this->assertStringContainsString('link (https://portify.co)', $text);
        $this->assertStringContainsString('Sent by Portify.', $text);
        $this->assertStringNotContainsString('<', $text);
    }

    public function test_the_standard_header_and_footer_wrap_the_email_unless_it_opts_out(): void
    {
        $with = (new EmailRenderer())->render(self::DESIGN, $this->brand(), [], self::HEADER, self::FOOTER);
        $without = (new EmailRenderer())->render(self::DESIGN, $this->brand(), ['chrome' => 'none'], self::HEADER, self::FOOTER);

        $this->assertStringContainsString('PORTIFY HEADER', $with);
        $this->assertStringContainsString('pml-email-footer', $with);
        $this->assertStringNotContainsString('PORTIFY HEADER', $without);
        $this->assertStringNotContainsString('pml-email-footer', $without);
    }

    public function test_values_fill_once_escaped_and_a_missing_value_stays_as_written(): void
    {
        $html = (new EmailRenderer())->render(self::DESIGN, $this->brand(), [], [], [], [
            'recipient_name' => '<b>Ada</b> {{action_url}}',
            'action_url' => 'https://portify.co/case?a=1&b=2',
        ]);

        $this->assertStringContainsString('Hi &lt;b&gt;Ada&lt;/b&gt; {{action_url}},', $html, 'escaped, and never filled twice');
        $this->assertStringContainsString('href="https://portify.co/case?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('{{action_text}}', $html, 'no value: left as written for a preview');
    }
}
