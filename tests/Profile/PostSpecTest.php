<?php

namespace Shirahcan\Pml\Tests\Profile;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardSpec;
use Shirahcan\Pml\Email\EmailSpec;
use Shirahcan\Pml\Profile\PostProfile;
use Shirahcan\Pml\Profile\PostSpec;
use Shirahcan\Pml\Profile\Profiles;
use Shirahcan\Pml\StandardLibrary;

/**
 * Shared PML Studio P5-1b: each profile says what a Build writing it is taught, from the same data its check reads.
 * A post's teaching names every piece and every standard section, and offers no website section.
 */
class PostSpecTest extends TestCase
{
    public function test_a_posts_spec_names_every_piece_and_every_standard_section(): void
    {
        $spec = Profiles::for('post')->spec();

        $this->assertSame(PostSpec::describe(), $spec);
        foreach (array_keys(PostProfile::PIECES) as $piece) {
            $this->assertStringContainsString('<'.str_replace('_', '-', $piece).'/>', $spec, $piece);
        }
        foreach (array_keys((new StandardLibrary)->signatures()) as $section) {
            $this->assertStringContainsString('<'.str_replace('_', '-', $section).'>', $spec, $section);
        }
        $this->assertStringNotContainsString('site-band', $spec);
        $this->assertStringContainsString('<comment for=', $spec);
    }

    public function test_every_field_a_piece_shows_has_words(): void
    {
        foreach (PostProfile::PIECES as $piece => $definition) {
            foreach ($definition['fields'] as $field) {
                $this->assertArrayHasKey($field, PostSpec::FIELD_WORDS, "{$piece} shows {$field}, which has no words");
            }
        }
    }

    public function test_an_email_and_a_card_are_taught_their_own_language_and_a_page_the_hosts(): void
    {
        $this->assertSame(EmailSpec::describe(), Profiles::for('email')->spec());
        $this->assertSame(CardSpec::describe(), Profiles::for('card')->spec());
        $this->assertNull(Profiles::for('page')->spec());
    }
}
