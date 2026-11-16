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
    public static function head(string $title, EmailStyle $style): string
    {
        return '<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            .'<meta name="color-scheme" content="light dark"><meta name="supported-color-schemes" content="light dark">'
            .'<title>'.EmailBlocks::h($title).'</title>'
            .'<style>'
            .'@media only screen and (max-width:620px){.pml-email-col{display:block!important;width:100%!important;padding:0!important}.pml-email-body{padding:24px 18px 8px!important}}'
            .'@media (prefers-color-scheme:dark){'
            .'body,.pml-email-page{background-color:#0F172A!important}'
            .'.pml-email-card{background-color:#111827!important}'
            .'.pml-email-card p,.pml-email-card li,.pml-email-card td,.pml-email-card th,.pml-email-card h1,.pml-email-card h2,.pml-email-card h3,.pml-email-card blockquote{color:#E5E7EB!important}'
            .'.pml-email-box{background-color:#1F2937!important}'
            .'.pml-email-card a{color:#A5B4FC!important}'
            .'.pml-email-button,.pml-email-button a{color:'.$style->color('on_accent').'!important}'
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
