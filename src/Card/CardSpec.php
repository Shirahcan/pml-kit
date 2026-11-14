<?php

namespace Shirahcan\Pml\Card;

use Shirahcan\Pml\PrimitiveSchema;

/**
 * The language reference a card Build is taught (shared program E4c), the `{{spec}}` of ai-service's `shared.pml.card`.
 * Every product sends this same text, so a card is held to one vocabulary everywhere.
 *
 * ⚠ DERIVED, NEVER RESTATED. The elements are {@see CardFrame::ELEMENTS} and each one's props come from the element
 * catalogue; the style choices come from {@see PrimitiveSchema}. A key named in {@see STYLE_KEYS} that the schema
 * does not know is a failing test, not a silent gap, so what the model is taught never drifts from what the parser
 * accepts.
 */
final class CardSpec
{
    /** The style keys a card uses (a subset of the page vocabulary: no motion, no grids, no breakpoints). */
    public const STYLE_KEYS = [
        'background', 'background_gradient', 'background_overlay', 'text_color', 'heading_size', 'body_size',
        'font_weight', 'line_height', 'align', 'justify', 'padding', 'padding_x', 'min_height', 'width',
    ];

    public static function describe(): string
    {
        $elements = [];
        foreach (CardFrame::ELEMENTS as $element) {
            $props = PrimitiveSchema::allowedProps($element) ?? [];
            $elements[] = '  <'.$element.'>'.($props === [] ? '' : ' props: '.implode(', ', array_map(self::attribute(...), $props)));
        }

        $styles = [];
        foreach (self::STYLE_KEYS as $key) {
            $choices = PrimitiveSchema::styleChoices($key);
            if ($choices !== null && ! PrimitiveSchema::isColourStyle($key)) {
                $styles[] = '  '.self::attribute($key).'='.implode('|', $choices);
            }
        }

        return implode("\n", [
            'CARD LANGUAGE (PML, card subset). A card is written like a page: elements as tags, settings as attributes,',
            'style as kebab-cased token attributes in quotes (align="center"), never CSS, never a raw colour or pixel size.',
            'Words go in the element\'s own prop: <heading text="..." level="1"/>, <text value="..."/>.',
            'Pictures and the logo use the address given in the facts: <image src="..." alt="..."/>.',
            '<credentials/> is the pinned identity line: when the facts say a line is pinned, keep this element.',
            '',
            'ELEMENTS A CARD MAY HOLD (anything else is removed, and the person is told):',
            ...$elements,
            '',
            'STYLE TOKENS A CARD USES:',
            ...$styles,
            '',
            sprintf(
                'THE CARD: %d x %d, shrunk to about 500 px wide in a feed. Keep words %d px from every edge. At most %d blocks of words and %d words. No words below body-size "md".',
                CardFrame::WIDTH, CardFrame::HEIGHT, CardFrame::SAFE_MARGIN, CardChecks::MAX_BLOCKS, CardChecks::MAX_WORDS,
            ),
            'A top-level <box> with width="full", min-height="lg" and justify="center" fills the card and places its words.',
            'Words on a photo: the box takes background="image", background-image-url="<the picture from the facts>" and background-overlay="dark", and its words keep the default text colour (light on the overlay), never text-color="on-accent".',
        ]);
    }

    private static function attribute(string $key): string
    {
        return str_replace('_', '-', $key);
    }
}
