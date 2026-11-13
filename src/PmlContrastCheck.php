<?php

namespace Shirahcan\Pml;


/**
 * Can a visitor read what Porter wrote? (A9, 2026-11-07.) Porter replicated a screenshot with white
 * text on the light `slate` gradient: the layout was right and nobody could read a word of it. This
 * walks a parsed design the way the renderer paints it (`primitiveStyle.ts`): the nearest background
 * an element sits on, the text colour it inherits, in the site's real palette, and names the first
 * pair below WCAG AA.
 *
 * Deliberately narrow, so it never refuses a page that reads fine:
 * - Only text whose colours someone CHOSE (a text colour, or a background, on it or an ancestor) is
 *   checked. Default text on the default page is the theme's own pairing, already readable.
 * - A photo background is skipped: nobody can know the photo's colours. Its overlay is the guard.
 * - It is Porter's check, run on Porter's output before it lands (one repair pass with the named
 *   pair), never a new save gate on pages people already have.
 */
final class PmlContrastCheck
{
    /** WCAG AA for body text. */
    private const BODY = 4.5;

    /** WCAG AA for large text (headings). */
    private const LARGE = 3.0;

    /** The fixed semantic colours (Tailwind emerald-600 and red-600 in the renderer). */
    private const FIXED = ['success' => '#059669', 'danger' => '#dc2626'];

    /** Every gradient preset's two stops (frontend GRADIENTS); text must read on both. */
    private const GRADIENTS = [
        'dawn' => ['#fff1eb', '#ffe4ec'],
        'dusk' => ['#ede7ff', '#dbeafe'],
        'ocean' => ['#e0f7fa', '#d6e8ff'],
        'forest' => ['#e6f4ea', '#d7f0e0'],
        'slate' => ['#f1f5f9', '#e2e8f0'],
    ];

    private const HEX = '/^#[0-9a-fA-F]{6}$/';

    /**
     * The unreadable text Porter brought in: what is faint in `$nodes` and was not already faint in
     * `$before` (a colour the person chose earlier is theirs, not a fault in Porter's work). In words
     * Porter can act on, or null.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  list<array<string, mixed>>  $before  the design as it was before this ask
     * @param  array<string, mixed>|null  $theme  the site's stored theme
     */
    public function newFault(array $nodes, array $before, ?array $theme): ?string
    {
        $old = $this->faults($before, $theme);

        foreach ($this->faults($nodes, $theme) as $fault) {
            if (! in_array($fault, $old, true)) {
                return $fault;
            }
        }

        return null;
    }

