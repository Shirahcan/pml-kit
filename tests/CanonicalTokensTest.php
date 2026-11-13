<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * Style tokens have one stored form (shirahcan/pml 0.5.0, shared program E2h).
 *
 * ⚠ WHAT THIS GUARDS: a numeric token becomes the schema's string at every depth and in its responsive variants, so a
 * round trip through PML text is lossless; a pixel size (a real integer) and anything that is not a token stay as
 * they are.
 */
class CanonicalTokensTest extends TestCase
{
    public function test_a_numeric_token_becomes_the_schemas_string_at_every_depth(): void
    {
        $out = PrimitiveSchema::canonical([
            ['uid' => 'g', 'primitive' => 'grid', 'children' => [
                ['uid' => 'a', 'primitive' => 'box', 'style' => ['col_span' => 7, 'md:col_span' => 4, 'padding' => 'normal']],
                ['uid' => 'b', 'primitive' => 'box', 'style' => ['col_span' => '5']],
            ]],
        ]);

        $this->assertSame(['col_span' => '7', 'md:col_span' => '4', 'padding' => 'normal'], $out[0]['children'][0]['style']);
        $this->assertSame(['col_span' => '5'], $out[0]['children'][1]['style']);
    }

    public function test_pixel_sizes_and_non_tokens_are_left_alone(): void
    {
        $nodes = [['uid' => 'a', 'primitive' => 'box', 'style' => ['padding_top_px' => 24, 'col_span' => 99]]];

        $this->assertSame($nodes, PrimitiveSchema::canonical($nodes), 'a px size is an integer, and 99 is no col_span token');
    }
}
