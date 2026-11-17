<?php

namespace Shirahcan\Pml\Email;

use Shirahcan\Pml\PrimitiveSchema;

/**
 * The language reference an email Build is taught (blog on growth-service N1h-4), the `{{spec}}` of ai-service's
 * `shared.email.design`. Every product sends this same text, so an email is held to one vocabulary everywhere (a
 * newsletter on growth-service, an email template in Portify's PML designer).
 *
 * ⚠ DERIVED, NEVER RESTATED. The elements are {@see EmailProfile::ELEMENTS} and each one's props come from the
 * element catalogue; the style choices come from {@see PrimitiveSchema} for {@see EmailProfile::STYLE_KEYS}. What the
 * model is taught therefore never drifts from what {@see EmailProfile::violations()} accepts.
 */
final class EmailSpec
{
    /** The standard chrome's roots: added to every email at send time, never written into a body. */
    private const CHROME = ['header', 'footer'];

    public static function describe(): string
    {
        $elements = [];
        foreach (EmailProfile::ELEMENTS as $element) {
            if (in_array($element, self::CHROME, true)) {
                continue;
            }
            $props = PrimitiveSchema::allowedProps($element) ?? [];
            $elements[] = '  <'.$element.'>'.($props === [] ? '' : ' props: '.implode(', ', array_map(self::attribute(...), $props)));
        }

        $styles = [];
        foreach (EmailProfile::STYLE_KEYS as $key) {
            $choices = PrimitiveSchema::styleChoices($key);
            if ($choices !== null && ! PrimitiveSchema::isColourStyle($key)) {
                $styles[] = '  '.self::attribute($key).'='.implode('|', $choices);
            }
        }

        return implode("\n", [
            'EMAIL LANGUAGE (PML, email subset). An email is written like a page: elements as tags, settings as attributes,',
            'style as kebab-cased token attributes in quotes (align="center"), never CSS, never a raw colour or pixel size.',
            'Words go in the element\'s own prop: <heading text="..." level="2"/>, <p> with its words inside, <button label="..." href="..."/>.',
            'KEEP every uid="..." and part="..." attribute exactly where it is: they tie the email to its template and to the',
            'notes pinned on it. A new element you add has no uid (one is given on save).',
            '',
            'ELEMENTS AN EMAIL MAY HOLD (mail clients cannot show anything else, and the email is refused):',
            ...$elements,
            '',
            'STYLE TOKENS AN EMAIL USES:',
            ...$styles,
            '',
            sprintf('At most %d columns side by side, and headings at level %d or below.', EmailProfile::MAX_COLUMNS, EmailProfile::MAX_HEADING_LEVEL),
            'No logic: never if, else, each or on attributes, and never an expression. Different readers get different words through {{variables}}.',
            'The standard header and footer are added when the email is sent: never write a <header> or a <footer>, an unsubscribe line or a mailing address.',
        ]);
    }

    private static function attribute(string $name): string
    {
        return str_replace('_', '-', $name);
    }
}
