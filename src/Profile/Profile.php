<?php

namespace Shirahcan\Pml\Profile;

/**
 * What a PML document is FOR (shared PML Studio, owner 2026-11-18: "make PML very robust and standard to serve
 * different situations/purposes"): a page, a post, an email, a social card, a component. A purpose is DATA here, never a
 * branch in an editor: the Studio, the save gates and Porter's Build all ask the document's profile what it may hold.
 *
 * A profile only DESCRIBES and CHECKS; it never edits a design (an email refuses, it never silently drops).
 */
interface Profile
{
    /** The profile's key: `page`, `post`, `email`, `card`, `component`. */
    public function key(): string;

    /**
     * The elements a document of this purpose may hold, or null when it may hold the whole element catalog.
     *
     * @return list<string>|null
     */
    public function elements(): ?array;

    /**
     * Everything the design holds that this purpose may not, each with where it is (1-based path, "2.1") and a
     * sentence a person reads. Empty when the design is valid for the purpose.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{code: string, at: string, text: string}>
     */
    public function violations(array $nodes): array;

    /**
     * The pieces whose words are the DOCUMENT's own fields (a post's title, its cover), by element: the fields each
     * one edits, when double-click edits one field in place which, and the details an ask aimed at it drafts. Empty when
     * the purpose has none.
     *
     * @return array<string, array{fields: list<string>, quickEdit?: string, drafts?: list<string>}>
     */
    public function pieces(): array;

    /**
     * What a Build writing this purpose is taught about it (shared PML Studio P5-1b), derived from the same data the
     * profile's check reads, so the teaching cannot drift from what is accepted. An email's and a card's is their whole
     * language; a post's is taught beside the host's page language (it names the pieces, the sections a post may hold,
     * and that a website's own sections are not available). Null: the host's own language alone (a website page).
     */
    public function spec(): ?string;
}
