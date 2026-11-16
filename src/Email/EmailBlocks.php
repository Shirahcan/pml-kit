<?php

namespace Shirahcan\Pml\Email;

/**
 * Each email element as the HTML mail clients render (pml-email A3a): tables for layout, inline styles, nothing that
 * depends on a stylesheet. Words are escaped here; `{{variables}}` stay as written and are filled (escaped) by the
 * renderer at send time. The design was checked by EmailProfile before it got here.
 */
final class EmailBlocks
{
    private const TONE_COLOR = ['info' => 'info', 'tip' => 'tip', 'success' => 'success', 'warning' => 'warning', 'danger' => 'danger'];

    public function __construct(private readonly EmailStyle $style) {}

    /** @param  list<array<string, mixed>>  $nodes */
    public function blocks(array $nodes): string
    {
        return implode('', array_map(fn (array $n) => $this->block($n), $nodes));
    }

    /** @param  array<string, mixed>  $node */
    public function block(array $node): string
    {
        $props = (array) ($node['props'] ?? []);
        $style = (array) ($node['style'] ?? []);
        $kids = (array) ($node['children'] ?? []);
        $css = $this->style->css($style);
        $font = 'font-family:'.$this->style->font();

        if (($node['primitive'] ?? '') === 'text' && ($name = EmailValues::preparedPlaceholder((string) ($props['value'] ?? ''))) !== null) {
            return '{{'.$name.'}}'; // prepared HTML, filled unescaped after drawing, standing alone as a block
        }

        return match ((string) ($node['primitive'] ?? '')) {
            'box', 'header', 'footer' => $this->cell($this->blocks($kids), $css),
            'grid' => $this->grid($kids, $css),
            'heading' => $this->heading($props, $style, $font),
            'p', 'text' => '<p style="margin:0 0 14px;'.$font.';font-size:'.$this->style->bodyPx($style['body_size'] ?? null).'px;line-height:1.6;color:'.$this->style->color('text').';'.$css.'">'.$this->inline($node).'</p>',
            'rich_text' => $this->richText($props, $font),
            'small' => '<p style="margin:0 0 10px;'.$font.';font-size:13px;line-height:1.5;color:'.$this->style->color('muted').'">'.$this->inlines($kids).'</p>',
            'list' => $this->listBlock($props, $kids, $font),
            'blockquote' => '<blockquote style="margin:0 0 14px;padding:4px 0 4px 14px;border-left:3px solid '.$this->style->color('border').';'.$font.';color:'.$this->style->color('muted').'">'.$this->blocks($kids).'</blockquote>',
            'table' => $this->table($kids, $font),
            'divider' => '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="padding:12px 0"><div style="border-top:1px solid '.$this->style->color('border').';height:1px;line-height:1px;font-size:1px">&nbsp;</div></td></tr></table>',
            'spacer' => '<div style="height:'.(['xs' => 8, 'sm' => 16, 'md' => 24, 'lg' => 40][(string) ($props['size'] ?? 'md')] ?? 24).'px;line-height:1px;font-size:1px">&nbsp;</div>',
            'image' => $this->image($props),
            'button' => $this->button($props, $style, $font),
            'callout' => $this->callout($props, $kids, $font),
            'pre' => '<pre style="margin:0 0 14px;padding:12px 14px;background-color:'.$this->style->color('surface').';border-radius:8px;font-family:Menlo,Consolas,monospace;font-size:14px;white-space:pre-wrap;color:'.$this->style->color('text').'">'.self::h((string) ($props['text'] ?? '')).'</pre>',
            default => $this->inline($node),
        };
    }

