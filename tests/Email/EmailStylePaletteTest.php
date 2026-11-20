<?php

namespace Shirahcan\Pml\Tests\Email;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Email\EmailBrand;
use Shirahcan\Pml\Email\EmailStyle;

/** An editor's canvas wears the colours the sent email is drawn with: the brand's over the defaults. */
class EmailStylePaletteTest extends TestCase
{
    public function test_the_palette_is_the_brands_colours_over_the_defaults(): void
    {
        $brand = new class implements EmailBrand {
            public function productName(): string
            {
                return 'Portify';
            }

            public function colors(): array
            {
                return ['accent' => '#0F766E', 'text' => 'not-a-colour'];
            }

            public function fontStack(): string
            {
                return 'Arial, sans-serif';
            }
        };

        $palette = (new EmailStyle($brand))->palette();

        $this->assertSame('#0F766E', $palette['accent']);
        $this->assertSame('#FFFFFF', $palette['on_accent'], 'a colour the brand left unset keeps its default');
        $this->assertSame('#1F2937', $palette['text'], 'a value that is not a colour is ignored');
        $this->assertSame((new EmailStyle($brand))->color('accent'), $palette['accent'], 'the palette and color() agree');
    }
}
