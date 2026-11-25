<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\PrimitiveSchema;
use Shirahcan\Pml\StandardLibrary;

/**
 * What a Build writing a BLOG POST (or a blog template) is taught beside the host's PML language (shared PML Studio
 * P5-1b): the post's pieces, the sections a post may hold, and what it may not. Derived from {@see PostProfile::PIECES}
 * and {@see StandardLibrary}, never a copied list, so the words cannot drift from what the post profile's check
 * accepts (PostSpecTest fails on a piece or a section that is missing).
 */
final class PostSpec
{
    /** The post field each piece shows, in the words a person uses. Every field in PostProfile::PIECES is here. */
    public const FIELD_WORDS = [
        'title' => 'the title',
        'dek' => 'the standfirst (the line under the title)',
        'answer' => 'the short answer',
        'author_name' => 'the author',
        'tags' => 'the tags',
        'cover' => 'the cover picture',
        'facts' => 'the sources and figures',
        'category_id' => 'the category',
        'cta_kind' => 'the call to action',
        'cta_ref' => 'the call to action',
        'story_id' => 'the client story',
    ];

    public static function describe(): string
    {
        $pieces = [];
        foreach (PostProfile::PIECES as $piece => $spec) {
            $props = PrimitiveSchema::allowedProps($piece) ?? [];
            $words = array_values(array_unique(array_map(fn (string $f) => self::FIELD_WORDS[$f] ?? $f, $spec['fields'])));
            $pieces[] = '  <'.self::tag($piece).'/>'
                .($props === [] ? '' : ' settings: '.implode(', ', array_map(self::tag(...), $props)))
                .' - '.($words === [] ? 'a value the platform keeps or a record the post points at' : 'shows '.implode(' and ', $words));
        }

        $sections = array_map(fn (string $name) => '<'.self::tag($name).'>', array_keys((new StandardLibrary)->signatures()));

        return implode("\n", [
            'THIS IS A BLOG POST (or a blog template), not a website page.',
            '',
            'POST PIECES: each shows one of the post\'s own details, so its words live in the post\'s fields, never as',
            'design text. Place a piece where the detail should appear; never type the title, the standfirst, the short',
            'answer or the author as a heading or a paragraph. A piece takes no words of its own:',
            ...$pieces,
            '',
            'SECTIONS a post may hold, at any depth: '.implode(' ', $sections).'.',
            'A website\'s own saved sections are NOT available in a post (the post is refused): build that look from',
            'primitives instead.',
            '',
            'A note about the design is a <comment for="uid">...</comment> element pinned to the element it is about, never',
            'words in the post.',
        ]);
    }

    private static function tag(string $name): string
    {
        return str_replace('_', '-', $name);
    }
}
