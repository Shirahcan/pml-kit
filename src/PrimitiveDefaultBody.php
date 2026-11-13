<?php

namespace Shirahcan\Pml;

/**
 * The default body of a SELF-SUSTAINING primitive, authored in PML source.
 *
 * A self-sustaining primitive is one that knows what a sensible default instance of itself looks
 * like. Writing the bare tag - <header/>, <nav/>, <footer/> - is shorthand for that default: at
 * build, {@see PmlComponentExpander::fillDefaultBodies} fills any EMPTY instance with the body
 * declared here, letting the author's own attributes win over the defaults. So the SOURCE stays a
 * concise tag while the COMPILED page carries the full content (the register-bound licensee
 * identity, the site.pages nav, the College licence line, the mandated no-guarantee disclaimer)
 * that the renderer draws and the compliance scan reads.
 *
 * This is NOT special treatment for three tags. It is one general capability keyed off the
 * primitive name: any primitive that declares a source here self-sustains through the SAME generic
 * pass, and nothing in that pass names a primitive. The bodies bind through the same whitelisted
 * placeholders a consultant's own alternative would ({owner.registered_name},
 * {owner.registration_number}, site.pages, {site.legal_disclaimer}), so the filled content is
 * resolved and compliance-scanned exactly like everything else - there is no privileged path.
 *
 * A body is authored as ordinary PML, exactly like a std-library component template
 * ({@see StandardLibrary}); it must be a single root element of the SAME primitive it defines (the
 * fill pass rejects a mismatch). A nested self-sustaining tag inside a body - the bare <nav/> in
 * the header - is filled by the same recursive pass, so a body is DRY (it never re-states another
 * primitive's default).
 */
class PrimitiveDefaultBody
{
    /** The primitives that ship a default body (data, not code: the fill pass never names one). */
    public const PRIMITIVES = ['header', 'nav', 'footer'];

    /**
     * The default-body PML source for a primitive, or null when the primitive does not self-sustain.
     */
    public static function source(string $primitive): ?string
    {
        return match ($primitive) {
            'header' => self::HEADER,
            'nav' => self::NAV,
            'footer' => self::FOOTER,
            default => null,
        };
    }

    /**
     * The default <header>: the register-bound licensee identity beside the page nav, as a sticky
     * top bar. The nav is a bare <nav/> filled by the same pass (see the class docblock).
     */
    private const HEADER = <<<'PML'
    <header direction="row" gap="md" padding="compact" background="surface" motion="sticky">
      <box gap="sm">
        <heading text={owner.registered_name} level="2" heading-size="sm"/>
        <text value={owner.registration_number} body-size="sm"/>
      </box>
      <nav/>
    </header>
    PML;

    /** The default <nav>: one link per PUBLISHED page (each={p in site.pages}, a PSL native read on every visit). */
    private const NAV = <<<'PML'
    <nav direction="row" gap="sm">
      <button each={p in site.pages} label={p.title} href={p.url} variant="link"/>
    </nav>
    PML;

    /**
     * The default <footer>: the identity line, the "Licensed by the College" line, the public-register
     * verify link, and the mandated no-guarantee disclaimer ({site.legal_disclaimer}).
     */
    private const FOOTER = <<<'PML'
    <footer direction="column" gap="md" padding="normal" background="surface">
      <heading text={owner.registered_name} level="3" heading-size="sm"/>
      <text value={owner.registration_number} body-size="sm"/>
      <text value="Licensed by the College of Immigration and Citizenship Consultants." body-size="sm"/>
      <button label="Verify this licence on the CICC public register" href="https://register.college-ic.ca" variant="outline"/>
      <text value={site.legal_disclaimer} body-size="sm"/>
    </footer>
    PML;
}
