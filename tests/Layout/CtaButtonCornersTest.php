<?php

namespace Shirahcan\Pml\Tests\Layout;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A call to action's button corners (owner 2026-11-23: blog buttons "obey the theme but they should also be able to be
 * modified in the studio"). Optional: absent, or `theme`, follows Website > Theme > Corners exactly as before.
 */
class CtaButtonCornersTest extends TestCase
{
    public function test_a_call_to_action_may_choose_its_buttons_corners(): void
    {
        $schema = new PrimitiveSchema(new BlockSchema());

        foreach (['theme', 'pill', 'rounded', 'square'] as $corners) {
            $schema->validateNode(['primitive' => 'post_cta', 'props' => ['stage' => 'decision', 'variant' => 'inline', 'button_corners' => $corners]]);
        }

        // Absent: a design saved before this setting validates exactly as before.
        $schema->validateNode(['primitive' => 'post_cta', 'props' => ['stage' => 'decision', 'variant' => 'inline']]);

        $this->assertContains('button_corners', PrimitiveSchema::allowedProps('post_cta'));
    }

    public function test_a_corner_outside_the_list_is_refused(): void
    {
        $this->expectException(InvalidPmlException::class);

        (new PrimitiveSchema(new BlockSchema()))->validateNode(['primitive' => 'post_cta', 'props' => ['stage' => 'decision', 'button_corners' => 'blob']]);
    }
}