    /** A node's words and inline marks. */
    public function inline(array $node): string
    {
        $props = (array) ($node['props'] ?? []);
        $kids = (array) ($node['children'] ?? []);

        return match ((string) ($node['primitive'] ?? '')) {
            // A line break in the words (a filled value written on several lines) stays a line break in the email.
            'text' => isset($props['value']) ? str_replace("\n", '<br>', self::h(str_replace("\r\n", "\n", (string) $props['value']))) : $this->inlines($kids),
            'p', 'span' => $this->inlines($kids),
            'strong' => '<strong>'.$this->inlines($kids).'</strong>',
            'emphasis' => '<em>'.$this->inlines($kids).'</em>',
            'small' => '<small style="font-size:13px;color:'.$this->style->color('muted').'">'.$this->inlines($kids).'</small>',
            'mark' => '<span style="background-color:'.$this->style->color('surface').';padding:1px 6px;border-radius:4px">'.$this->inlines($kids).'</span>',
            'code' => '<code style="font-family:Menlo,Consolas,monospace;font-size:14px">'.self::h((string) ($props['text'] ?? '')).'</code>',
            'link' => '<a href="'.self::h((string) ($props['href'] ?? '')).'" style="color:'.$this->style->color('accent').';text-decoration:underline">'.$this->inlines($kids).'</a>',
            'br' => '<br>',
            default => '',
        };
    }

    /** @param  list<array<string, mixed>>  $nodes */
    public function inlines(array $nodes): string
    {
        return implode('', array_map(fn (array $n) => $this->inline($n), $nodes));
    }

    private function cell(string $inner, string $css): string
    {
        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="'.$css.'">'.$inner.'</td></tr></table>';
    }

    private function grid(array $kids, string $css): string
    {
        $cols = array_slice($kids, 0, EmailProfile::MAX_COLUMNS);
        $width = (int) floor(100 / max(1, count($cols)));
        $cells = implode('', array_map(fn (array $k) => '<td class="pml-email-col" width="'.$width.'%" valign="top" style="width:'.$width.'%;padding:0 8px">'.$this->block($k).'</td>', $cols));

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="'.$css.'"><tr>'.$cells.'</tr></table>';
    }

    private function heading(array $props, array $style, string $font): string
    {
        $level = min(EmailProfile::MAX_HEADING_LEVEL, max(1, (int) ($props['level'] ?? 2)));
        $px = $this->style->headingPx($level, $style['heading_size'] ?? null);

        return '<h'.$level.' style="margin:0 0 12px;'.$font.';font-size:'.$px.'px;line-height:1.3;font-weight:700;color:'.$this->style->color('text').';'.$this->style->css($style).'">'.self::h((string) ($props['text'] ?? '')).'</h'.$level.'>';
    }

    private function image(array $props): string
    {
        // `logo` (0.13.0) is a brand mark: email clients size by width, so it gets a wordmark-friendly width.
        $width = ['xs' => 120, 'sm' => 240, 'md' => 400, 'lg' => 568, 'logo' => 160][(string) ($props['size'] ?? 'lg')] ?? 568;
        $img = '<img src="'.self::h((string) ($props['src'] ?? '')).'" alt="'.self::h((string) ($props['alt'] ?? '')).'" width="'.$width.'" style="display:block;width:100%;max-width:'.$width.'px;height:auto;border:0;outline:none">';

        return '<div style="margin:0 0 14px">'.$img.'</div>';
    }

    /** A "bulletproof" button: a table cell carries the colour, so it shows in clients that drop link padding. */
    private function button(array $props, array $style, string $font): string
    {
        $primary = ($props['variant'] ?? 'primary') === 'primary';
        $bg = $primary ? $this->style->color('accent') : $this->style->color('card');
        $fg = $primary ? $this->style->color('on_accent') : $this->style->color('accent');
        $align = in_array($style['align'] ?? null, ['left', 'center', 'right'], true) ? $style['align'] : 'left';
        $a = '<a href="'.self::h((string) ($props['href'] ?? '')).'" style="display:inline-block;padding:12px 24px;'.$font.';font-size:16px;font-weight:600;color:'.$fg.';text-decoration:none;border-radius:8px">'.self::h((string) ($props['label'] ?? '')).'</a>';

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px"><tr><td align="'.$align.'">'
            .'<table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr><td class="pml-email-button" bgcolor="'.$bg.'" style="border-radius:8px;background-color:'.$bg.';border:1px solid '.$this->style->color('accent').'">'.$a.'</td></tr></table>'
            .'</td></tr></table>';
    }

