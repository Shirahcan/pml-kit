<?php

namespace Shirahcan\Pml\Email;

/**
 * A PML email design as the HTML an inbox receives (pml-email A3a; owner 2026-11-16: "PML gets its own email renderer").
 * The same for every product: the product passes its brand, its standard header and footer partials (A4) and the
 * values to fill; the renderer owns the structure every mail client needs (a 600px table card on a page colour,
 * inline styles, bulletproof buttons).
 *
 * `{{variables}}` are filled after the design is drawn, each value escaped, in ONE pass (a value that itself contains
 * `{{x}}` is not filled again). A variable with no value stays as written, which is what a preview shows.
 *
 * The design must already have passed EmailProfile: the renderer draws, it does not judge.
 */
final class EmailRenderer
{
    public const WIDTH = 600;

    /**
     * @param  list<array<string, mixed>>  $nodes  the email's own design
     * @param  array{chrome?: string, preheader?: string, subject?: string}  $config  `chrome: none` leaves the header and footer out
     * @param  list<array<string, mixed>>  $header  the product's standard header partial
     * @param  list<array<string, mixed>>  $footer  the product's standard footer partial
     * @param  array<string, scalar|null>  $values
     */
    public function render(array $nodes, EmailBrand $brand, array $config = [], array $header = [], array $footer = [], array $values = []): string
    {
        $style = new EmailStyle($brand);
        $blocks = new EmailBlocks($style);
        $chrome = ($config['chrome'] ?? 'standard') !== 'none';

        $body = '<tr><td class="pml-email-body" style="padding:32px 32px 16px">'.$blocks->blocks($nodes).'</td></tr>';
        $top = $chrome && $header !== [] ? '<tr><td class="pml-email-header" style="padding:24px 32px 0">'.$blocks->blocks($header).'</td></tr>' : '';
        $bottom = $chrome && $footer !== [] ? '<tr><td class="pml-email-footer" style="padding:16px 32px 28px;border-top:1px solid '.$style->color('border').'">'.$blocks->blocks($footer).'</td></tr>' : '';

        $html = '<!DOCTYPE html><html lang="en">'.EmailHead::head((string) ($config['subject'] ?? $brand->productName()), $style)
            .'<body style="margin:0;padding:0;background-color:'.$style->color('page').'">'
            .EmailHead::preheader((string) ($config['preheader'] ?? ''))
            .'<table role="presentation" class="pml-email-page" width="100%" cellpadding="0" cellspacing="0" border="0" style="background-color:'.$style->color('page').'"><tr><td align="center" style="padding:24px 12px">'
            .EmailHead::outlookOpen(self::WIDTH)
            .'<table role="presentation" class="pml-email-card" width="'.self::WIDTH.'" cellpadding="0" cellspacing="0" border="0" style="width:100%;max-width:'.self::WIDTH.'px;background-color:'.$style->color('card').';border-radius:16px">'
            .$top.$body.$bottom
            .'</table>'.EmailHead::outlookClose().'</td></tr></table></body></html>';

        return self::fill($html, $values);
    }

    /**
     * The plain-text part of the same email (A3b): every word in order, buttons and links with their addresses.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $header
     * @param  list<array<string, mixed>>  $footer
     * @param  array<string, scalar|null>  $values
     */
    public function text(array $nodes, array $config = [], array $header = [], array $footer = [], array $values = []): string
    {
        return (new EmailPlainText())->text($nodes, $config, $header, $footer, $values);
    }

    /**
     * Fill `{{name}}` with its value, escaped, in one pass; a name with no value stays as written.
     *
     * @param  array<string, scalar|null>  $values
     */
    public static function fill(string $html, array $values): string
    {
        $map = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $map['{{'.$name.'}}'] = EmailBlocks::h((string) $value);
            }
        }

        return $map === [] ? $html : strtr($html, $map);
    }
}
