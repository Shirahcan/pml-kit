<?php

namespace Shirahcan\Pml;

/**
 * The standard library: the sections a consultant gets out of the box, authored as PML
 * component templates over the trusted primitives. This is what makes "everything above
 * primitives is a component" literally true - the built-in sections use the same expander,
 * the same compile gate, and the same primitive vocabulary as a consultant's own partials.
 *
 * Two conventions keep the templates simple:
 *   - Optional sub-elements (a hero image, a CTA button) are ALWAYS in the template; the
 *     primitive renderer omits the ones whose data is absent, so no show-if is needed here.
 *   - Repeating content (FAQ entries, timeline steps) is authored as CHILD tags in a <slot/>,
 *     not a data loop; data-driven repetition (over real packages) is the binding layer's job.
 *
 * A template must have exactly one root element. Field names mirror the block catalog so the
 * cutover is a like-for-like move, not a redesign of what a consultant can say.
 */
class StandardLibrary
{
    /** @var array<string, array{params: list<string>, pml: string}> */
    private const COMPONENTS = [
        'hero' => [
            'params' => ['headline', 'subhead', 'image_url', 'image_alt', 'cta_label', 'cta_href'],
            'pml' => '<box align="center" padding="spacious">
  <heading text={props.headline} level="1" heading_size="xl"/>
  <text value={props.subhead} body_size="lg"/>
  <button label={props.cta_label} href={props.cta_href} variant="primary"/>
  <image src={props.image_url} alt={props.image_alt}/>
</box>',
        ],
        'about' => [
            'params' => ['heading', 'photo_url', 'photo_alt'],
            'pml' => '<box padding="normal">
  <image src={props.photo_url} alt={props.photo_alt}/>
  <heading text={props.heading} level="2"/>
  <slot/>
</box>',
        ],
        'image_text' => [
            'params' => ['heading', 'image_url', 'image_alt'],
            'pml' => '<grid columns="2" gap="lg">
  <image src={props.image_url} alt={props.image_alt}/>
  <box>
    <heading text={props.heading} level="2"/>
    <slot/>
  </box>
</grid>',
        ],
        'cta_banner' => [
            'params' => ['headline', 'body', 'cta_label', 'cta_href'],
            'pml' => '<box align="center" background="accent" padding="spacious">
  <heading text={props.headline} level="2"/>
  <text value={props.body}/>
  <button label={props.cta_label} href={props.cta_href} variant="primary"/>
</box>',
        ],
        // `faq` / `faq_item` are PRIMITIVES now (PML v2 S1-08), so the published page keeps the FAQ
        // marker for FAQPage structured data; no stored page used the components when they moved.
        'stats' => [
            'params' => ['heading'],
            'pml' => '<box padding="normal">
  <heading text={props.heading} level="2"/>
  <grid columns="3" gap="md">
    <slot/>
  </grid>
</box>',
        ],
        'stat' => [
            'params' => ['value', 'label'],
            'pml' => '<box align="center">
  <heading text={props.value} level="2" heading_size="lg"/>
  <text value={props.label} body_size="sm"/>
</box>',
        ],
        'team' => [
            'params' => ['heading'],
            'pml' => '<box padding="normal">
  <heading text={props.heading} level="2"/>
  <grid columns="3" gap="lg">
    <slot/>
  </grid>
</box>',
        ],
        'member' => [
            'params' => ['name', 'role', 'photo_url', 'photo_alt'],
            'pml' => '<box align="center">
  <image src={props.photo_url} alt={props.photo_alt}/>
  <heading text={props.name} level="3" heading_size="sm"/>
  <text value={props.role} body_size="sm"/>
</box>',
        ],
        'process_timeline' => [
            'params' => ['heading'],
            'pml' => '<box padding="normal">
  <heading text={props.heading} level="2"/>
  <slot/>
</box>',
        ],
        'step' => [
            'params' => ['title'],
            'pml' => '<box direction="row" gap="md" padding="compact">
  <heading text={props.title} level="3" heading_size="sm"/>
  <slot/>
</box>',
        ],
        // trust_bar/logo and social_links/social_link are RETIRED. Each was a thin container+item
        // pair that expanded to a plain grid/box of <image>/<button> - i.e. pure primitive
        // composition with nothing a consultant (or Porter) cannot author directly with the raw
        // <grid>/<box>/<image>/<button> primitives. Social profile links also already live at the
        // SITE level (ConsultantPublicSite.social_links, edited in Site settings, rendered in the
        // footer + SEO jsonLd), so a duplicate page section was redundant. Removed to keep one
        // way to say each thing; a distinctive logo strip is a <grid> of <image> primitives.
        'office_map' => [
            'params' => ['heading', 'address', 'note'],
            'pml' => '<box padding="normal">
  <heading text={props.heading} level="2"/>
  <text value={props.address}/>
  <text value={props.note} body_size="sm"/>
</box>',
        ],
        // contact_form is a MODULE PRIMITIVE (see PrimitiveSchema), not a std-lib component:
        // it owns its consent capture and the funnel's submitLead, which a box of loose
        // field primitives cannot. It is therefore absent from this component library.
        // testimonials is a MODULE PRIMITIVE (see PrimitiveSchema), not a std-lib component, for
        // the same reason packages is: the platform bakes the resolved content, here the site's
        // ATTESTED + visible testimonials, and the s.45 attestation is verified at publish by
        // ComplianceScanner::scanTestimonials. (Stage 8 built the attestation model this once
        // waited on; an unattested client quote can no longer reach a public page.)
        // `callout` is a PRIMITIVE now (PML v2 S1-04: <callout tone= title=>, an <aside role=note>),
        // not a std-lib component; no stored page used the component when it moved.
        // A slim sticky bar: a short message + one CTA that pins to the top on scroll. The
        // motion=sticky is baked into the template, so it works for every tier (it is the
        // component\'s definition, not a rail control - see the 6d premium gate).
        'announcement' => [
            'params' => ['message', 'cta_label', 'cta_href'],
            'pml' => '<box background="accent" padding="compact" direction="row" gap="sm" motion="sticky">
  <text value={props.message}/>
  <button label={props.cta_label} href={props.cta_href} variant="primary"/>
</box>',
        ],
        // header / nav / footer are NOT std-lib components: they are self-sustaining PRIMITIVES
        // (see PrimitiveSchema), rendered as <header>/<nav>/<footer>. Their default identity + nav +
        // disclaimer body is a PML template in PrimitiveDefaultBody, filled into any bare tag by
        // PmlComponentExpander::fillDefaultBodies (the primitive analogue of a component template).
        //
        // button_row is RETIRED: it was exactly `<box direction=row gap=sm>` with a slot, which is
        // the box primitive verbatim. A row of buttons is authored directly as <box direction=row>.
        // bullet_list / numbered_list / checklist / icon_list / icon_list_item are RETIRED: they
        // were four near-identical box>heading>slot sections whose bullet-vs-numbered distinction
        // lived in the Markdown anyway. Superseded by the structured `list` PRIMITIVE (one tag, a
        // type=bullet|numbered|check|icon axis, holding list_item children; see PrimitiveSchema).
    ];