    /** A note card (no tone) or an alert (tone); `pml-email-box` lets dark mode recolour it with the text (A3b). */
    private function callout(array $props, array $kids, string $font): string
    {
        $tone = self::TONE_COLOR[(string) ($props['tone'] ?? '')] ?? null;
        $edge = $tone !== null ? $this->style->color($tone) : $this->style->color('border');
        $title = trim((string) ($props['title'] ?? ''));
        $inner = ($title !== '' ? '<p style="margin:0 0 6px;'.$font.';font-weight:700;color:'.$this->style->color('text').'">'.self::h($title).'</p>' : '').$this->blocks($kids);

        return '<table role="presentation" class="pml-email-box" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px"><tr>'
            .'<td class="pml-email-box" style="padding:14px 16px;background-color:'.$this->style->color('surface').';border-left:4px solid '.$edge.';border-radius:8px">'.$inner.'</td></tr></table>';
    }

    private function listBlock(array $props, array $kids, string $font): string
    {
        $tag = ($props['type'] ?? 'bullet') === 'numbered' ? 'ol' : 'ul';
        $items = implode('', array_map(function (array $k) use ($font) {
            $p = (array) ($k['props'] ?? []);
            $title = trim((string) ($p['title'] ?? ''));

            return '<li style="margin:0 0 6px;'.$font.';font-size:16px;line-height:1.6;color:'.$this->style->color('text').'">'
                .($title !== '' ? '<strong>'.self::h($title).'</strong> ' : '').self::h((string) ($p['text'] ?? '')).$this->inlines((array) ($k['children'] ?? [])).'</li>';
        }, $kids));

        return '<'.$tag.' style="margin:0 0 14px;padding-left:22px">'.$items.'</'.$tag.'>';
    }

    private function table(array $rows, string $font): string
    {
        $html = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ((array) ($row['children'] ?? []) as $cell) {
                $tag = ! empty($cell['props']['header']) ? 'th' : 'td';
                $cells .= '<'.$tag.' style="padding:8px 10px;border-bottom:1px solid '.$this->style->color('border').';'.$font.';font-size:15px;text-align:left;color:'.$this->style->color('text').'">'.$this->blocksOrInline((array) ($cell['children'] ?? [])).'</'.$tag.'>';
            }
            $html .= '<tr>'.$cells.'</tr>';
        }

        return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin:0 0 16px;border-collapse:collapse">'.$html.'</table>';
    }

    private function blocksOrInline(array $kids): string
    {
        if (count($kids) === 1 && ($kids[0]['primitive'] ?? '') === 'p') {
            return $this->inlines((array) ($kids[0]['children'] ?? [])); // a cell's one paragraph, without paragraph spacing
        }

        $inline = array_filter($kids, fn ($k) => in_array($k['primitive'] ?? '', ['text', 'strong', 'emphasis', 'link', 'span', 'mark', 'code', 'br', 'small'], true));

        return count($inline) === count($kids) ? $this->inlines($kids) : $this->blocks($kids);
    }

    /** A rich text field's document (paragraphs, headings, lists, code, marked words) as email HTML. */
    private function richText(array $props, string $font): string
    {
        $doc = is_array($props['doc'] ?? null) ? $props['doc'] : (new \Shirahcan\Pml\MarkdownToDoc())->convert((string) ($props['markdown'] ?? ''));

        return (new EmailRichText($this->style, $font))->doc($doc);
    }

    /** Escape words (and attribute values) for HTML. `{{variables}}` survive (braces are not escaped) and are filled later. */
    public static function h(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
