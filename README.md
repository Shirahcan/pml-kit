# shirahcan/pml

The server side of PML and PSL, the page language every Shirahcan product shares (shared program, phase E2).

- `Shirahcan\Pml\*`: element definitions (`resources/pml/elements.json`, the ONE definition), serialiser, rich text, schema helpers.
- `Shirahcan\Pml\Psl\*`: PSL lexer, parser and analyzer (`resources/psl/stdlib/manifest.json`).
- `Shirahcan\Pml\Blocks\*`: `BlockSchema`, the security boundary for author content.

## Compile, snapshot, diagnose (0.8.0, 0.9.0)

| Class | What it does |
|---|---|
| `PmlCompiler` | Expands components, validates every primitive, scans the visible words with a `Compliance\ComplianceRulePack`. |
| `PmlSnapshotBuilder` | The publish/preview path: compile, bake the owner's module data (packages, credentials, testimonials), scan the RESOLVED page, and check the licensee identity when one is given. No model call: a product adds its own advisory (AI) pass by extending it. |
| `PmlDiagnoseService` | The Code view's located problems (syntax, PSL, vocabulary, advice). Never throws. A product's own components reach it only as the `handle => expander` map it is handed. |
| `PmlStreamPrefix` | The finished part of a page a model is still writing, as nodes, plus the element being written. The product splits its own reply format first and hands in the page part. |

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

Designing a card (0.10.0, shared program E4c), the same for every product:

| Class | What it does |
|---|---|
| `CardDesign` | Whether a design may be stored: only the card subset is kept; nothing to show, or far beyond a card's size, is refused in plain words. Storing is not trusting: `CardGuard` still runs on every read and publish. |
| `CardSpec` | The card language a Build is taught (the `{{spec}}` of ai-service's `shared.pml.card`), derived from `CardFrame::ELEMENTS`, the element catalogue and `PrimitiveSchema`, never restated. |
| `CardBuild` | One console Build: card as PML to the model (the product's `$ask` callable), the answer read back (card, reply, `Issue:`/`Gap:`/`Suggestion:` notes), kept to the subset, checked, ONE repair, guarded. A refusal changes nothing and says why. |
| `CardDocView` | The card document a builder loads: what will be drawn, the person's choice, and why anything was dropped, restored or refused; a refused design is handed back (`design`) so the canvas reopens it. |

The product owns the queued job (retries, `report()`), the backfill command, the signed card route the renderer opens, and the storage. Portify wraps the kit's `AiRender::snapshot()` as its `CardRenderer`.
