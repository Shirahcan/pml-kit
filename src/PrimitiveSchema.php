<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Blocks\InvalidBlockException;
use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\InvalidPmlException;

/**
 * The trusted leaf vocabulary of Portify Markup Language (PML).
 *
 * PML has exactly one security surface: this list of primitives and the props each may
 * carry. Everything a consultant, the visual builder, or Porter authors compiles DOWN to
 * these leaves, and the renderer emits elements from them and never produces markup. There
 * is no raw-HTML primitive, and there never will be (PML_LANGUAGE_SPEC.md section 1).
 *
 * This class validates ONE node in isolation: the primitive is known, every prop is on that
 * primitive's allowlist, every token value is in its enum, every URL passes the SAME scheme
 * check the block system uses (reused, not reimplemented), and only containers carry
 * children. The whole-tree walk (recursion, container depth, deep rich-text docs, and the
 * compliance scan) belongs to PmlCompiler, not here.
 */
class PrimitiveSchema
{
    /*
     * The element list, what each may contain and where it may sit, the props each may carry and their
     * types (enums, urls, ids, ...) live in ONE definition: resources/pml/elements.json, read through
     * ElementCatalog (PML v3 L1, owner 2026-11-10). The frontend gets a generated copy of the same file.
     * This class keeps the checks; the vocabulary is data.
     */