    /**
     * The component map for {@see PmlCompiler}: name => expander callable.
     *
     * @return array<string, callable>
     */
    public function components(PmlComponentExpander $expander): array
    {
        $map = [];

        foreach (self::COMPONENTS as $name => $definition) {
            $map[$name] = $expander->make($definition['pml'], $definition['params']);
        }

        return $map;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys(self::COMPONENTS);
    }

    /**
     * Each section's settings and whether it holds elements of its own (a `<slot/>`), for the Studio's settings panel
     * (exported to pml-ui by {@see ElementsTypescript}). Without it a stored standard section with no menu entry of its
     * own (a process timeline, a team) was drawn but read as "a reusable component, edited under My components", with no
     * way to change its heading in a post, which has no My components (shared PML Studio P1a-3d, 2026-11-24).
     *
     * @return array<string, array{params: list<string>, has_slot: bool}>
     */
    public function sections(): array
    {
        return array_map(fn (array $def) => ['params' => $def['params'], 'has_slot' => str_contains($def['pml'], '<slot')], self::COMPONENTS);
    }

    /**
     * Each component's declared param names, for the Porter prompt spec. Derived from the same
     * COMPONENTS the compiler expands, so the language Porter is taught can never drift from the
     * language the gate accepts.
     *
     * @return array<string, list<string>>
     */
    public function signatures(): array
    {
        return array_map(fn (array $def) => $def['params'], self::COMPONENTS);
    }
}