    /**
     * Every unreadable text in the design.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>|null  $theme
     * @return list<string>
     */
    public function faults(array $nodes, ?array $theme): array
    {
        $palette = Theme::palette($theme);
        $page = ['backgrounds' => [$palette['bg']], 'label' => 'the page background', 'text' => $palette['text'], 'textLabel' => 'the default text colour', 'chosen' => false, 'photo' => false];
        $out = [];

        foreach ($nodes as $node) {
            if (is_array($node)) {
                $this->walk($node, $page, $palette, $out);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Faint text fixed WITHOUT re-writing the page: on each element whose words are too faint and whose
     * own colour made them so, that colour is dropped (it inherits the colour its background sets), or,
     * when that is faint too, replaced by white or the site's text, whichever reads. Nothing else moves.
     *
     * ⚠ WHY (2026-11-08): one faint grey "[Years] exp." line sent a whole mirrored page back to the model
     * to be re-typed, and on the test box the re-type came back without its last three sections.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @param  array<string, mixed>|null  $theme
     * @return list<array<string, mixed>>
     */
    public function lighten(array $nodes, ?array $theme): array
    {
        $palette = Theme::palette($theme);
        $page = ['backgrounds' => [$palette['bg']], 'label' => 'the page background', 'text' => $palette['text'], 'textLabel' => 'the default text colour', 'chosen' => false, 'photo' => false];

        return array_map(fn ($node) => is_array($node) ? $this->mend($node, $page, $palette) : $node, $nodes);
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}  $at
     * @param  array<string, string>  $palette
     * @return array<string, mixed>
     */
    private function mend(array $node, array $at, array $palette, bool $inWords = false): array
    {
        $style = is_array($node['style'] ?? null) ? $node['style'] : [];
        $primitive = (string) ($node['primitive'] ?? '');
        $floor = $primitive === 'heading' ? self::LARGE : self::BODY;
        $here = $this->paint($at, $style, $palette);

        // ⚠ THE COLOUR IS OFTEN INHERITED, NOT ITS OWN. A mirror's wrapper box carried the source's dark
        // text into a dark band (remote.com: #14191e on #500087, 1.4:1) and only text with its OWN colour
        // was mended, so dark-on-dark and light-on-light reached the page (owner 2026-11-08). Any words
        // that cannot be read get a readable colour of their own: first by dropping their own colour,
        // then white, the site's text colour, or black, whichever reads.
        if (! ($inWords && PmlUids::isRun($node)) && $this->words($node, $primitive) !== null && $this->faint($here, $floor)) {
            [$style, $here] = $this->readable($at, $style, $palette, $floor);
            $node['style'] = $style;
        }

        // Rich text is a tag now (PML v3 L4), so it takes a readable colour like any element. The words
        // of a paragraph are judged once, on the paragraph; its runs inherit what it is given.
        if (isset($node['children']) && is_array($node['children'])) {
            $holds = ElementCatalog::holdsInline($primitive);
            $node['children'] = array_map(fn ($child) => is_array($child) ? $this->mend($child, $here, $palette, $holds) : $child, $node['children']);
        }

        return $node;
    }

    /**
     * Words painted here that a reader cannot read: on a colour someone chose (never the page's own
     * default, never a photo whose colours are unknowable), below the floor on any background.
     *
     * @param  array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}  $paint
     */
    private function faint(array $paint, float $floor): bool
    {
        return $paint['chosen'] && ! $paint['photo']
            && array_filter($paint['backgrounds'], fn ($b) => Theme::contrastRatio($paint['text'], $b) < $floor) !== [];
    }

    /**
     * The element's style with a text colour that reads: first without its own colour (so it inherits
     * the band's), then white, the site's text colour, or black, whichever reads first.
     *
     * @param  array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}  $at
     * @param  array<string, mixed>  $style
     * @param  array<string, string>  $palette
     * @return array{0: array<string, mixed>, 1: array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}}
     */
    private function readable(array $at, array $style, array $palette, float $floor): array
    {
        $here = $this->paint($at, $style, $palette);

        if (isset($style['text_color_custom']) || isset($style['text_color'])) {
            unset($style['text_color_custom'], $style['text_color']);
            $here = $this->paint($at, $style, $palette);
        }

        if ($this->faint($here, $floor)) {
            foreach (['#ffffff', $palette['text'], '#000000'] as $candidate) {
                $trial = $this->paint($at, [...$style, 'text_color_custom' => $candidate], $palette);

                if (! $this->faint($trial, $floor)) {
                    $style['text_color_custom'] = $candidate;
                    $here = $trial;

                    break;
                }
            }
        }

        return [$style, $here];
    }

    /**
     * @param  array<string, mixed>  $node
     * @param  array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}  $at
     * @param  array<string, string>  $palette
     * @param  list<string>  $out
     */
    private function walk(array $node, array $at, array $palette, array &$out, bool $inWords = false): void
    {
        $at = $this->paint($at, is_array($node['style'] ?? null) ? $node['style'] : [], $palette);
        $primitive = (string) ($node['primitive'] ?? '');
        $words = $inWords && PmlUids::isRun($node) ? null : $this->words($node, $primitive);

        if ($words !== null && $at['chosen'] && ! $at['photo']) {
            $floor = $primitive === 'heading' ? self::LARGE : self::BODY;

            foreach ($at['backgrounds'] as $background) {
                $ratio = Theme::contrastRatio($at['text'], $background);

                if ($ratio < $floor) {
                    $out[] = sprintf(
                        'the %s "%s" is %s (%s) on %s (%s): %.1f:1, too faint to read (it needs %.1f:1).',
                        $primitive === 'list_item' ? 'list item' : $primitive,
                        $words,
                        $at['textLabel'],
                        $at['text'],
                        $at['label'],
                        $background,
                        floor($ratio * 10) / 10,
                        $floor,
                    );

                    break;
                }
            }
        }

        foreach ((array) ($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->walk($child, $at, $palette, $out, ElementCatalog::holdsInline($primitive));
            }
        }
    }

    /**
     * What this element's own style does to the background it paints and the text colour its
     * words (and its children's) inherit, mirroring `primitiveStyle.ts`.
     *
     * @param  array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}  $at
     * @param  array<string, mixed>  $style
     * @param  array<string, string>  $palette
     * @return array{backgrounds: list<string>, label: string, text: string, textLabel: string, chosen: bool, photo: bool}
     */
    private function paint(array $at, array $style, array $palette): array
    {
        $background = is_string($style['background'] ?? null) ? $style['background'] : 'none';

        if ($background === 'surface') {
            $at = [...$at, 'backgrounds' => [$palette['surface']], 'label' => 'the surface background', 'chosen' => true, 'photo' => false];
        } elseif ($background === 'accent') {
            // An accent section also sets its text to the accent's readable colour.
            $at = [...$at, 'backgrounds' => [$palette['accent']], 'label' => 'the accent background', 'text' => $palette['accentText'], 'textLabel' => 'the on-accent text colour', 'chosen' => true, 'photo' => false];
        } elseif ($background === 'custom' && $this->hex($style['background_color'] ?? null)) {
            // The box sets its own words' colour (primitiveStyle.ts, 2026-11-08): white on a colour dark
            // enough for 4.5:1, the site's text otherwise. A text colour it names wins below.
            $paint = strtolower($style['background_color']);
            $light = Theme::contrastRatio('#ffffff', $paint) >= self::BODY;
            $at = [...$at, 'backgrounds' => [$paint], 'label' => 'the custom background', 'chosen' => true, 'photo' => false,
                'text' => $light ? '#ffffff' : $palette['text'], 'textLabel' => $light ? 'white' : 'the default text colour'];
        } elseif ($background === 'gradient' && isset(self::GRADIENTS[$style['background_gradient'] ?? ''])) {
            $name = (string) $style['background_gradient'];
            // Every gradient is a light wash, and sets the site's text on it (primitiveStyle.ts).
            $at = [...$at, 'backgrounds' => self::GRADIENTS[$name], 'label' => "the {$name} gradient", 'chosen' => true, 'photo' => false,
                'text' => $palette['text'], 'textLabel' => 'the default text colour'];
        } elseif ($background === 'image' && is_string($style['background_image_url'] ?? null) && $style['background_image_url'] !== '') {
            $overlay = is_string($style['background_overlay'] ?? null) ? $style['background_overlay'] : 'none';
            // A dark or strong scrim turns the text white over a photo; any other photo is unknowable.
            $at = in_array($overlay, ['dark', 'strong'], true)
                ? [...$at, 'backgrounds' => ['#000000'], 'label' => 'the darkened photo', 'text' => '#ffffff', 'textLabel' => 'white', 'chosen' => true, 'photo' => false]
                : [...$at, 'photo' => true];
        }

        $custom = $style['text_color_custom'] ?? null;
        $token = is_string($style['text_color'] ?? null) ? $style['text_color'] : null;

        if ($this->hex($custom)) {
            return [...$at, 'text' => strtolower($custom), 'textLabel' => 'a custom text colour', 'chosen' => true];
        }

        $tokens = ['default' => $palette['text'], 'muted' => $palette['muted'], 'accent' => $palette['accent'], 'on-accent' => $palette['accentText'], ...self::FIXED];

        if ($token !== null && isset($tokens[$token])) {
            return [...$at, 'text' => $tokens[$token], 'textLabel' => "the {$token} text colour", 'chosen' => true];
        }

        return $at;
    }

    /** The words a reader would see, or null for an element that draws no text of its own. */
    private function words(array $node, string $primitive): ?string
    {
        $props = is_array($node['props'] ?? null) ? $node['props'] : [];
        $value = match ($primitive) {
            'heading' => $props['text'] ?? null,
            'text' => $props['value'] ?? null,
            // A mirror's paragraphs are rich text; a paragraph's words are its runs, read together.
            'rich_text' => is_string($props['markdown'] ?? null) ? self::plainMarkdown($props['markdown']) : null,
            'p' => self::runText((array) ($node['children'] ?? [])),
            'list_item' => $props['text'] ?? ($props['title'] ?? null),
            // A button drawn without a fill (link, outline, ghost) is its words in the text colour.
            'button' => in_array($props['variant'] ?? null, ['link', 'outline', 'ghost'], true) ? ($props['label'] ?? null) : null,
            default => null,
        };

        if (! is_string($value) || trim($value) === '') {
            // An expression ({props.title}) is still text a reader sees; name it by what it reads.
            return is_array($value) && is_string($value['$expr'] ?? null) ? '{'.$value['$expr'].'}' : null;
        }

        $plain = trim(strip_tags($value));

        return mb_strlen($plain) > 40 ? mb_substr($plain, 0, 40).'...' : $plain;
    }

    /** Markdown as the words a reader sees: link labels kept, formatting marks dropped. */
    private static function plainMarkdown(string $markdown): string
    {
        return trim((string) preg_replace(['/\[([^\]]+)\]\([^)]*\)/', '/[*_`#>]+/', '/\s+/'], ['$1', '', ' '], $markdown));
    }

    /** A paragraph's literal words, its inline elements' included. */
    private static function runText(array $children): string
    {
        $words = '';

        foreach ($children as $child) {
            if (is_array($child)) {
                $words .= is_string($child['props']['value'] ?? null) ? $child['props']['value'] : (string) ($child['props']['text'] ?? '');
                $words .= self::runText((array) ($child['children'] ?? []));
            }
        }

        return $words;
    }

    private function hex(mixed $value): bool
    {
        return is_string($value) && preg_match(self::HEX, $value) === 1;
    }
}