    /**
     * The shared appearance vocabulary, mirroring frontend sectionStyle.ts. Tokens only,
     * plus the two bounded-free values (clamped px paddings, a #rrggbb colour) that already
     * exist in the block system. No freeform CSS anywhere.
     *
     * @var array<string, list<string>>
     */
    private const STYLE_ENUMS = [
        'align' => ['left', 'center', 'right'],
        // `xwide` (1280px, 2026-11-08): a modern desktop layout (vanhack.com's content is ~1,240px) sat
        // between `wide` (1152px, visibly narrower) and `full` (no limit, visibly wider).
        'width' => ['narrow', 'normal', 'wide', 'xwide', 'full'],
        'padding' => ['none', 'compact', 'normal', 'spacious', 'custom'],
        'background' => ['none', 'surface', 'accent', 'custom', 'gradient', 'image'],
        'heading_size' => ['sm', 'md', 'lg', 'xl'],
        'body_size' => ['sm', 'md', 'lg'],
        // The named gradient preset used when background=gradient. Closed set - the runtime derives
        // the actual CSS from the site palette, so there are no user-supplied colour stops.
        'background_gradient' => ['dawn', 'dusk', 'ocean', 'forest', 'slate'],
        // The scrim over a background image (background=image). This is a CONTRAST guarantee: text
        // stays legible over any photo, so it is a token, never a free opacity.
        'background_overlay' => ['none', 'light', 'dark', 'strong'],
        // A closed motion vocabulary; the trusted frontend runtime owns all timing and easing, so
        // there is deliberately NO user-supplied duration/delay (spec section 7).
        'motion' => ['none', 'reveal-up', 'reveal-in', 'stagger', 'parallax-soft', 'hover-lift', 'sticky', 'count-up'],
        // How many columns/rows a child spans inside a grid or a table row. A closed token set (not
        // a free integer), bounded by the grid's max of 4 columns; the renderer clamps a span that
        // exceeds the actual column count. Meaningless outside a grid/table, and simply ignored there.
        'col_span' => ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
        'row_span' => ['1', '2', '3'],

        // --- Placement (how a container distributes/aligns its children on the flex axes). Closed
        // token sets mapped to flexbox utilities by the renderer; meaningless on a leaf and ignored
        // there. justify = main-axis distribution, align_items = cross-axis alignment, self_align = a
        // single child overriding its parent's cross-axis alignment. These are what let a nav center
        // its links or space them out (the nav-layout fix).
        'justify' => ['start', 'center', 'end', 'between', 'around', 'evenly'],
        'align_items' => ['start', 'center', 'end', 'stretch', 'baseline'],
        'self_align' => ['start', 'center', 'end', 'stretch'],

        // --- Spacing. padding_x is horizontal padding (the existing `padding` stays vertical);
        // margin_y is outer vertical spacing between sections. Both are closed presets - the custom
        // per-side px escape valve lives in STYLE_PX_KEYS, still clamped 0-200.
        'padding_x' => ['none', 'compact', 'normal', 'spacious'],
        'margin_y' => ['none', 'sm', 'md', 'lg'],

        // --- Typography. text_color is a PALETTE token (derived from the site theme), never a free
        // hex, so brand + legibility hold and a disclaimer can't be recoloured into the background
        // (the text_color_custom hex escape valve is bounded like background_color). The rest are
        // closed weight/leading/tracking/transform sets. There is deliberately NO free font size -
        // arbitrary sizing is an s.44 hidden-text vector; heading_size/body_size stay the only axes.
        'text_color' => ['default', 'muted', 'accent', 'on-accent', 'success', 'danger'],
        'font_weight' => ['normal', 'medium', 'semibold', 'bold'],
        'line_height' => ['tight', 'normal', 'relaxed', 'loose'],
        'letter_spacing' => ['tight', 'normal', 'wide'],
        'text_transform' => ['none', 'uppercase', 'capitalize'],
        // Which of the SITE's two fonts (2026-11-08): a heading may take the body font, a line the
        // heading font. Never a free family: the site keeps its own two. A mirrored page whose source
        // set its section headings in its text font came out all in the display font, a third wider.
        'font' => ['heading', 'body'],

        // --- Border & effects. Border width + radius + shadow as closed presets; border_color is a
        // palette token (custom hex via STYLE_HEX_KEYS). A border colour only takes effect when
        // `border` is not none (the renderer's concern).
        'border' => ['none', 'hairline', 'thin', 'thick'],
        'border_color' => ['default', 'muted', 'accent'],
        'radius' => ['none', 'sm', 'md', 'lg', 'xl', 'full'],
        'shadow' => ['none', 'sm', 'md', 'lg'],

        // --- Sizing. A minimum height for hero/section bands, as a closed set (not a free px/vh, so
        // it can never be abused to push content off-screen). `width` above stays the max-width axis.
        'min_height' => ['none', 'sm', 'md', 'lg'],

        // --- Visibility (PML v2 S2-03). Leave an element out on ONE screen size (a long table on a
        // phone, a decorative image on mobile) instead of duplicating content per device.
        'hide' => ['none', 'mobile', 'tablet', 'desktop'],

        // --- Display (owner 2026-11-08: "a general appearance/display attribute ... what browsers support
        // + carousel"). How the element lays itself out. A box: block | flex (its `direction` row/column,
        // the default) | grid | carousel; a leaf: block | inline | inline-block (see DISPLAY_FOR_LEAF).
        // `none` is deliberately absent: `hide` leaves an element out per screen size, and an element
        // hidden everywhere is an s.44 hidden-text vector.
        'display' => ['block', 'flex', 'grid', 'carousel', 'inline', 'inline-block'],
        // A carousel's own settings (display=carousel). Closed sets: the runtime owns the timing.
        'carousel_per_view' => ['1', '2', '3', '4'],
        'carousel_motion' => ['none', 'step', 'scroll'],
        'carousel_speed' => ['slow', 'normal', 'fast'],
        'carousel_controls' => ['arrows', 'dots', 'both', 'none'],
        'carousel_loop' => ['on', 'off'],

        // --- Placement (owner 2026-11-08: "static, fixed, pinned/sticky"). Where the element sits as the
        // page scrolls. Fixed and sticky are anchored to an EDGE, never to free coordinates, so a bar can
        // be pinned but nothing can be parked over the page's words. `stacking` orders overlapping elements;
        // `overflow` lets a wide row scroll sideways instead of spilling.
        'placement' => ['static', 'sticky', 'fixed'],
        'placement_edge' => ['top', 'bottom'],
        'stacking' => ['base', 'raised', 'top'],
        'overflow' => ['visible', 'clip', 'scroll-x'],
    ];

    /** The display values a leaf (words, a picture, a button) may take; containers take the rest. */
    public const DISPLAY_FOR_LEAF = ['block', 'inline', 'inline-block'];

    /** The display values a container may take. */
    public const DISPLAY_FOR_CONTAINER = ['block', 'flex', 'grid', 'carousel'];

