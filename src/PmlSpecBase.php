<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\ElementCatalog;

/**
 * The COMMON BASE of the language reference handed to Porter so it can SPEAK PML: what is true on
 * every site (syntax, sections, primitives, design, interactivity, style tokens). Each audience
 * extends it: {@see ConsultantPmlSpec} for a consultant's site, {@see PlatformPmlSpec} for
 * Portify's own. {@see PmlPromptSpec} picks one for the site's owner.
 *
 * Split 2026-11-07 (owner: "separate Porter instructions for admins and consultants, extending off a
 * common base"): admin pages had been taught the consultant rulebook ("A page needs a <header/> +
 * <footer/>", consultant bindings, licensee modules), which their save gate refuses, so every
 * whole-page ask on an admin page failed. What differs by audience is a method here, never an edit
 * of shared text: the few shared lines that name an audience's tags are SLOTS (`[[name]]`) each
 * audience fills in {@see slots()}.
 *
 * The section list is derived from {@see StandardLibrary::signatures()} (and the module primitives
 * from {@see PrimitiveSchema}), so the vocabulary Porter is taught can never drift from the
 * vocabulary the compiler accepts; a parity test asserts every one is named.
 */
abstract class PmlSpecBase
{
    public function __construct(
        protected readonly StandardLibrary $library,
    ) {
    }

    /** A one-line purpose per component, so Porter knows WHEN to reach for each. */
    private const PURPOSE = [
        'hero' => 'The first thing a visitor reads. Say what you do and for whom.',
        'about' => 'Who you are and why to trust you with a file. Holds Markdown prose.',
        'image_text' => 'A picture beside copy. Holds Markdown prose.',
        'cta_banner' => 'One clear invitation to act, in a band they cannot scroll past.',
        'stats' => 'A short row of TRUE numbers. Holds stat children.',
        'stat' => 'One number (value) and its label.',
        'team' => 'The people who will handle the work. Holds member children.',
        'member' => 'One person: name, role, optional photo.',
        'process_timeline' => 'How working with you actually goes, step by step. Holds step children.',
        'step' => 'One step: a title and Markdown detail.',
        'office_map' => 'Where you are: heading, address, an optional note.',
        'announcement' => 'A slim accent bar with a short message and one CTA, pinned to the top on scroll.',
    ];

    /**
     * The full language reference for a compose/revise system prompt. `$studio` is the builder catalog
     * for the site being written (StudioComponentResolver::catalogFor): the sections already designed
     * for it, which Porter reaches for before building the same thing from primitives.
     *
     * @param  list<array<string, mixed>>  $studio
     */
    public function describe(array $studio = []): string
    {
        return strtr(implode("\n\n", array_filter([
            $this->intro(),
            $this->syntax(),
            $this->sections(),
            $this->studio($studio),
            $this->primitives(),
            $this->longForm(),
            $this->design(),
            ...$this->audienceSections(),
            $this->interactivity(),
            $this->styleTokens(),
            $this->rules(),
            $this->examples(),
        ])), $this->slots());
    }

    /** Whose site this is, and what PML is for there. */
    abstract protected function intro(): string;

    /** What PRIMITIVES says about <header/>, <nav/> and <footer/> (first line unindented, the rest 4 spaces). */
    abstract protected function landmarks(): string;

    /**
     * The audience's own sections between DESIGN and INTERACTIVITY (modules, forms, the data it may read).
     *
     * @return list<string>
     */
    abstract protected function audienceSections(): array;

    abstract protected function rules(): string;

    abstract protected function examples(): string;

    /**
     * The audience's text for each `[[slot]]` in the shared sections: leaf-example, prose-example,
     * bindings-note, static-bindings, row-justify.
     *
     * @return array<string, string>
     */
    abstract protected function slots(): array;

