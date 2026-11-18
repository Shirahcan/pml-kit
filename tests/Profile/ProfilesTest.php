<?php

namespace Shirahcan\Pml\Tests\Profile;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Card\CardFrame;
use Shirahcan\Pml\Email\EmailProfile;
use Shirahcan\Pml\Profile\Profiles;

/**
 * A purpose is a profile (shared PML Studio P1a-1): the email and card profiles say EXACTLY what EmailProfile and
 * CardFrame say (parity), every profile shares one shape, and an unknown purpose fails loudly.
 */
final class ProfilesTest extends TestCase
{
    private const MIXED = [
        ['primitive' => 'heading', 'props' => ['text' => 'Hello', 'level' => 2]],
        ['primitive' => 'carousel', 'children' => []],
        ['component' => 'PricingCards', 'props' => []],
        ['comment' => 'Purpose: a note for the author'],
        ['primitive' => 'box', 'children' => [['primitive' => 'form', 'children' => []], ['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi']]]]]],
        ['primitive' => 'button', 'props' => ['label' => 'Go', 'href' => 'https://example.com'], 'style' => ['shadow' => 'lg']],
    ];

    public function test_the_email_profile_is_exactly_the_email_rules(): void
    {
        $profile = Profiles::for('email');

        $this->assertSame('email', $profile->key());
        $this->assertSame(EmailProfile::ELEMENTS, $profile->elements());
        $this->assertSame(EmailProfile::violations(self::MIXED), $profile->violations(self::MIXED));
        $this->assertNotSame([], $profile->violations(self::MIXED), 'the corpus does hold things an email refuses');
        $this->assertSame([], $profile->pieces());
    }

    public function test_the_card_profile_is_exactly_the_card_rules_and_its_sentences_did_not_change(): void
    {
        $profile = Profiles::for('card');
        $found = $profile->violations(self::MIXED);

        $this->assertSame('card', $profile->key());
        $this->assertSame(CardFrame::ELEMENTS, $profile->elements());
        $this->assertSame(CardFrame::violations(self::MIXED), array_column($found, 'text'), 'violations() is the findings\' sentences');
        $this->assertSame(['2', '5.1', '6'], array_column($found, 'at'));
        $this->assertSame('<carousel> (at 2) cannot be on a card. A card holds only: '.implode(', ', CardFrame::ELEMENTS).'.', $found[0]['text']);
    }

    public function test_every_profile_shares_one_shape(): void
    {
        foreach (Profiles::keys() as $key) {
            foreach (Profiles::for($key)->violations(self::MIXED) as $finding) {
                $this->assertSame(['code', 'at', 'text'], array_keys($finding), "{$key} findings carry code, at and text");
            }
        }
    }

    public function test_an_unknown_purpose_fails_loudly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('There is no PML profile called "newsletterx"');

        Profiles::for('newsletterx');
    }
}
