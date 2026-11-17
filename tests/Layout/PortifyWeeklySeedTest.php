<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Email\EmailProfile;
use Shirahcan\Pml\Layout\LayoutChecks;
use Shirahcan\Pml\Layout\LayoutDefinition;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * The "Portify Weekly" newsletter layout (post layouts H1), from the owner's newsletter reference: the big change with
 * the one main button, at a glance, news and changes, the draw tracker, new from Portify, a client win, ask us, a
 * personal note and the decision banner. The standard header and footer are pml-email's partials, added at render.
 */
class PortifyWeeklySeedTest extends TestCase
{
    private function layout(): LayoutDefinition
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__).'/fixtures/layouts/email.portify-weekly.json'), true);

        return LayoutDefinition::fromArray($data, new PrimitiveSchema(new BlockSchema()));
    }

    public function test_the_seed_is_a_valid_email_layout_whose_always_on_blocks_are_required(): void
    {
        $layout = $this->layout();

        $this->assertSame(['portify-weekly', 'email'], [$layout->key, $layout->kind]);

        foreach (['hero', 'news', 'new-from-portify', 'note'] as $always) {
            $this->assertTrue($layout->part($always)['required'], "{$always} is in every issue");
        }
        foreach (['at-a-glance', 'draws', 'client-win', 'ask', 'decision'] as $switchable) {
            $this->assertFalse($layout->part($switchable)['required'], "{$switchable} can be switched off per issue");
        }
    }

    public function test_a_fresh_issue_follows_its_layout_and_is_a_valid_email_with_one_main_button(): void
    {
        $layout = $this->layout();
        $starter = $layout->starter();

        $fresh = LayoutChecks::check($layout, $starter);
        $this->assertSame([], array_values(array_filter($fresh['findings'], static fn ($f) => $f['severity'] === 'block' && $f['rule'] !== 'layout.unfilled')), 'the structure of a fresh issue passes');
        $this->assertFalse($fresh['mayPublish'], 'a fresh issue is example words, so it cannot go out until they are written (0.27.0)');
        $this->assertSame([], EmailProfile::violations($starter), 'every block uses only what mail clients show');
        $this->assertTrue(EmailProfile::hasMainButton([$starter[0]['children'][5]]), 'the hero carries the one main button');

        $buttons = substr_count(json_encode($starter), '"primitive":"button"');
        $this->assertSame(1, $buttons, 'one main button; everything else is a text link');
    }

    public function test_the_seed_never_writes_a_figure_or_a_client_win(): void
    {
        $raw = (string) file_get_contents(dirname(__DIR__).'/fixtures/layouts/email.portify-weekly.json');

        $this->assertStringNotContainsString(LayoutChecks::MARKER, $raw);
        $this->assertDoesNotMatchRegularExpression('/\$\s?\d|\d{2,},\d{3}/', $raw);
        $this->assertSame([], $this->layout()->part('client-win')['skeleton']);
    }
}