    /** @param  list<array<string, mixed>>  $components */
    protected function studio(array $components): string
    {
        $lines = [];

        foreach ($components as $component) {
            if (($component['offered'] ?? false) !== true) {
                continue;
            }

            $version = $component['versions'][(string) $component['current_version']] ?? [];
            // Each setting as it is written on the tag, by kind (component props p08): words in quotes, a
            // number or yes/no in braces, a list or map JS-like.
            $attrs = array_map(fn (array $p) => str_replace('_', '-', (string) $p['name']).'='.self::exampleValue($p, true), $version['params'] ?? []);
            $named = array_values(array_filter($version['slots'] ?? [], fn (array $s) => ($s['name'] ?? '') !== ''));
            $note = ($version['has_slot'] ?? false) ? ' Holds child elements.' : '';

            if ($named !== []) {
                $note .= ' Named places: '.implode(', ', array_map(fn (array $s) => "slot=\"{$s['name']}\" ({$s['label']})", $named)).' on a child puts it there.';
            }

            $purpose = trim((string) ($component['description'] ?? $component['name']));

            $lines[] = trim('<'.$component['tag'].' '.implode(' ', $attrs).'>').' - '.$purpose.$note;
        }

        if ($lines === []) {
            return '';
        }

        array_unshift($lines, 'PORTIFY COMPONENTS (already designed and approved for this site. When one fits, USE it instead of building the same thing from primitives, and fill in its attributes. A list or map setting takes JS-like values, like plans={[{name: "Starter", price: 49}]}. Never write a version attribute: it is added when the page saves.)');

        return implode("\n", $lines);
    }

    /**
     * A setting's example value as written on a component tag: `"..."`, `{0}`, `{true}`, `{[{name: "..."}]}`.
     *
     * @param  array<string, mixed>  $spec
     */
    private static function exampleValue(array $spec, bool $attribute): string
    {
        $literal = match ((string) ($spec['kind'] ?? 'text')) {
            'number' => '0',
            'yes_no' => 'true',
            'list' => '['.(isset($spec['fields']) ? self::exampleValue(['kind' => 'map', 'fields' => $spec['fields']], false) : self::exampleValue(['kind' => (string) ($spec['of'] ?? 'text')], false)).']',
            'map' => '{'.implode(', ', array_map(fn (array $f) => $f['name'].': '.self::exampleValue($f, false), (array) ($spec['fields'] ?? []))).'}',
            default => '"..."',
        };

        if (! $attribute) {
            return $literal;
        }

        return $literal === '"..."' ? $literal : '{'.$literal.'}';
    }