    /**
     * The layout tokens that may differ by screen size (PML v2 S2-01), written `md:padding=` (from
     * 768px) or `lg:padding=` (from 1024px) over the mobile-first base value. Colour, borders and
     * effects are deliberately NOT responsive: a design keeps one look and only reflows.
     */
    public const RESPONSIVE_STYLE_KEYS = [
        'padding', 'padding_x', 'margin_y', 'align', 'width', 'heading_size', 'body_size',
        'justify', 'align_items', 'min_height', 'col_span', 'carousel_per_view',
    ];

    /** The breakpoint prefixes a responsive token may carry. */
    public const BREAKPOINTS = ['md', 'lg'];

    private const STYLE_PX_KEYS = ['padding_top_px', 'padding_bottom_px', 'padding_left_px', 'padding_right_px'];

    /**
     * Keys whose value may be ONE size (both sides) or TWO, one per side (owner 2026-11-12): `padding="none spacious"`
     * is top none, bottom spacious; `padding-x="compact normal"` is left compact, right normal. Each side is one of the
     * key's sizes; `custom` (pixel sides) is never half of a pair. Mirrored by the builder's paddingPair.ts.
     */
    public const PAIRED_STYLE_KEYS = ['padding', 'padding_x'];

    private const STYLE_HEX_KEYS = ['background_color', 'text_color_custom', 'border_color_custom'];

    /** Style keys whose value is a URL and gets the SAME scheme check every other url gets. */
    private const STYLE_URL_KEYS = ['background_image_url'];

    private const PADDING_PX_MAX = 200;

    private const HEX_COLOR = '/^#[0-9a-fA-F]{6}$/';

    /**
     * A video id: a short run of URL-safe id characters only. Deliberately STRICT - no `/`, `?`,
     * `"`, `<`, or space - so a value can never break out of the `src="...{id}..."` the renderer
     * builds. Covers a YouTube id (11 chars) and a Vimeo id (numeric).
     */
    private const VIDEO_ID = '/^[A-Za-z0-9_-]{1,20}$/';

    /**
     * A form's stable key: a lowercase URL-safe slug (alnum with inner hyphens, 1-40 chars). It is
     * a route segment in the public submit endpoint (/forms/{key}/submit), so it is validated as
     * strictly as a video id - no slash, space, quote, uppercase, or leading/trailing hyphen that
     * could reshape the route.
     */
    private const FORM_KEY = '/^[a-z0-9](?:[a-z0-9-]{0,38}[a-z0-9])?$/';

    /** The id shapes an element definition names with `"type": "pattern"`. */
    private const PATTERNS = [
        'video_id' => self::VIDEO_ID,
        'form_key' => self::FORM_KEY,
    ];

    /** The events an element may answer (`on-click={...}`). Mirrors PmlParser::EVENTS. */
    public const EVENTS = ['click', 'change', 'submit', 'input'];

    /** `each={item in list}`: the item's name. */
    private const EACH_ITEM = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    /** A node's imperative-handle key: a short SAFE id a PSL `dom.el(key)` handle targets. Never a URL,
     * selector, or path - it names one keyed node in the virtual tree, nothing more. */
    private const NODE_KEY = '/^[a-z][a-z0-9_-]{0,63}$/i';

    /**
     * A linkable anchor (S1-02): becomes the element's `id`, so `#eligibility` and a table of contents
     * can jump to it. Lowercase slug only (a letter first), so it can never collide with a framework
     * id, break out of the attribute, or carry a selector. Page-uniqueness is the compiler's check.
     */
    public const ANCHOR = '/^[a-z][a-z0-9-]{0,63}$/';

    public function __construct(private readonly BlockSchema $blocks)
    {
    }

    /**
     * Is this node name one of the trusted leaf primitives (vs a std-library component)?
     * The parser uses this to tag a node `primitive` or `component`; it is a vocabulary
     * lookup, not validation (the schema still gates every prop and token).
     */
    public static function isPrimitive(string $name): bool
    {
        return ElementCatalog::has($name);
    }

