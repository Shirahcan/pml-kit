<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailBlocks;
use Shirahcan\Pml\Email\EmailPlainText;
use Shirahcan\Pml\Email\EmailStyle;
use Shirahcan\Pml\Email\NullBrand;
use Shirahcan\Pml\Email\PmlToSemantic;
use Shirahcan\Pml\Email\SemanticToPml;

/**
 * The email side of a list item's rich children (0.25.0): an item with bold, italic or a link carries a paragraph's
 * inline nodes, and after them at most one nested list (two levels). The email draws them as a paragraph would, the
 * semantic body keeps them for the round trip, the text part reads them once. An item of words only is unchanged,
 * byte for byte.
 */
final class ListItemChildrenTest extends TestCase
{
    private const LI = 'margin:0 0 6px;font-family:Arial, Helvetica, sans-serif;font-size:16px;line-height:1.6;color:#1F2937';

    private function html(array $list): string
    {
        return (new EmailBlocks(new EmailStyle(new NullBrand())))->block($list);
    }

    private function semantic(array $list): string
    {
        return (new PmlToSemantic())->convert([$list])['html'];
    }

    private function text(array $list): string
    {
        return (new EmailPlainText())->text([$list], ['chrome' => 'none']);
    }

    private static function item(array $props, array $children = []): array
    {
        return array_filter(['primitive' => 'list_item', 'props' => $props, 'children' => $children], fn ($v) => $v !== []);
    }

    private static function list(array $items, string $type = 'bullet'): array
    {
        return ['primitive' => 'list', 'props' => ['type' => $type], 'children' => $items];
    }

    private static function link(string $href, string $words): array
    {
        return ['primitive' => 'link', 'props' => ['href' => $href], 'children' => [['primitive' => 'text', 'props' => ['value' => $words]]]];
    }

    private static function words(string $value): array
    {
        return ['primitive' => 'text', 'props' => ['value' => $value]];
    }

    public function test_an_item_of_words_only_is_drawn_byte_for_byte_as_before(): void
    {
        // Recorded from 0.24.0 before the change.
        $list = self::list([self::item(['title' => 'Fees:', 'text' => 'paid online & by card']), self::item(['text' => 'Biometrics within 30 days'])]);

        $this->assertSame('<ul style="margin:0 0 14px;padding-left:22px"><li style="'.self::LI.'"><strong>Fees:</strong> paid online &amp; by card</li><li style="'.self::LI.'">Biometrics within 30 days</li></ul>', $this->html($list));
        $this->assertSame('<ul><li><p><strong>Fees:</strong> paid online &amp; by card</p></li><li><p>Biometrics within 30 days</p></li></ul>', $this->semantic($list));
        $this->assertSame("- Fees: paid online & by card\n- Biometrics within 30 days", $this->text($list));
    }

    public function test_a_linked_item_draws_its_link_exactly_as_a_paragraph_does(): void
    {
        $inline = [self::words('Pay at '), self::link('https://ircc.example/pay', 'IRCC'), self::words('.')];
        $blocks = new EmailBlocks(new EmailStyle(new NullBrand()));
        $paragraphLink = $blocks->inline(self::link('https://ircc.example/pay', 'IRCC'));

        $html = $this->html(self::list([self::item([], $inline)]));

        $this->assertStringContainsString('<li style="'.self::LI.'">Pay at '.$paragraphLink.'.</li>', $html);
        $this->assertSame('<ul><li><p>Pay at <a href="https://ircc.example/pay">IRCC</a>.</p></li></ul>', $this->semantic(self::list([self::item([], $inline)])));
        $this->assertSame('- Pay at IRCC (https://ircc.example/pay).', $this->text(self::list([self::item([], $inline)])));
    }

    public function test_a_bold_label_then_marked_words_keeps_one_space_and_never_reads_twice(): void
    {
        $item = self::item(['title' => 'Fees:', 'text' => 'stale words never drawn'], [self::words(' paid at '), self::link('https://ircc.example/pay', 'IRCC')]);

        $this->assertStringContainsString('<strong>Fees:</strong> paid at <a href="https://ircc.example/pay"', $this->html(self::list([$item])));
        $this->assertSame('<ul><li><p><strong>Fees:</strong> paid at <a href="https://ircc.example/pay">IRCC</a></p></li></ul>', $this->semantic(self::list([$item])));
        $this->assertSame('- Fees: paid at IRCC (https://ircc.example/pay)', $this->text(self::list([$item])));
    }

    public function test_a_nested_list_sits_inside_its_item_after_the_words(): void
    {
        $list = self::list([
            self::item([], [self::words('Documents'), self::list([self::item(['text' => 'Passport']), self::item(['text' => 'Photo'])], 'numbered')]),
            self::item(['title' => 'Then:'], [self::list([self::item(['text' => 'Book biometrics'])])]),
        ]);

        $html = $this->html($list);
        $this->assertStringContainsString('<li style="'.self::LI.'">Documents<ol style="margin:6px 0 0;padding-left:22px"><li style="'.self::LI.'">Passport</li><li style="'.self::LI.'">Photo</li></ol></li>', $html);
        $this->assertStringContainsString('<strong>Then:</strong> <ul style="margin:6px 0 0;padding-left:22px">', $html);

        $this->assertSame(
            '<ul><li><p>Documents</p><ol><li><p>Passport</p></li><li><p>Photo</p></li></ol></li><li><p><strong>Then:</strong> </p><ul><li><p>Book biometrics</p></li></ul></li></ul>',
            $this->semantic($list),
        );
        $this->assertSame("- Documents\n  - Passport\n  - Photo\n- Then:\n  - Book biometrics", $this->text($list));
    }

    public function test_a_linked_item_round_trips_semantic_to_pml_and_back_with_its_link(): void
    {
        $body = '<ul><li><p>Pay at <a href="https://ircc.example/pay">IRCC</a> online.</p></li><li><p>Keep the receipt.</p></li></ul>';

        $design = (new SemanticToPml())->convert($body);
        $this->assertSame([], $design['unconverted'] ?? [], 'a link inside a list item converts for email (0.25.0)');

        $back = (new PmlToSemantic())->convert($design['nodes'])['html'];
        $this->assertSame($body, $back);
        $this->assertSame($back, (new PmlToSemantic())->convert((new SemanticToPml())->convert($back)['nodes'])['html'], 'stable on a second trip');
    }
}
