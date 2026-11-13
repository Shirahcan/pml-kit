<?php

namespace Shirahcan\Pml;

/**
 * What a public site's theme may contain (PML v2 S2a-02): ONE authority for both site owners'
 * update endpoints (a consultant's site settings, the admin Website theme).
 *
 * Every value is a closed choice except the brand colour, which must be a #rrggbb hex; its readable
 * text colour is DERIVED on render (frontend `readableOn`), never stored, so a site cannot ship an
 * unreadable button. The lists mirror `frontend/src/lib/sites/theme.ts` (THEME_PRESETS, FONT_PAIRS,
 * RADIUS) and `SiteThemeTest` fails if they drift.
 *
 * S2a-04b: the brand colour is ALSO drawn as text on the page background (prices, icons, links), so
 * one too faint there is refused here, not only in the editor: any other writer (an API call, a
 * future Porter action) gets the same answer.
 */
class Theme
{
    public const PRESETS = ['slate', 'forest', 'maple', 'ink', 'sand'];

    /** Each preset's page background (frontend THEME_PRESETS[].bg); the parity test keeps them equal. */
    public const PRESET_BACKGROUNDS = [
        'slate' => '#ffffff',
        'forest' => '#ffffff',
        'maple' => '#fffdfb',
        'ink' => '#0f172a',
        'sand' => '#fdfcf9',
    ];

    /**
     * Each preset's full palette (frontend THEME_PRESETS), so a server-side check can read the colours
     * a page will actually be drawn in (PmlContrastCheck). The parity test keeps them equal.
     *
     * @var array<string, array{bg: string, text: string, accent: string, accentText: string, muted: string, surface: string}>
     */
    public const PRESET_PALETTES = [
        'slate' => ['bg' => '#ffffff', 'text' => '#0f172a', 'accent' => '#0f4c81', 'accentText' => '#ffffff', 'muted' => '#475569', 'surface' => '#f8fafc'],
        'forest' => ['bg' => '#ffffff', 'text' => '#14261d', 'accent' => '#1c5c3c', 'accentText' => '#ffffff', 'muted' => '#436153', 'surface' => '#f3f8f5'],
        'maple' => ['bg' => '#fffdfb', 'text' => '#2b1512', 'accent' => '#a03328', 'accentText' => '#ffffff', 'muted' => '#7a4a44', 'surface' => '#fdf4f2'],
        'ink' => ['bg' => '#0f172a', 'text' => '#e2e8f0', 'accent' => '#7dd3fc', 'accentText' => '#082f49', 'muted' => '#94a3b8', 'surface' => '#1e293b'],
        'sand' => ['bg' => '#fdfcf9', 'text' => '#292524', 'accent' => '#8a6d3b', 'accentText' => '#ffffff', 'muted' => '#6b5f4f', 'surface' => '#f6f2ea'],
    ];

    /** WCAG 3:1, the floor for large text and icons (frontend MIN_ACCENT_CONTRAST). */
    public const MIN_ACCENT_CONTRAST = 3.0;

    public const RADII = ['sharp', 'soft', 'round'];

    public const FONT_PAIRS = ['modern', 'editorial', 'friendly', 'classic', 'geometric'];

    /**
     * The two faces of each pair and whether each is a serif (frontend FONT_PAIRS `heading` / `body`,
     * `--font-site-{slug}`; the parity test keeps them equal). Porter reads these (2026-11-09): a screenshot
     * whose headline is a sans came out in the site's serif headline, because nothing told Porter which of
     * the site's two fonts is the serif one.
     *
     * @var array<string, array{heading: array{family: string, slug: string, serif: bool}, body: array{family: string, slug: string, serif: bool}}>
     */
    public const FONT_FACES = [
        'modern' => ['heading' => ['family' => 'Inter', 'slug' => 'inter', 'serif' => false], 'body' => ['family' => 'Inter', 'slug' => 'inter', 'serif' => false]],
        'editorial' => ['heading' => ['family' => 'Fraunces', 'slug' => 'fraunces', 'serif' => true], 'body' => ['family' => 'Inter', 'slug' => 'inter', 'serif' => false]],
        'friendly' => ['heading' => ['family' => 'Plus Jakarta Sans', 'slug' => 'jakarta', 'serif' => false], 'body' => ['family' => 'Plus Jakarta Sans', 'slug' => 'jakarta', 'serif' => false]],
        'classic' => ['heading' => ['family' => 'Lora', 'slug' => 'lora', 'serif' => true], 'body' => ['family' => 'Source Sans 3', 'slug' => 'source-sans', 'serif' => false]],
        'geometric' => ['heading' => ['family' => 'Manrope', 'slug' => 'manrope', 'serif' => false], 'body' => ['family' => 'Manrope', 'slug' => 'manrope', 'serif' => false]],
    ];