    /**
     * The content props a primitive may carry (null for a name that is not a primitive: a section or
     * component validates its own). Read by a mirror's plan, so an edit can never set a prop the page
     * check would refuse (2026-11-08).
     *
     * @return list<string>|null
     */
    public static function allowedProps(string $primitive): ?array
    {
        return ElementCatalog::propNames($primitive);
    }

    /**
     * What a component's style setting may hold for this style (component props p02a): the key's tokens
     * (less `custom`, which needs a colour beside it), `['#rrggbb']` for a colour key, or null when the
     * key is not one a page can choose (a pixel padding, an image address, a breakpoint override).
     *
     * @return list<string>|null
     */
    public static function styleChoices(string $key): ?array
    {
        if (isset(self::STYLE_ENUMS[$key])) {
            return array_values(array_diff(self::STYLE_ENUMS[$key], ['custom']));
        }

        return in_array($key, self::STYLE_HEX_KEYS, true) ? ['#rrggbb'] : null;
    }

    /** Whether a style key holds a #rrggbb colour. */
    public static function isColourStyle(string $key): bool
    {
        return in_array($key, self::STYLE_HEX_KEYS, true);
    }

    /**
     * The style-token attribute names. The tag scanner routes these to a node's `style`
     * object (validated as a closed token vocabulary), and every other attribute to `props`.
     *
     * @return list<string>
     */
    public static function styleKeys(): array
    {
        $responsive = [];
        foreach (self::BREAKPOINTS as $bp) {
            foreach (self::RESPONSIVE_STYLE_KEYS as $key) {
                $responsive[] = "{$bp}:{$key}";
            }
        }

        return array_merge(
            array_keys(self::STYLE_ENUMS),
            self::STYLE_PX_KEYS,
            self::STYLE_HEX_KEYS,
            self::STYLE_URL_KEYS,
            $responsive,
        );
    }

