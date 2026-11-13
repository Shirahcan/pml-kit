# shirahcan/pml

The server side of PML and PSL, the page language every Shirahcan product shares (shared program, phase E2).

- `Shirahcan\Pml\*`: element definitions (`resources/pml/elements.json`, the ONE definition), serialiser, rich text, schema helpers.
- `Shirahcan\Pml\Psl\*`: PSL lexer, parser and analyzer (`resources/psl/stdlib/manifest.json`).
- `Shirahcan\Pml\Blocks\*`: `BlockSchema`, the security boundary for author content.

The browser side is `@shirahcan/pml` (repo `pml-ui`). A change to `elements.json` is exported to it with Portify's `php artisan pml:export-elements`, then both are tagged.

## Social cards (`Shirahcan\Pml\Card\*`)

A social card is an ordinary PML node list limited to the card subset (`CardFrame::ELEMENTS`), drawn by `PmlCard` from `@shirahcan/pml` in a fixed 1200x630 frame. There is no card element in the grammar. No AI runs in any of these classes.

| Class | What it does |
|---|---|
| `CardFacts` | The facts a product hands in: title, subtitle, kicker, picture, logo, price, pinned identity, alt. Read from records, never written. |
| `DefaultCardComposer` | The automatic card: picks a starter (`resources/cards/{headline,photo,offer}.json`) from the facts. |
| `CardChecks` | Findings for a designed card: text too small to read in a feed, too many blocks, too many words. |
| `CardPolicy` / `DefaultCardPolicy` | The product's rules: pinned identity, the compliance scan, the byte budget, own pictures. |
| `CardGuard` | Drops what a card cannot hold, restores a pinned identity line, falls back to the automatic card when the scan refuses words, and reports `refused` when the automatic card's own words are refused too. |
| `CardPublisher` | Guards, hashes, draws through the product's `CardRenderer` and stores through its `CardImageStore`. Unchanged = nothing drawn; a failed draw keeps the last good picture (`pending` when retryable, `failed` with the reason otherwise). Refused words are never drawn (`refused`: the product's metadata must stop using the old picture). `needsDrawing()` is a backfill's dry run. |

The product owns the queued job (retries, `report()`), the backfill command, the signed card route the renderer opens, and the storage. Portify wraps the kit's `AiRender::snapshot()` as its `CardRenderer`.