    public const HERO_STYLES = ['centered', 'split', 'minimal'];

    /** How large the site's text runs (S2-08); frontend TYPE_SCALES. */
    public const TYPE_SCALES = ['compact', 'comfortable', 'spacious'];

    public const HEX = '/^#[0-9a-fA-F]{6}$/';

    /**
     * The colours a site is drawn in, as the frontend `themeToCssVars` resolves them: the preset's
     * palette, with the site's own brand colour replacing the accent and its text colour derived.
     *
     * @param  array<string, mixed>|null  $theme
     * @return array{bg: string, text: string, accent: string, accentText: string, muted: string, surface: string}
     */
    public static function palette(?array $theme): array
    {
        $preset = is_string($theme['preset'] ?? null) && isset(self::PRESET_PALETTES[$theme['preset']]) ? $theme['preset'] : self::PRESETS[0];
        $palette = self::PRESET_PALETTES[$preset];
        $accent = $theme['accent'] ?? null;

        if (is_string($accent) && preg_match(self::HEX, $accent) === 1) {
            $palette['accent'] = $accent;
            $palette['accentText'] = self::readableOn($accent);
        }

        return $palette;
    }

    /**
     * The site's two fonts in words, for Porter (`fonts` in every authoring payload): which face headings
     * and text are drawn in, whether each is a serif, and how to match a picture or a source page. An
     * unknown or missing pair is the first one, exactly as the frontend draws it (`fontPairFor`).
     *
     * @param  array<string, mixed>|null  $theme
     * @return array{heading: string, body: string, matching: string}
     */
    public static function fontsFor(?array $theme): array
    {
        $key = is_string($theme['font_pair'] ?? null) && isset(self::FONT_FACES[$theme['font_pair']]) ? $theme['font_pair'] : self::FONT_PAIRS[0];
        ['heading' => $heading, 'body' => $body] = self::FONT_FACES[$key];
        $kind = static fn (array $face): string => $face['family'].($face['serif'] ? ', a serif' : ', a sans-serif');

        $matching = match (true) {
            $heading['serif'] && ! $body['serif'] => 'Headings are drawn in the serif. When a picture or a source page sets a heading in a sans-serif face, give that heading font=body so it is drawn in '.$body['family'].'; keep the heading font only for a heading the source sets in a serif.',
            $heading['family'] === $body['family'] => 'Headings and text share one face, so font= changes nothing on this site. If a picture or a source page uses a serif headline, say in your reply that this site has no serif face and the site\'s theme sets it.',
            default => 'Use font=heading or font=body to match the face a picture or a source page sets each heading in.',
        };

        return ['heading' => $kind($heading), 'body' => $kind($body), 'matching' => $matching];
    }

    /** White when white reaches 4.5:1 on the colour, otherwise near-black (frontend `readableOn`). */
    public static function readableOn(string $colour): string
    {
        return self::contrastRatio('#ffffff', $colour) >= 4.5 ? '#ffffff' : '#0f172a';
    }

    /** WCAG 2 contrast ratio between two #rrggbb colours (the frontend `contrastRatio`). */
    public static function contrastRatio(string $foreground, string $background): float
    {
        $a = self::luminance($foreground);
        $b = self::luminance($background);

        return (max($a, $b) + 0.05) / (min($a, $b) + 0.05);
    }

    private static function luminance(string $hex): float
    {
        $value = ltrim($hex, '#');
        $channels = array_map(static function (int $offset) use ($value): float {
            $c = hexdec(substr($value, $offset, 2)) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, [0, 2, 4]);

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