    /**
     * Validate ONE node in isolation. Throws on anything not positively recognised. Does not
     * recurse into children; the tree walk is PmlCompiler's job.
     *
     * @param  array<string, mixed>  $node
     *
     * @throws InvalidPmlException
     */
    public function validateNode(array $node, string $path = 'node'): void
    {
        /*
         * A comment (`<!-- -->`, a studio note, a template's purpose note) may stand wherever a node may (0.26.1): a
         * post started from a template that carries purpose notes is validated on every save, and refusing the notes
         * meant it could never be saved. It holds its words and, for a studio note, its `note`; nothing else rides on it.
         */
        if (PmlParser::isCommentNode($node)) {
            if (! is_string($node['comment'])) {
                throw new InvalidPmlException('A comment holds words.', "{$path}.comment");
            }
            if (array_diff(array_keys($node), ['comment', 'note']) !== [] || (isset($node['note']) && ! is_array($node['note']))) {
                throw new InvalidPmlException('A comment holds its words and a note only.', $path);
            }

            return;
        }

        $primitive = $node['primitive'] ?? null;

        if (! is_string($primitive) || ! ElementCatalog::has($primitive)) {
            throw InvalidPmlException::unknownPrimitive((string) $primitive, $path);
        }

        $props = $node['props'] ?? [];

        if (! is_array($props)) {
            throw new InvalidPmlException("A node's props must be an object.", "{$path}.props");
        }

        $allowed = ElementCatalog::propNames($primitive);

        foreach ($props as $key => $value) {
            $key = (string) $key;
            $keyPath = "{$path}.props.{$key}";

            if (! in_array($key, $allowed, true)) {
                // This is where onclick, srcdoc, an inline style="", and any smuggled
                // attribute die: a prop that is not on the primitive's allowlist is rejected.
                // (Appearance lives in the node-level "style" object, never in props.)
                throw InvalidPmlException::unknownProp($key, $primitive, $keyPath);
            }

            // A `{...}` value (PML v3 L3) is a PSL expression the browser evaluates: its shape is checked
            // here, its VALUE never is (the server does not run PSL). A url still meets its scheme check
            // where it is drawn (the renderer's sink), as a bound url always has.
            if (self::isExpression($value)) {
                $this->assertExpression($value, $keyPath);

                continue;
            }

            if ((ElementCatalog::prop($primitive, $key)['type'] ?? null) === 'url' && is_string($value)) {
                // Reuse the block system's URL check verbatim (one source of truth for what
                // is a safe scheme), but present the PML boundary's single exception type so
                // the builder and the PML pane can catch InvalidPmlException uniformly.
                try {
                    $this->blocks->assertSafeUrl($value, $keyPath);
                } catch (InvalidBlockException $e) {
                    throw new InvalidPmlException($e->getMessage(), $e->path);
                }

                continue;
            }

            $this->validateToken($primitive, $key, $value, $keyPath);
        }

        // "style" is a node-level sibling of props, orthogonal to content and validated as a
        // closed token vocabulary. A "style" key inside props is rejected above as unknown.
        if (array_key_exists('style', $node)) {
            $this->validateStyle($node['style'], "{$path}.style");

            // A display value must suit the element: a heading cannot be a carousel, a box cannot be inline.
            $display = is_array($node['style']) ? ($node['style']['display'] ?? null) : null;
            if (is_string($display)) {
                $allowed = ElementCatalog::isContainer($primitive) ? self::DISPLAY_FOR_CONTAINER : self::DISPLAY_FOR_LEAF;
                if (! in_array($display, $allowed, true)) {
                    throw new InvalidPmlException("\"display={$display}\" does not suit a {$primitive}: use ".implode(', ', $allowed).'.', "{$path}.style.display");
                }
            }
        }

        // The control fields (if / else_if / else / each / on, PML v3 L3) are node-level siblings the
        // browser evaluates. Shape only here; the bystander-safety guarantee is at the renderer's sink
        // (a url drawn from an expression still passes assertSafeUrl), so this refuses a malformed node.
        $this->validateControl($node, $path);

        // "key" is another node-level sibling: the stable id a PSL `dom.el(key)` handle targets for an
        // imperative edit (setText / setAttr / append / remove). It is a SAFE id only (never a URL,
        // selector, or path), so a handle can reach exactly one keyed node and nothing beyond it.
        if (array_key_exists('key', $node)) {
            if (! is_string($node['key']) || preg_match(self::NODE_KEY, $node['key']) !== 1) {
                throw new InvalidPmlException(
                    'A node "key" must be a short id: a letter, then letters, digits, "_" or "-".',
                    "{$path}.key",
                );
            }
        }

        // "slot" names the component slot this element goes into on a page (component props p04).
        if (array_key_exists('slot', $node) && (! is_string($node['slot']) || preg_match(self::NODE_KEY, $node['slot']) !== 1)) {
            throw new InvalidPmlException('A "slot" names one of the places a component offers: a letter, then letters, digits, "_" or "-".', "{$path}.slot");
        }

        // "part" names the part of a layout this element is (post layouts A1a): a layout locks, repeats or checks it.
        if (array_key_exists('part', $node) && (! is_string($node['part']) || preg_match(self::NODE_KEY, $node['part']) !== 1)) {
            throw new InvalidPmlException('A "part" names one part of the layout: a letter, then letters, digits, "_" or "-".', "{$path}.part");
        }

        // "uid" is the element's permanent identity (B1): system-written, a short lowercase id only.
        if (array_key_exists('uid', $node) && (! is_string($node['uid']) || preg_match(PmlUids::PATTERN, $node['uid']) !== 1)) {
            throw new InvalidPmlException('An element "uid" is its permanent id: 6 to 16 lowercase letters and digits.', "{$path}.uid");
        }

        $hasChildren = array_key_exists('children', $node);
        $isContainer = ElementCatalog::isContainer($primitive);

        if ($hasChildren && ! $isContainer) {
            throw new InvalidPmlException(
                "\"{$primitive}\" cannot contain other nodes.",
                "{$path}.children",
            );
        }

        if ($isContainer && $hasChildren && ! is_array($node['children'])) {
            throw new InvalidPmlException('Container children must be a list.', "{$path}.children");
        }
    }

