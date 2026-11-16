<?php

namespace Shirahcan\Pml\Email;

/**
 * PML's style tokens as the inline CSS mail clients read (pml-email A3a). One place maps a token to pixels and
 * colours, so the editor's canvas (which renders this output) and the sent email can never disagree.
 */
final class EmailStyle
{
    private const DEFAULT_COLORS = [
        'accent' => '#4361EE', 'on_accent' => '#FFFFFF', 'text' => '#1F2937', 'muted' => '#6B7280',
        'surface' => '#F3F4F6', 'border' => '#E5E7EB', 'page' => '#F2F2FF', 'card' => '#FFFFFF',
        'info' => '#2563EB', 'tip' => '#7C3AED', 'success' => '#15803D', 'warning' => '#B45309', 'danger' => '#B91C1C',
    ];

    private const PADDING = ['none' => 0, 'compact' => 8, 'normal' => 16, 'spacious' => 32];

    private const MARGIN_Y = ['none' => 0, 'sm' => 8, 'md' => 16, 'lg' => 32];

    private const HEADING_PX = ['sm' => 18, 'md' => 22, 'lg' => 26, 'xl' => 32];

    private const LEVEL_SIZE = [1 => 'xl', 2 => 'lg', 3 => 'md'];

    private const BODY_PX = ['sm' => 14, 'md' => 16, 'lg' => 18];

    private const WEIGHT = ['normal' => 400, 'medium' => 500, 'semibold' => 600, 'bold' => 700];

    private const LINE_HEIGHT = ['tight' => 1.25, 'normal' => 1.5, 'relaxed' => 1.65, 'loose' => 1.85];

    private const LETTER = ['tight' => '-0.01em', 'normal' => 'normal', 'wide' => '0.04em'];

    private const BORDER_PX = ['none' => 0, 'hairline' => 1, 'thin' => 1, 'thick' => 3];

    private const RADIUS_PX = ['none' => 0, 'sm' => 4, 'md' => 8, 'lg' => 12, 'xl' => 16, 'full' => 999];

    /** @var array<string, string> */
    private array $colors;

    public function __construct(private readonly EmailBrand $brand)
    {
        $this->colors = [...self::DEFAULT_COLORS, ...array_filter($brand->colors(), fn ($v) => is_string($v) && preg_match('/^#[0-9a-f]{3,8}$/i', $v) === 1)];
    }

    public function color(string $key): string
    {
        return $this->colors[$key] ?? self::DEFAULT_COLORS[$key] ?? '#1F2937';
    }

    public function font(): string
    {
        return $this->brand->fontStack() !== '' ? $this->brand->fontStack() : 'Arial, Helvetica, sans-serif';
    }

    public function bodyPx(?string $size): int
    {
        return self::BODY_PX[$size ?? 'md'] ?? 16;
    }

    public function headingPx(int $level, ?string $size): int
    {
        return self::HEADING_PX[$size ?? (self::LEVEL_SIZE[$level] ?? 'md')] ?? 22;
    }

    /**
     * The inline CSS for a node's style tokens (only the email profile's keys; anything else was refused before).
     *
     * @param  array<string, mixed>  $style
     */
    public function css(array $style): string
    {
        $rules = [];
        $pad = self::PADDING[(string) ($style['padding'] ?? '')] ?? null;
        $padX = self::PADDING[(string) ($style['padding_x'] ?? '')] ?? null;

        if ($pad !== null || $padX !== null) {
            $rules[] = 'padding:'.($pad ?? 0).'px '.($padX ?? $pad ?? 0).'px';
        }
        if (isset(self::MARGIN_Y[(string) ($style['margin_y'] ?? '')])) {
            $rules[] = 'margin:'.self::MARGIN_Y[(string) $style['margin_y']].'px 0';
        }
        if (in_array($style['background'] ?? null, ['surface', 'accent'], true)) {
            $rules[] = 'background-color:'.$this->color((string) $style['background']);
        }
        if (isset($style['text_color']) && $style['text_color'] !== 'default') {
            $rules[] = 'color:'.$this->color(match ((string) $style['text_color']) {
                'on-accent' => 'on_accent', 'muted' => 'muted', 'accent' => 'accent', 'success' => 'success', 'danger' => 'danger', default => 'text',
            });
        }
        if (isset(self::WEIGHT[(string) ($style['font_weight'] ?? '')])) {
            $rules[] = 'font-weight:'.self::WEIGHT[(string) $style['font_weight']];
        }
        if (isset(self::LINE_HEIGHT[(string) ($style['line_height'] ?? '')])) {
            $rules[] = 'line-height:'.self::LINE_HEIGHT[(string) $style['line_height']];
        }
        if (isset(self::LETTER[(string) ($style['letter_spacing'] ?? '')])) {
            $rules[] = 'letter-spacing:'.self::LETTER[(string) $style['letter_spacing']];
        }
        if (in_array($style['text_transform'] ?? null, ['uppercase', 'capitalize'], true)) {
            $rules[] = 'text-transform:'.$style['text_transform'];
        }
        if (in_array($style['align'] ?? null, ['left', 'center', 'right'], true)) {
            $rules[] = 'text-align:'.$style['align'];
        }
        $border = self::BORDER_PX[(string) ($style['border'] ?? '')] ?? 0;
        if ($border > 0) {
            $rules[] = 'border:'.$border.'px solid '.$this->color(match ((string) ($style['border_color'] ?? 'default')) {
                'accent' => 'accent', 'muted' => 'muted', default => 'border',
            });
        }
        if (($radius = self::RADIUS_PX[(string) ($style['radius'] ?? '')] ?? 0) > 0) {
            $rules[] = 'border-radius:'.$radius.'px';
        }

        return implode(';', $rules);
    }
}
