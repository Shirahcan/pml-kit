<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Theme;

/**
 * The shared theme values (shirahcan/pml 0.7.0, shared program E2g): framework-free, so every product and the brand
 * kit read the same palettes, fonts and contrast maths.
 *
 * ⚠ WHAT THIS GUARDS: the WCAG contrast ratio is right at its known end points; text colour on a fill is chosen for
 * readability; a theme with no settings still yields a full palette and fonts.
 */
class ThemeTest extends TestCase
{
    public function test_the_contrast_ratio_is_right_at_its_end_points(): void
    {
        $this->assertEqualsWithDelta(21.0, Theme::contrastRatio('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, Theme::contrastRatio('#4361ee', '#4361ee'), 0.01);
    }

    public function test_text_on_a_fill_is_chosen_for_readability(): void
    {
        $this->assertNotSame(Theme::readableOn('#000000'), Theme::readableOn('#ffffff'));
    }

    public function test_an_empty_theme_still_has_a_palette_and_fonts(): void
    {
        $this->assertNotSame([], Theme::palette(null));
        $this->assertNotSame([], Theme::fontsFor(null));
    }
}
