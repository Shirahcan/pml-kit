<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailBrand;
use Shirahcan\Pml\Email\EmailHead;
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
        ['primitive' => 'button', 'props' => ['label' => '{{action_label}}', 'href' => '{{action_url}}', 'variant' => 'primary'], 'style' => ['align' => 'center']],
        ['primitive' => 'image', 'props' => ['src' => 'https://portify.co/x.png', 'alt' => 'A picture', 'size' => 'sm']],
        ['primitive' => 'divider'],
        ['primitive' => 'grid', 'props' => ['columns' => 2], 'children' => [['primitive' => 'box', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Left']]]]]], ['primitive' => 'box', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Right']]]]]]]],
        ['primitive' => 'rich_text', 'props' => ['markdown' => "A **bold** word and a [link](https://portify.co)."]],
    ];

    private const HEADER = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'PORTIFY HEADER']]]]];

    private const FOOTER = [['primitive' => 'small', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Sent by {{platform_name}}.']]]]];

    public function test_every_element_draws_as_email_html(): void
    {
        $html = (new EmailRenderer())->render(self::DESIGN, $this->brand(), ['subject' => 'Moved', 'missing' => 'keep'], self::HEADER, self::FOOTER);

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

    /** A brand with only its accent set (the rest falls back to the default palette). */
    private function brandWithAccent(string $accent): EmailBrand
    {
        return new class($accent) implements EmailBrand
        {
            public function __construct(private readonly string $accent) {}

            public function productName(): string
            {
                return 'Portify';
            }

            public function colors(): array
            {
                return ['accent' => $this->accent];
            }

            public function fontStack(): string
            {
                return 'Arial, sans-serif';
            }
        };
    }

    /** The CSS inside the dark-mode media query. */
    private function darkRules(string $html): string
    {
        $this->assertSame(1, preg_match('/@media \(prefers-color-scheme:dark\)\{(.*?)\}<\/style>/s', $html, $m), 'the email has a dark-mode block');

        return $m[1];
    }

    public function test_a_dark_accent_button_gains_a_light_edge_in_dark_mode_a_bright_one_keeps_its_own(): void
    {
        // Portify's real accent: 1.5:1 against the dark card, so the button's shape would all but vanish (WCAG 1.4.11 wants 3:1).
        $navy = '#1E3A5F';
        $this->assertLessThan(3.0, \Shirahcan\Pml\Theme::contrastRatio($navy, EmailHead::DARK_CARD));
        $dark = $this->darkRules((new EmailRenderer())->render(self::DESIGN, $this->brandWithAccent($navy), [], self::HEADER, self::FOOTER));
        $this->assertStringContainsString('.pml-email-button{border-color:'.EmailHead::DARK_EDGE.'!important}', $dark);

        // An accent that already reads on the dark card is left as the brand chose.
        $bright = '#6366F1';
        $this->assertGreaterThanOrEqual(3.0, \Shirahcan\Pml\Theme::contrastRatio($bright, EmailHead::DARK_CARD));
        $dark = $this->darkRules((new EmailRenderer())->render(self::DESIGN, $this->brandWithAccent($bright), [], self::HEADER, self::FOOTER));
        $this->assertStringNotContainsString('.pml-email-button{border-color:', $dark);

        // The edge and the light words are measured, not eyeballed.
        $this->assertGreaterThanOrEqual(3.0, \Shirahcan\Pml\Theme::contrastRatio(EmailHead::DARK_EDGE, EmailHead::DARK_CARD));
        $this->assertGreaterThanOrEqual(4.5, \Shirahcan\Pml\Theme::contrastRatio(EmailHead::DARK_LINK, EmailHead::DARK_CARD));
    }

    public function test_a_secondary_button_never_turns_white_on_white_in_dark_mode(): void
    {
        $design = [['primitive' => 'button', 'props' => ['label' => 'Read the guide', 'href' => 'https://example.com', 'variant' => 'secondary']]];
        $html = (new EmailRenderer())->render($design, $this->brandWithAccent('#1E3A5F'), [], self::HEADER, self::FOOTER);

        $this->assertStringContainsString('class="pml-email-button pml-email-button-secondary"', $html);
        $dark = $this->darkRules($html);
        // Declared AFTER the primary rule with the same specificity, so the secondary's light words win over on-accent white.
        $primaryAt = strpos($dark, '.pml-email-button,.pml-email-button a{color:');
        $secondaryAt = strpos($dark, '.pml-email-button-secondary,.pml-email-button-secondary a{color:'.EmailHead::DARK_LINK.'!important}');
        $this->assertNotFalse($secondaryAt);
        $this->assertGreaterThan($primaryAt, $secondaryAt);
        $this->assertStringContainsString('.pml-email-button-secondary{background-color:'.EmailHead::DARK_CARD.'!important', $dark);
    }

    public function test_the_plain_text_part_carries_every_word_and_every_address(): void
    {
        $text = (new EmailRenderer())->text(self::DESIGN, [], self::HEADER, self::FOOTER, ['recipient_name' => 'Ada', 'action_label' => 'View', 'action_url' => 'https://portify.co/c/1', 'platform_name' => 'Portify']);

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

    public function test_values_fill_once_escaped_and_a_missing_value_stays_as_written_in_a_preview(): void
    {
        $html = (new EmailRenderer())->render(self::DESIGN, $this->brand(), ['missing' => 'keep'], [], [], [
            'recipient_name' => '<b>Ada</b> {{action_url}} {{summary_html}}',
            'action_url' => 'https://portify.co/case?a=1&b=2',
        ]);

        $this->assertStringContainsString('Hi &lt;b&gt;Ada&lt;/b&gt; {{action_url}} {{summary_html}},', $html, 'escaped, and words a person typed are never filled');
        $this->assertStringContainsString('href="https://portify.co/case?a=1&amp;b=2"', $html);
        $this->assertStringContainsString('{{action_label}}', $html, 'no value: left as written for a preview');
    }

    public function test_sending_strips_a_missing_value_and_leaves_out_an_empty_button_and_an_emptied_detail_row(): void
    {
        $cell = fn (array $inline) => ['primitive' => 'cell', 'children' => [['primitive' => 'p', 'children' => $inline]]];
        $text = fn (string $v) => ['primitive' => 'text', 'props' => ['value' => $v]];
        $design = [
            ['primitive' => 'p', 'children' => [$text('Hi {{recipient_name}}{{nickname}}. Typed: {{typed}}')]],
            ['primitive' => 'button', 'props' => ['label' => '{{action_label}}', 'href' => '{{action_url}}']],
            ['primitive' => 'table', 'children' => [
                ['primitive' => 'row', 'children' => [$cell([$text('Date')]), $cell([$text('{{meeting_date}}')])]],
                ['primitive' => 'row', 'children' => [$cell([$text('Timezone')]), $cell([['primitive' => 'strong', 'children' => [$text('{{timezone}}')]]])]],
            ]],
        ];

        $html = (new EmailRenderer())->render($design, $this->brand(), [], [], [], ['recipient_name' => 'Ada', 'meeting_date' => 'May 3', 'typed' => 'my {{nickname}}']);

        $this->assertStringContainsString('Hi Ada. Typed: my {{nickname}}', $html, 'missing stripped, typed braces kept');
        $this->assertStringNotContainsString('class="pml-email-button"', $html, 'no action_url on this send: no button');
        $this->assertStringContainsString('May 3', $html);
        $this->assertStringNotContainsString('Timezone', $html, 'the row whose value came out empty is dropped');
        $this->assertStringNotContainsString("\u{E000}", $html);
    }

    public function test_an_address_that_came_out_unsafe_is_never_sent(): void
    {
        $design = [
            ['primitive' => 'button', 'props' => ['label' => 'Pay', 'href' => '{{pay_url}}']],
            ['primitive' => 'p', 'children' => [['primitive' => 'link', 'props' => ['href' => '{{site_url}}'], 'children' => [['primitive' => 'text', 'props' => ['value' => 'Our site']]]]]],
            ['primitive' => 'image', 'props' => ['src' => '{{logo_url}}', 'alt' => 'Logo']],
        ];

        $html = (new EmailRenderer())->render($design, $this->brand(), [], [], [], ['pay_url' => 'javascript:alert(1)', 'site_url' => 'javascript:alert(2)', 'logo_url' => 'data:image/png;base64,AAAA']);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:image', $html);
        $this->assertStringContainsString('Our site', $html, 'the link keeps its words');
        $this->assertStringNotContainsString('class="pml-email-button"', $html);
    }

    public function test_a_value_written_on_several_lines_keeps_its_line_breaks_and_stays_escaped(): void
    {
        $design = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => '{{summary}}']]]]];

        $html = (new EmailRenderer())->render($design, $this->brand(), [], [], [], ['summary' => "First line\nSecond <b>line</b>"]);

        $this->assertStringContainsString('First line<br>Second &lt;b&gt;line&lt;/b&gt;', $html);
    }

    public function test_a_prepared_lines_link_keeps_its_address_in_the_plain_text_part(): void
    {
        $text = (new EmailRenderer())->text([['primitive' => 'text', 'props' => ['value' => '{{preferences_line}}']]], [], [], [], [
            'preferences_line' => '<p><small><a href="https://portify.co/settings/notifications" style="color:#000">Manage email preferences</a></small></p>',
        ]);

        $this->assertSame('Manage email preferences (https://portify.co/settings/notifications)', $text);
    }

    public function test_a_prepared_html_value_is_filled_unescaped_as_its_own_block(): void
    {
        $design = [['primitive' => 'text', 'props' => ['value' => '{{summary_html}}']], ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'After']]]]];

        $html = (new EmailRenderer())->render($design, $this->brand(), [], [], [], ['summary_html' => '<table><tr><td>Prepared</td></tr></table>']);

        $this->assertStringContainsString('<table><tr><td>Prepared</td></tr></table>', $html);
        $this->assertDoesNotMatchRegularExpression('#<p[^>]*>\s*<table><tr><td>Prepared#', $html, 'never inside a paragraph');
    }
}