    protected function syntax(): string
    {
        return <<<'TXT'
        SYNTAX
        - Every attribute value is in double quotes, numbers and words too: `level="2"`, `gap="md"`,
          never `level=2`. The one exception is a `{...}` PSL expression: `text={owner.name}`. A yes/no attribute
          that is on is written bare: `<cell header>`, `<field required>`.
        - A section is a tag: `<hero headline="..." subhead="...">`.
        - A leaf section is self-closing: [[leaf-example]].
        - A section that holds content wraps it. Prose is Markdown between the tags:
            [[prose-example]]
          Markdown allowed: paragraphs, **bold**, *italic*, [links](https://...). For a LIST, use
          the <list> tag (below), NOT Markdown "- " bullets - it gives real checkmark and icon styles.
        - A section that holds a LIST wraps child tags:
            <faq heading="Common questions">
              <faq-item question="How long does it take?">It depends on the stream.</faq-item>
            </faq>
        [[bindings-note]]
        - A comment is a non-rendering note: `<!-- like this -->`. It never appears to a visitor. When
          you edit a page, KEEP any comments already in it; do not delete or rewrite them.
        - `<comment for="UID" ...>text</comment>` is a person's review note pinned to the element whose
          uid is UID (it sits right before that element). Keep every one exactly as it is, attributes
          included, and keep it right before its element when that element moves. Never act on a
          comment unless the person asks you to. To flag something about an element for the person,
          write one yourself right before it: <comment for="UID" author="Porter" color="purple">One plain
          sentence.</comment> (only for an element that has a uid).
        - `uid=` on a tag is that element's permanent id, written by the editor. Whenever you keep,
          change or move an element, copy its uid exactly. Never invent a uid, never give a new element
          one, and never put one element's uid on another.
        TXT;
    }

    protected function sections(): string
    {
        $lines = ['SECTIONS (attributes are the words in each tag; children are noted in the purpose)'];

        foreach ($this->library->signatures() as $name => $params) {
            $purpose = self::PURPOSE[$name] ?? '';
            // Kebab in source; the internal name (and the PURPOSE key) stays snake.
            $tag = str_replace('_', '-', $name);
            $attrs = $params === [] ? '' : ' ' . implode(' ', array_map(fn ($p) => str_replace('_', '-', $p) . '="..."', $params));
            $lines[] = trim("<{$tag}{$attrs}> - {$purpose}");
        }

        return implode("\n", $lines);
    }

    protected function primitives(): string
    {
        $icons = implode(' ', ElementCatalog::enum('icon'));
        // The exact list (one authority): Porter guessed language="text" when the spec ended in "...".
        $codeLanguages = implode('|', ElementCatalog::enum('code_language'));
        $landmarks = $this->landmarks();

        return <<<TXT
        PRIMITIVES (the raw building blocks. Reach for these to craft a CUSTOM layout when no section
        above expresses the intent - this is where a distinctive, god-tier design comes from. They
        pass the same safety gate, so composing them is safe.)
        Containers (hold children; nesting is capped at 4 levels):
          <box direction="column|row" gap="none|xs|sm|md|lg|xl" align="center" padding="spacious">...</box>
            - a stack (column, the default) or a row. The workhorse for arranging things.
            Say what a box IS with as=section|article|aside|main|figure|address|blockquote (it then
            renders that element, which screen readers and search engines understand): the page's
            main content is as=main (one per page), a self-contained piece of writing is
            as=article, and each part of a long page is as=section with its own level-2 heading.
            ratio=square|4:3|3:2|16:9|3:4 gives a box a picture's shape (a frame waiting for a picture).
            A box of equal items that slide (testimonials, logos, cards) is a CAROUSEL, never a grid of them:
            give it the style display=carousel (see DISPLAY below).
          <grid columns="1|2|3|4" gap="none|xs|sm|md|lg|xl">...</grid> - a responsive grid; one column on phones.
          {$landmarks}
        Leaves:
          <heading text="..." level="1|2|3|4" heading-size="sm|md|lg|xl"/>
          Words, three kinds (pick the plainest that does the job):
            <text value="..." body-size="sm|md|lg"/> - plain words, no formatting: a label, a caption, one line.
            <p>Book a call with <strong>{owner.name}</strong> or <link href="#fees">see the fees</link>.</p>
              - a paragraph whose words can be formatted or show page data. Inside a <p> ONLY words and these:
              <span> <strong> <emphasis> <small> <mark> <link href="..."> <quote cite="..."> <keyboard>
              <sub> <sup> <abbr title="..."> <time datetime="..."> <code>verbatim</code> <br/>.
              They never go anywhere else. A {...} in the words shows page data; write \{ for a literal brace.
            <rich-text>Markdown: ## headings, **bold**, *italic*, [links](https://...), > quotes</rich-text>
              - longer formatted writing, kept as the Markdown you write. Markdown written directly inside a
              <box>/<grid> is rich text too. A {...} in it shows page data.
          Longer pieces:
            <pre language="{$codeLanguages}">code exactly as written</pre> - a code block (plaintext for
              anything that is not code in one of those); nothing
              in it runs and nothing in it is read as a tag or {...}.
            <blockquote cite="https://...">  <p>...</p>  </blockquote> - a quotation set apart.
            <details>  <summary>The line a visitor clicks</summary>  <p>The detail</p>  </details> - opens with no
              script; `open` starts it open. The summary comes first, and only once.
            <definitions>  <term>LMIA</term>  <definition><p>A labour market impact assessment.</p></definition>  </definitions>
            <figure>  <image .../> (or a <pre>, a <table>)  <caption>What it shows</caption>  </figure>
            A summary, a term and a caption hold words like a <p> (strong, link, {...}).
          <button label="..." href="https://... | #anchor | /path" variant="primary|outline|link"/>
          <image src="https://..." alt="describe it" caption="optional"/>
          <video provider="youtube|vimeo" id="the-video-id" title="describe it"/> - a safe embedded
            video; use the video ID only (e.g. dQw4w9WgXcQ), never a full URL.
          <icon name="..."/> - one of: {$icons}
          <divider size="sm|md|lg"/> - a hairline rule.    <spacer size="sm|md|lg"/> - blank vertical space.
          anchor="who-can-apply" on a heading or container gives it a link name (lowercase letters,
            digits, hyphens) for #links; every heading gets one automatically, so set it only when a
            link needs a short, stable name. Each anchor is used once per page.
          <toc title="On this page" depth="2|3"/> - a table of contents built from the page's own
            headings (level 2, plus level 3 at depth=3). Put it after a long page's introduction.
          <sources title="Sources">
            <source title="..." publisher="..." href="https://..." date="YYYY-MM-DD"/>
          </sources> - numbered citations for the official pages and laws the page relies on.
            CITE ONLY WHAT IS REAL: a source the author gave you, or a page you are certain exists
            (canada.ca, laws-lois.justice.gc.ca). Never invent a URL or a date: leave href or date
            out and the author fills it in. A made-up citation on immigration advice is worse than none.
          Code, in prose: `inline code` with backticks, and a fenced block for longer code
            (three backticks, an optional language like json, the code, three backticks).
          <faq heading="...">  <faq-item question="...">answer in Markdown</faq-item> ...  </faq>
            - answers to what anxious applicants actually ask. Search engines read it as an FAQ, so
            use it for real questions only (two or more), each answered plainly.
          <callout tone="info|tip|warning|success|danger" title="...">Markdown or children</callout> - a short,
            toned aside a reader must not miss (a deadline, a common mistake, a tip). Holds prose,
            a list, a button or an image; never a section or a form.
        Lists - ALWAYS use <list> for a list (never Markdown "- " bullets). ONE tag, a type axis,
        holding list-item children. A CHECKLIST (type=check, real checkmarks) and an ICON list
        (type=icon, icon cards) can ONLY be made this way - Markdown cannot draw either.
          <list heading="..." type="bullet|numbered|check|icon">
            <list-item text="a bullet, numbered, or checked line"/>
            <list-item icon="check-circle" title="For type=icon" text="each item is an icon card"/>
          </list>
          type=icon draws cards (icon + title + text); bullet/numbered/check draw a marked line from
          each item's text. list-item icons use the allowed names above.
        Table - for genuinely TABULAR data (a fee schedule, a comparison), not for page layout (use
        <grid> for layout). Strict nesting: a <table> holds <row>s, a <row> holds <cell>s, a <cell>
        holds ordinary content. Mark a heading cell with a bare `header` (renders a real table
        header). A cell may span with col-span/row-span. `header` is yes/no: write it bare
        (`<cell header>`).
          <table caption="What each package includes">
            <row>
              <cell header>Service</cell>
              <cell header>Price</cell>
            </row>
            <row>
              <cell>Study permit</cell>
              <cell>$2,500</cell>
            </row>
          </table>
        A child of a <grid> may span with col-span=1|2|3|4 and row-span=1|2|3 (never more than the
        grid's own columns). Compose these to build, for instance, a three-up <grid> of <box>es each
        holding an <icon>, a <heading> and a line of text. PREFER a section above when one fits;
        primitives are for the custom 20% a pre-built section cannot express.
        TXT;
    }

    /**
     * How a long article is shaped (S1-11): semantic, linkable and cited, so it reads well, ranks,
     * and can be trusted.
     */
    protected function longForm(): string
    {
        return <<<'TXT'
        LONG ARTICLES (a guide, an explainer, a "what this letter means" page):
          - Wrap the whole piece in <box as="article">. Open with ONE level-1 heading and a short
            plain-language summary of who it is for.
          - Add <toc/> after that introduction when the piece has three or more parts.
          - Split it into <box as="section"> parts, each starting with a level-2 heading; use level 3
            inside a part, never skipping a level.
          - Put a deadline, a common mistake or a tip a reader must not miss in a <callout tone="...">.
          - Answer the questions people actually ask in a <faq> (two or more real questions).
          - End with <sources> for the official pages and laws you relied on (only real ones).
          - Describe every image in its alt text, and label buttons with where they go
            ("See the document checklist", never "Click here").
        TXT;
    }

    /** Responsive design and depth (S2-10): reflow per screen, frame images, decorate safely. */
    protected function design(): string
    {
        return <<<'TXT'
        DESIGNING FOR EVERY SCREEN (mobile first):
          - A layout token set plainly is the PHONE value. Prefix it md: for tablets (768px and up) or
            lg: for desktops (1024px and up): <box padding="compact" md:padding="spacious" lg:align="left">.
            Only layout tokens change per screen: padding, padding-x, margin-y, align, width,
            heading-size, body-size, justify, align-items, min-height, col-span. Never colour.
          - hide=mobile|tablet|desktop leaves an element out on one screen size (a wide table on
            phones). Never hide the page's only level-1 heading.
          - <grid columns="12"> takes asymmetric spans: <box col-span="7">...</box><box col-span="5">...</box>,
            and lg:col-span= for desktops. Grids stack to one column on phones by themselves.
          - Images: ratio=auto|square|4:3|3:2|16:9|3:4, fit=cover|contain, focal=center|top|bottom|left|right,
            size=xs|sm|md|lg for a picture smaller than its column (xs 48px: an icon; sm 160px: a logo; md 360px;
            lg 640px); without a size it fills its column.
          - <layer shape="blob|circle|ring|dots|grid|image" position="top-left|top-right|bottom-left|bottom-right|center"
            size="sm|md|lg" color="accent|muted|surface"/> as the FIRST child of a <box> adds depth behind its
            content. Decoration only: it holds no text, and one or two per page is plenty.
          - Buttons: variant=primary (the main action), outline or soft (secondary), ghost or link
            (tertiary). One primary action per section.
        TXT;
    }

    protected function interactivity(): string
    {
        return <<<'TXT'
        INTERACTIVITY (optional, advanced: a little live behaviour that runs in a visitor's browser.
        Every script you write is compiled and checked before anyone sees the page: a name nothing
        defines comes back to you to fix. Everything here is safe: the code computes VALUES only, it
        has no network, storage, or page access, and nothing it does can make a promise the RULES forbid.)
        Inside a COMPONENT, keep its own values in `self` (self.open, self.count++), never `state`: every
        copy of the component on a page gets its own `self` and its own functions, so opening one copy
        never opens another. `state` is shared by the whole page. Write self.name, never `self` alone.
        For a show / hide on each item of a repeated list, use <details> (it needs no script at all).
        A <script> holds PSL (a small, JS-like scripting language). It renders nothing; it just defines
        the functions the page can call. Write the code as the tag's BODY, never as an attribute:
            <script>
            function togglePanel() { state.open = !state.open }
            function increment() { state.count++ }
            </script>
        Comment your PSL with `//` for a single line or `/* ... */` for a block; comments are ignored
        when the script runs.
        `state` is a shared store you MUST read and write with a DOT: state.open (read), state.open = true
        (write), state.count += 1 or state.count++ (an unset number counts as 0). There are NO
        state.get / state.set / state.has methods - writing state.set("open", ...) FAILS; write
        state.open = ... instead. Wire a VISIBLE element to it with expressions in braces:
        - on-click / on-change / on-input / on-submit run a function a <script> defined, or a change
          written right there:
            <button label="Show more" on-click={togglePanel}/>
            <button label="Add one" on-click={state.count++}/>
        - if={...} shows an element only while the expression is truthy; else-if={...} and a bare else
          may follow it as the next siblings:
            <box if={state.open}> More detail here. </box>
        - any value in braces tracks state live:
            <text value={state.count}/>   <box background={state.open ? "accent" : "none"}>...</box>
        [[static-bindings]]
        and is shown the same way. Keep interactivity small and optional: the visible page must still
        read and work with it switched off.
        TXT;
    }

    protected function styleTokens(): string
    {
        return <<<'TXT'
        STYLE (optional, on any section OR primitive; tokens only, never CSS. Written as attributes,
        kebab-cased. The lists below show each key's choices as key=a|b; write the one you pick in
        quotes: align="center".)
        LAYOUT
          align=left|center|right  width=narrow|normal|wide|xwide|full
          WIDTH: a block with no width takes the site's STANDARD PAGE WIDTH, the same column as the site's
          header and footer, so a page's edges line up from the logo down. That is the default for every
          block, a page-wide box and plain text alike: leave width off. Set it only when the person asks for a
          block narrower or wider than the page (narrow, normal, wide, xwide, or full for a full-bleed band),
          and never narrow a whole page or the box that wraps it on your own.
          padding=none|compact|normal|spacious  padding-x=none|compact|normal|spacious
          margin-y=none|sm|md|lg  min-height=none|sm|md|lg
          padding is the space above and below (per side: compact 16px, normal 32px, spacious 64px on
          desktop); padding-x the space left and right. Each takes ONE size for both sides or TWO, one per
          side: padding="none spacious" is top none and bottom spacious, padding-x="compact normal" is left
          compact and right normal (md: and lg: take pairs too). Give it to a page's top-level bands and to a box with its own background; a box
          inside a padded box takes padding="none" (or "compact" when it has a background) and its
          parent's gap spaces it, so padding is never stacked.
        DISPLAY (how the element lays itself out)
          display=block|flex|grid|carousel on a box (flex is the default: its direction=row|column);
          display=block|inline|inline-block on words, pictures and buttons.
          A carousel's settings: carousel-per-view=1|2|3|4 (md:/lg: may differ; one on phones by default),
          carousel-motion=none|step|scroll (step: one view every few seconds; scroll: an endless track,
          like a row of logos), carousel-speed=slow|normal|fast, carousel-controls=arrows|dots|both|none,
          carousel-loop=on|off. Moving carousels pause on hover and stay still for reduced motion.
        POSITION (where it sits as the page scrolls)
          placement=static|sticky|fixed with placement-edge=top|bottom: sticky stays at the edge while its
          section scrolls past (a header bar); fixed stays at the edge of the window (a call-to-action bar).
          Never pin anything over the page's words. stacking=base|raised|top orders overlapping elements;
          overflow=visible|clip|scroll-x (scroll-x: a wide row scrolls sideways on small screens).
        PLACEMENT (how a container - box/grid/nav/header/footer - arranges its children)
          justify=start|center|end|between|around|evenly   align-items=start|center|end|stretch|baseline
          self-align=start|center|end|stretch (on ONE child, to override the row's cross-axis alignment)
          Inside a box, every child fills the box's full width unless told otherwise, and align=center
          only centres the words INSIDE it. A pill, badge or tag, or a call-to-action button, that should
          be only as wide as its words takes self-align=start (or center, under centred text) itself:
            <box self-align="start" background="accent" radius="full" padding="none" padding-x="compact">
              <text value="Most popular" body-size="sm" text-color="on-accent"/></box>
          [[row-justify]]
        TYPOGRAPHY (on a heading/text/button, or a container passing it down)
          heading-size=sm|md|lg|xl  body-size=sm|md|lg
          text-color=default|muted|accent|on-accent|success|danger (palette colours, always legible;
            on-accent is for text sitting on an accent background)  font-weight=normal|medium|semibold|bold
          line-height=tight|normal|relaxed|loose  letter-spacing=tight|normal|wide
          text-transform=none|uppercase|capitalize
          font=heading|body (which of the site's two fonts: a heading set in the body font, a quote in
            the heading font; never any other family). The payload's `fonts` names both and says which
            is a serif: when you match a picture or a source page, follow `fonts.matching` so a heading
            the source sets in a sans-serif is not drawn in a serif
        BORDER & EFFECTS
          border=none|hairline|thin|thick  border-color=default|muted|accent
          radius=none|sm|md|lg|xl|full  shadow=none|sm|md|lg
          A custom colour only where a palette token will not do: text-color-custom="#rrggbb",
          border-color-custom="#rrggbb" (and background=custom background-color="#rrggbb"). Prefer the
          palette tokens - they stay on-brand and legible; a free colour can break contrast.

        BACKGROUND (background=...):
          none | surface | accent | gradient | image
          A gradient wash: background=gradient background-gradient=dawn|dusk|ocean|forest|slate
          A photo: background=image background-image-url="https://..." background-overlay=none|light|dark|strong
            The overlay is what keeps text legible over the image, so use dark or strong over a busy
            photo. White text is applied automatically over a dark/strong overlay.
          READABLE TEXT: every gradient is a LIGHT wash, and surface is light on a light scheme, so text
          on them keeps its default dark colour: never text-color=on-accent or a white custom colour
          there. On background=accent the text turns readable by itself. A copied design with white
          text on a pale band is unreadable here: keep the band's mood with background=accent or a dark
          custom background instead. Text a visitor cannot read is sent back to you to fix.

        MOTION (motion=...; a trusted runtime animates it, and it respects reduced-motion):
          none | reveal-up | reveal-in | stagger | parallax-soft | hover-lift | sticky | count-up
          reveal-up / reveal-in fade a section in as it scrolls into view; hover-lift lifts a card on
          hover; sticky pins a bar; count-up animates a number. Use ONE or TWO per page, never on
          every section, or it feels cheap.

        Use these purposefully: a centered, spacious hero over an image with a dark overlay and a
        reveal-up; an accent cta-banner; a stats row with count-up.
        TXT;
    }
}
