<?php

namespace Shirahcan\Pml\Email;

/**
 * What an email needs around its design for real mail clients (pml-email A3b), the safeguards today's compiler
 * carries, re-proved for the PML renderer:
 *  - DARK MODE: clients that honour `prefers-color-scheme` recolour the card AND every coloured box with the text
 *    (CLAUDE.md email rule E: a box whose background does not flip with its text is light-on-light, shipped twice).
 *    Each box carries `pml-email-box` on its table and cell, so this block reaches both.
 *  - A PHONE: the two columns stack below 620px.
 *  - OUTLOOK (Windows): a fixed 600px ghost table, because it ignores max-width.
 *  - THE PREHEADER: the line an inbox shows after the subject, hidden in the email itself.
 */
final class EmailHead
{
    /** The dark card, the one colour every dark-mode contrast is measured against. */
    public const DARK_CARD = '#111827';

    /** A light edge and link tone that read on the dark card (both well above 3:1, and 4.5:1 for text). */
    public const DARK_EDGE = '#CBD5E1';

    public const DARK_LINK = '#A5B4FC';

    /**
     * The main button in dark mode. Its fill is the brand accent with on-accent words, which stays readable, but a
     * dark accent (Portify's #1E3A5F is 1.5:1 against the dark card) leaves the BUTTON itself nearly invisible:
     * WCAG 1.4.11 wants 3:1 for a control's boundary. So under 3:1 it gains a light edge; a brand whose accent
     * already reads on dark keeps it as it is. A secondary button (white fill, accent words) would otherwise turn
     * white-on-white in dark mode, so it becomes a dark button with light-accent words and edge.
     */
    private static function darkButtons(EmailStyle $style): string
    {
        $accent = $style->color('accent');
        $edge = \Shirahcan\Pml\Theme::contrastRatio($accent, self::DARK_CARD) < 3.0
            ? '.pml-email-button{border-color:'.self::DARK_EDGE.'!important}'
            : '';

        return '.pml-email-button,.pml-email-button a{color:'.$style->color('on_accent').'!important}'
            .$edge
            .'.pml-email-button-secondary{background-color:'.self::DARK_CARD.'!important;border-color:'.self::DARK_LINK.'!important}'
            .'.pml-email-button-secondary,.pml-email-button-secondary a{color:'.self::DARK_LINK.'!important}';
    }

    public static function head(string $title, EmailStyle $style): string
    {
        return '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            .'<title>'.EmailBlocks::h($title).'</title>'
            .'<style>'
            .'@media only screen and (max-width:620px){.pml-email-col{display:block!important;width:100%!important;padding:0!important}.pml-email-body{padding:24px 18px 8px!important}}'
            .'@media (prefers-color-scheme:dark){'
            .'body,.pml-email-page{background-color:#0F172A!important}'
            .'.pml-email-card{background-color:'.self::DARK_CARD.'!important}'
            .'.pml-email-card p,.pml-email-card li,.pml-email-card td,.pml-email-card th,.pml-email-card h1,.pml-email-card h2,.pml-email-card h3,.pml-email-card blockquote{color:#E5E7EB!important}'
            .'.pml-email-box{background-color:#1F2937!important}'
            .'.pml-email-card a{color:'.self::DARK_LINK.'!important}'
            .self::darkButtons($style)
            .'}'
            .'</style></head>';
    }

    /** The preheader: shown by the inbox after the subject, invisible in the email. Empty when none was set. */
    public static function preheader(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : '<div style="display:none;max-height:0;overflow:hidden;mso-hide:all;font-size:1px;line-height:1px;color:transparent;opacity:0">'
            .EmailBlocks::h($text).str_repeat('&#8199;&#65279;&#847; ', 30).'</div>';
    }

    /** Outlook's ghost table around the card (it ignores max-width); every other client skips the comments. */
    public static function outlookOpen(int $width): string
    {
        return '<!--[if mso]><table role="presentation" width="'.$width.'" align="center" cellpadding="0" cellspacing="0" border="0"><tr><td><![endif]-->';
    }

    public static function outlookClose(): string
    {
        return '<!--[if mso]></td></tr></table><![endif]-->';
    }
}
