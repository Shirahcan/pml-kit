# shirahcan/pml

The server side of PML and PSL, the page language every Shirahcan product shares (shared program, phase E2).

- `Shirahcan\Pml\*`: element definitions (`resources/pml/elements.json`, the ONE definition), serialiser, rich text, schema helpers.
- `Shirahcan\Pml\Psl\*`: PSL lexer, parser and analyzer (`resources/psl/stdlib/manifest.json`).
- `Shirahcan\Pml\Blocks\*`: `BlockSchema`, the security boundary for author content.

The browser side is `@shirahcan/pml` (repo `pml-ui`). A change to `elements.json` is exported to it with Portify's `php artisan pml:export-elements`, then both are tagged.
