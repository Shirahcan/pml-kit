<?php

namespace Shirahcan\Pml\Profile;

use InvalidArgumentException;

/**
 * Every purpose a PML document can have, by key. ONE authority: a save gate, the Studio and Porter's Build all look a
 * profile up here, so two of them can never disagree about what an email or a card may hold. An unknown key fails
 * loudly: a document with no known purpose is never treated as "anything goes".
 */
final class Profiles
{
    /** @var array<string, class-string<Profile>> */
    private const KNOWN = [
        'email' => EmailDocumentProfile::class,
        'card' => CardDocumentProfile::class,
        'page' => PageProfile::class,
        'post' => PostProfile::class,
    ];

    /** The profile for a purpose. */
    public static function for(string $key): Profile
    {
        $class = self::KNOWN[$key] ?? null;

        if ($class === null) {
            throw new InvalidArgumentException("There is no PML profile called \"{$key}\". Known: ".implode(', ', self::keys()).'.');
        }

        return new $class();
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::KNOWN);
    }
}
