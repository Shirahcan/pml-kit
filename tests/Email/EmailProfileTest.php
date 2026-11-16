<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailChecks;
use Shirahcan\Pml\Email\EmailProfile;
use Shirahcan\Pml\Email\EmailVariables;

/**
 * What an email may hold (pml-email A2): A1's elements pass; anything a mail client cannot honour is REFUSED with a
 * sentence a person reads (never silently dropped); variables and the main button are held against the email as it was.
 */
final class EmailProfileTest extends TestCase
{
    private const EMAIL = [
        ['primitive' => 'heading', 'props' => ['text' => 'Your meeting has moved', 'level' => 2]],
        ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi {{recipient_name}},']]]],
        ['primitive' => 'callout', 'children' => [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Reply with any questions.']]]]]],
        ['primitive' => 'grid', 'props' => ['columns' => 2], 'children' => [['primitive' => 'box'], ['primitive' => 'box']]],
        ['primitive' => 'image', 'props' => ['src' => 'https://portify.co/logo.png', 'alt' => 'Portify']],
        ['primitive' => 'button', 'props' => ['label' => '{{action_text}}', 'href' => '{{action_url}}', 'variant' => 'primary'], 'style' => ['align' => 'center']],
    ];

    private function vars(array $declared = ['recipient_name', 'action_url', 'action_text']): EmailVariables
    {
        return new class($declared) implements EmailVariables
        {
            public function __construct(private readonly array $declared) {}

            public function declared(): array
            {
                return $this->declared;
            }

            public function alwaysAvailable(): array
            {
                return ['platform_name', 'support_email'];
            }
        };
    }

    public function test_an_email_built_from_the_email_elements_passes(): void
    {
        $this->assertSame([], EmailProfile::violations(self::EMAIL));
        $this->assertSame([], EmailChecks::check(self::EMAIL, null, $this->vars()));
    }

    public function test_what_a_mail_client_cannot_honour_is_refused_with_a_reason(): void
    {
        $design = [
            ['primitive' => 'form'],
            ['primitive' => 'box', 'style' => ['motion' => 'fade'], 'if' => ['$expr' => 'x']],
            ['primitive' => 'grid', 'props' => ['columns' => 3]],
            ['primitive' => 'heading', 'props' => ['text' => 'Deep', 'level' => 4]],
            ['primitive' => 'image', 'props' => ['src' => '/relative.png']],
            ['primitive' => 'button', 'props' => ['label' => '', 'href' => 'javascript:x']],
        ];

        $codes = array_column(EmailProfile::violations($design), 'code');

        foreach (['element', 'style', 'logic', 'columns', 'heading', 'image', 'button'] as $code) {
            $this->assertContains($code, $codes, "expected a {$code} refusal");
        }
        $this->assertStringContainsString('form cannot go in an email', EmailProfile::violations($design)[0]['text']);
    }

    public function test_an_allowed_setting_with_a_value_mail_clients_drop_is_refused(): void
    {
        $design = [['primitive' => 'box', 'style' => ['background' => 'gradient', 'padding' => 'custom']], ['primitive' => 'box', 'style' => ['background' => 'surface', 'padding' => 'normal']]];

        $violations = EmailProfile::violations($design);

        $this->assertCount(2, $violations);
        $this->assertSame(['1', '1'], array_column($violations, 'at'), 'only the first box; surface and normal are fine');
    }

    public function test_variables_the_email_does_not_have_are_errors(): void
    {
        $design = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi {{nickname}} from {{platform_name}}']]]]];

        $findings = EmailChecks::check($design, null, $this->vars());

        $this->assertSame(['unknown_variable'], array_column($findings, 'code'));
        $this->assertStringContainsString('{{nickname}}', $findings[0]['text']);
    }

    public function test_a_dropped_variable_is_an_error_and_a_lost_main_button_is_said(): void
    {
        $after = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hello.']]]]];

        $findings = EmailChecks::check($after, self::EMAIL, $this->vars());

        $this->assertContains('dropped_variable', array_column($findings, 'code'));
        $this->assertContains('main_button', array_column($findings, 'code'));
        $this->assertSame('issue', array_values(array_filter($findings, fn ($f) => $f['code'] === 'main_button'))[0]['kind']);
    }

    public function test_a_required_line_cannot_be_lost_by_opting_out_of_the_standard_footer(): void
    {
        $footer = [['primitive' => 'small', 'children' => [['primitive' => 'link', 'props' => ['href' => '{{unsubscribe_url}}'], 'children' => [['primitive' => 'text', 'props' => ['value' => 'Unsubscribe']]]]]]];
        $body = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'News']]]]];
        $bodyWithLine = [...$body, ...$footer];

        $this->assertSame([], EmailChecks::requiredLines($body, [], $footer, ['unsubscribe_url']), 'the standard footer carries it');
        $this->assertSame(['required_line'], array_column(EmailChecks::requiredLines($body, ['chrome' => 'none'], $footer, ['unsubscribe_url']), 'code'), 'opting out drops the footer, so the body must carry it');
        $this->assertSame([], EmailChecks::requiredLines($bodyWithLine, ['chrome' => 'none'], $footer, ['unsubscribe_url']));
        $this->assertCount(1, EmailChecks::requiredLines($body, [], [], ['unsubscribe_url']), 'a footer without it does not count');
    }

    public function test_it_reads_variables_and_the_main_button(): void
    {
        $this->assertSame(['recipient_name', 'action_text', 'action_url'], EmailProfile::variables(self::EMAIL));
        $this->assertTrue(EmailProfile::hasMainButton(self::EMAIL));
    }
}