    /**
     * Enforce strict parent/child nesting for the primitives that require it (table/row/cell). A
     * container with no rule stays permissive. Called by {@see PmlCompiler} per child on the
     * validating pass, once the tree is pure primitives, so it never trips on a binding marker.
     *
     * @throws InvalidPmlException
     */
    public function assertContainment(?string $parent, string $child, string $path): void
    {
        $parents = ElementCatalog::parentsOf($child);

        if ($parents !== null && count($parents) > 1 && ! in_array($parent, $parents, true)) {
            throw new InvalidPmlException(
                "\"{$child}\" can only go inside a ".implode(', ', $parents).'.',
                $path,
            );
        }

        if ($parents !== null && count($parents) === 1 && $parent !== $parents[0]) {
            throw new InvalidPmlException(
                "\"{$child}\" can only go directly inside a \"".$parents[0]."\".",
                $path,
            );
        }

        $allowedChildren = $parent === null ? null : ElementCatalog::childrenOf($parent);

        if ($allowedChildren !== null && ! in_array($child, $allowedChildren, true)) {
            throw new InvalidPmlException("A \"{$parent}\" cannot contain a \"{$child}\".", $path);
        }
    }

    /**
     * One prop's value against its declared type in the element definitions. Every message is the one
     * the hand-written cases used to give, so authors and Porter's repairs read the same words.
     *
     * @throws InvalidPmlException
     */
    private function validateToken(string $primitive, string $key, mixed $value, string $path): void
    {
        $def = ElementCatalog::prop($primitive, $key) ?? ['type' => 'string'];

        switch ($def['type']) {
            case 'enum':
                $this->assertEnum($value, ElementCatalog::enumValues($def), $key, $path);

                return;

            case 'pattern':
                // A video id builds the iframe src; a form key is a route segment (/forms/{key}/submit).
                // Both are strict safe ids so nothing can break out of, or reshape, what they build.
                $regex = self::PATTERNS[$def['pattern'] ?? ''] ?? null;

                if ($regex === null || ! is_string($value) || preg_match($regex, $value) !== 1) {
                    throw InvalidPmlException::badToken($key, is_scalar($value) ? (string) $value : gettype($value), $path);
                }

                return;

            case 'email':
                // A typo would silently misroute the alerts, so it is caught at authoring time.
                if (! is_string($value) || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    throw new InvalidPmlException("\"{$key}\" must be a valid email address.", $path);
                }

                return;

            case 'date':
                // A source's access date (S1-07): a real calendar date, written YYYY-MM-DD.
                $ok = is_string($value) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $d) === 1
                    && checkdate((int) $d[2], (int) $d[3], (int) $d[1]);

                if (! $ok) {
                    throw new InvalidPmlException('A source date is the day you read it, written like 2026-11-06.', $path);
                }

                return;

            case 'anchor':
                if (! is_string($value) || preg_match(self::ANCHOR, $value) !== 1) {
                    throw new InvalidPmlException(
                        'An anchor is a short lowercase name for linking to this part of the page: letters, digits and hyphens, starting with a letter (like "eligibility").',
                        $path,
                    );
                }

                return;

            case 'doc':
                // Presence + shape only here; the constrained rich-text AST is deep-validated at
                // compile time by reusing BlockSchema's document walker (the single source of truth).
                if (! is_array($value)) {
                    throw new InvalidPmlException('A "doc" prop must be a rich-text document object.', $path);
                }

                return;

            case 'program':
                // A <script>'s compiled PSL AST-JSON. Object shape only here; PmlCompiler deep-checks it is
                // a well-formed Program node, so the browser runtime gets AST it can walk, never a raw
                // string to parse. The runtime is a tree-walker with a step budget and no host access.
                if (! is_array($value)) {
                    throw new InvalidPmlException('A "program" prop must be a compiled PSL program object.', $path);
                }

                return;

            case 'bool':
                if (! is_bool($value)) {
                    throw new InvalidPmlException("\"{$key}\" must be true or false.", $path);
                }

                return;

            default:
                // prose, string, and a url that was not a string: free text, never an array or object.
                if (! is_string($value)) {
                    throw new InvalidPmlException("\"{$key}\" must be a string.", $path);
                }
        }
    }

    /** A `{...}` value: `{"$expr": source, "ast": {...}}`. */
    public static function isExpression(mixed $value): bool
    {
        return is_array($value) && array_key_exists('$expr', $value);
    }

    /**
     * An expression's shape: its PSL source and the AST the parser compiled from it.
     *
     * @throws InvalidPmlException
     */
    private function assertExpression(mixed $value, string $path): void
    {
        if (! is_array($value) || ! is_string($value['$expr'] ?? null) || trim($value['$expr']) === '' || ! is_array($value['ast'] ?? null) || ! is_string($value['ast']['type'] ?? null)) {
            throw new InvalidPmlException('An expression must be written in braces, like {owner.name}.', $path);
        }
    }

    /**
     * The node-level control fields (PML v3 L3): `if` / `else_if` (an expression), `else` (true),
     * `each` (`{item, $expr, ast}`) and `on` (event => expression). Whether an `else` follows an `if` is
     * a sibling question, checked by the compiler.
     *
     * @param  array<string, mixed>  $node
     *
     * @throws InvalidPmlException
     */
    private function validateControl(array $node, string $path): void
    {
        foreach (['if', 'else_if'] as $field) {
            if (array_key_exists($field, $node)) {
                $this->assertExpression($node[$field], "{$path}.{$field}");
            }
        }

        if (array_key_exists('else', $node) && $node['else'] !== true) {
            throw new InvalidPmlException('"else" takes no value.', "{$path}.else");
        }

        if (array_key_exists('each', $node)) {
            $each = $node['each'];

            if (! is_array($each) || ! is_string($each['item'] ?? null) || preg_match(self::EACH_ITEM, $each['item']) !== 1) {
                throw new InvalidPmlException('"each" names the item and the list, like each={package in owner.packages}.', "{$path}.each");
            }

            $this->assertExpression($each, "{$path}.each");
        }

        if (array_key_exists('on', $node)) {
            if (! is_array($node['on'])) {
                throw new InvalidPmlException('"on" must be a set of events, like on-click={...}.', "{$path}.on");
            }

            foreach ($node['on'] as $event => $handler) {
                if (! in_array((string) $event, self::EVENTS, true)) {
                    throw new InvalidPmlException('An element answers '.implode(', ', array_map(fn ($e) => "on-{$e}", self::EVENTS)).", not on-{$event}.", "{$path}.on.{$event}");
                }

                $this->assertExpression($handler, "{$path}.on.{$event}");
            }
        }
    }

    /**
     * @param  list<string>  $allowed
     *
     * @throws InvalidPmlException
     */
    /**
     * One token, or for a paired key two tokens separated by a space (each side its own size).
     *
     * @param  list<string>  $allowed
     *
     * @throws InvalidPmlException
     */
    private function assertStyleToken(string $baseKey, mixed $value, array $allowed, string $key, string $path): void
    {
        if (in_array($baseKey, self::PAIRED_STYLE_KEYS, true) && is_string($value) && preg_match('/\s/', trim($value)) === 1) {
            $sides = preg_split('/\s+/', trim($value)) ?: [];
            $sizes = array_values(array_diff($allowed, ['custom']));
            if (count($sides) !== 2) {
                throw new InvalidPmlException("\"{$key}\" takes one size for both sides or two sizes, one per side (\"none spacious\").", $path);
            }
            foreach ($sides as $side) {
                $this->assertEnum($side, $sizes, $key, $path);
            }

            return;
        }

        $this->assertEnum($value, $allowed, $key, $path);
    }

    private function assertEnum(mixed $value, array $allowed, string $key, string $path): void
    {
        $str = is_scalar($value) ? (string) $value : null;

        if ($str === null || ! in_array($str, $allowed, true)) {
            throw InvalidPmlException::badToken($key, is_scalar($value) ? (string) $value : gettype($value), $path, $allowed);
        }
    }

    /**
     * The design with every style TOKEN in its one stored form, the schema's string (shirahcan/pml 0.5.0, E2h).
     *
     * The validator accepts `col_span: 7` and `col_span: "7"` as the same token (it compares the string form), but
     * kept whichever arrived. A whole-page Build round-trips the page through PML text, where every attribute is a
     * string, so a token saved as a number came back as a string and "What changed" named a box whose look had not
     * changed (found 2026-11-13). Applied at the host's save gate, a round trip is lossless and the diff tells the
     * truth. Only a number whose string form IS one of the key's tokens changes; anything else is left as it is.
     *
     * @param  list<mixed>  $nodes
     * @return list<mixed>
     */
    public static function canonical(array $nodes): array
    {
        return array_map(static function ($node) {
            if (! is_array($node)) {
                return $node;
            }

            if (is_array($node['style'] ?? null)) {
                foreach ($node['style'] as $key => $value) {
                    if (! is_int($value) && ! is_float($value)) {
                        continue;
                    }

                    $base = preg_match('/^(md|lg):([a-z_]+)$/', (string) $key, $m) === 1 ? $m[2] : (string) $key;

                    if (isset(self::STYLE_ENUMS[$base]) && in_array((string) $value, self::STYLE_ENUMS[$base], true)) {
                        $node['style'][$key] = (string) $value;
                    }
                }
            }

            if (is_array($node['children'] ?? null)) {
                $node['children'] = self::canonical(array_values($node['children']));
            }

            return $node;
        }, $nodes);
    }

    /**
     * @throws InvalidPmlException
     */
    private function validateStyle(mixed $style, string $path): void
    {
        if ($style === null) {
            return;
        }

        if (! is_array($style)) {
            throw new InvalidPmlException('"style" must be an object of tokens.', $path);
        }

        foreach ($style as $key => $value) {
            $key = (string) $key;
            $keyPath = "{$path}.{$key}";

            // A token chosen by an expression (background={state.open ? "accent" : "none"}) is evaluated
            // in the browser; the renderer only ever maps a known token to a class, so an unknown word
            // draws nothing. A pixel size, a colour or an address stays a fixed, checked value.
            if (self::isExpression($value)) {
                $base = preg_match('/^(md|lg):([a-z_]+)$/', $key, $mm) === 1 ? $mm[2] : $key;

                if (! isset(self::STYLE_ENUMS[$base])) {
                    throw new InvalidPmlException("\"{$key}\" takes a fixed value, not an expression.", $keyPath);
                }

                $this->assertExpression($value, $keyPath);

                continue;
            }

            // A responsive override (S2-01): `md:padding` takes the same values as `padding`, and
            // only the layout tokens may carry one (a `padding=custom` px escape has no md variant).
            if (preg_match('/^(md|lg):([a-z_]+)$/', $key, $m) === 1) {
                if (! in_array($m[2], self::RESPONSIVE_STYLE_KEYS, true)) {
                    throw new InvalidPmlException("\"{$m[2]}\" cannot change by screen size.", $keyPath);
                }

                $allowed = array_values(array_diff(self::STYLE_ENUMS[$m[2]], ['custom']));
                $this->assertStyleToken($m[2], $value, $allowed, $key, $keyPath);

                continue;
            }

            if (isset(self::STYLE_ENUMS[$key])) {
                $this->assertStyleToken($key, $value, self::STYLE_ENUMS[$key], $key, $keyPath);

                continue;
            }

            if (in_array($key, self::STYLE_PX_KEYS, true)) {
                if (! is_int($value) || $value < 0 || $value > self::PADDING_PX_MAX) {
                    throw new InvalidPmlException(
                        "\"{$key}\" must be an integer between 0 and ".self::PADDING_PX_MAX.'.',
                        $keyPath,
                    );
                }

                continue;
            }

            if (in_array($key, self::STYLE_HEX_KEYS, true)) {
                if (! is_string($value) || preg_match(self::HEX_COLOR, $value) !== 1) {
                    throw new InvalidPmlException("\"{$key}\" must be a #rrggbb colour.", $keyPath);
                }

                continue;
            }

            if (in_array($key, self::STYLE_URL_KEYS, true)) {
                if (! is_string($value)) {
                    throw new InvalidPmlException("\"{$key}\" must be a URL.", $keyPath);
                }

                // A background image url passes the SAME scheme check every url does (reused, not
                // reimplemented), presented as the PML boundary's exception type.
                try {
                    $this->blocks->assertSafeUrl($value, $keyPath);
                } catch (InvalidBlockException $e) {
                    throw new InvalidPmlException($e->getMessage(), $e->path);
                }

                continue;
            }

            throw InvalidPmlException::unknownStyleToken($key, $keyPath);
        }
    }
}
