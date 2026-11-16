<?php

namespace Shirahcan\Pml\Email;

/**
 * The product's port for an email's variables (pml-email A2): which `{{names}}` this template may use. The product
 * knows its templates' declared variables and the ones every email gets (its name, the year, its support address);
 * the profile never guesses them.
 */
interface EmailVariables
{
    /** @return list<string> the template's own declared variables */
    public function declared(): array;

    /** @return list<string> variables every email of this product may use */
    public function alwaysAvailable(): array;
}
