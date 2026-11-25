<?php

namespace Shirahcan\Pml\Component;

use InvalidArgumentException;

/**
 * A component's settings (its props) refused, with WHERE (the field key a form shows it under, e.g. `params.0.name`)
 * and the sentence a person reads. The kit's own exception, so a host turns it into its framework's validation error
 * (Portify: `ValidationException::withMessages([field => sentence])`) and the 422 shape stays exactly as it was.
 */
final class ParamRefused extends InvalidArgumentException
{
    public function __construct(public readonly string $field, string $sentence)
    {
        parent::__construct($sentence);
    }

    /** As Laravel's `withMessages([$field => $sentence])` reads, so a moved check keeps its line. */
    public static function at(string $field, string $sentence): self
    {
        return new self($field, $sentence);
    }
}
