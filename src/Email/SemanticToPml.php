<?php

namespace Shirahcan\Pml\Email;

use Shirahcan\Pml\Html\HtmlToPml;

/**
 * A semantic email body (the WYSIWYG editor's vocabulary and its tokens) as a PML email design (pml-email B2a). The
 * same for every product that authored emails that way. It loses no words, variables or links (SemanticParity proves
 * it per body); where styling has no PML home its words are kept and the change is listed in `notes`, and an element
 * it cannot place at all goes to `unconverted`, so the caller keeps the old body for that email.
 *
 * Since pml-kit 0.24.0 it is {@see HtmlToPml} in its EMAIL profile plus the email body's tokens
 * ({@see EmailBodyTokens}): the generic core is shared with written pages, and the output is unchanged.
 */
final class SemanticToPml
{
    /** @return array{nodes: list<array<string, mixed>>, notes: list<string>, unconverted: list<string>} */
    public function convert(string $html): array
    {
        return (new HtmlToPml(new EmailBodyTokens()))->convert($html, HtmlToPml::EMAIL);
    }
}
