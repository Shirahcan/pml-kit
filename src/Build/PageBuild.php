<?php

namespace Shirahcan\Pml\Build;

use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlContrastCheck;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PmlSyntaxMend;
use Shirahcan\Pml\PmlUids;

/**
 * One console Build on a PAGE, the same for every product (shared-page-build S2a/S2b, 2026-11-23; moved from Portify's
 * `SitePmlComposerService::finishEdit`, where it was built and tuned on real asks from 2026-11-07 on). The model's new
 * page goes through everything a written page goes through: pictures placed, the product's save check, the
 * readability check, the repairs, canonical source, and whether it changed. The product supplies only
 * {@see PageBuildHost} (the model call on `shared.pml.edit*`, where pictures are stored, its save check, its theme, and
 * where progress is said); NOTHING is stored here.
 *
 * ⚠ THE MODEL NEVER DECIDES WHAT IS KEPT. A draft the save check still refuses after every fix changes nothing and says
 * why; a first draft whose only fault was faint text is kept when its repair broke something else.
 */
final class PageBuild
{
    public const REPLY_MARKER = PageReply::REPLY_MARKER;

    private readonly PmlParser $parser;

    private readonly PmlSerializer $serializer;

    private readonly PmlContrastCheck $contrast;

    public function __construct(?PmlParser $parser = null, ?PmlSerializer $serializer = null, ?PmlContrastCheck $contrast = null)
    {
        $this->parser = $parser ?? new PmlParser(new PmlScanner, new MarkdownToDoc);
        $this->serializer = $serializer ?? new PmlSerializer(new DocToMarkdown);
        $this->contrast = $contrast ?? new PmlContrastCheck;
    }

    /**
     * The first ask, then {@see finish()}. For a product that does not stream the draft (a product that streams it
     * calls `shared.pml.edit` itself, splits with {@see PageReply::split()} and calls {@see finish()}).
     *
     * @param  string  $payload  the JSON the prompt reads as `{{payload}}` (the instruction, the current source, facts)
     * @return array{source: ?string, unavailable: bool, reply?: ?string, changed?: bool, repaired?: ?string, reason?: ?string, fault_line?: ?string}
     */
    public function edit(string $source, string $payload, PageBuildHost $host, bool $retypeForFaint = true): array
    {
        $answer = $host->ask('edit', ['payload' => $payload]);

        if ($answer === null) {
            return ['source' => null, 'unavailable' => true];
        }

        ['pml' => $pml, 'reply' => $reply] = PageReply::split($answer);

        return $this->finish($source, $pml, $reply, $payload, $host, $retypeForFaint);
    }

    /**
     * The model's new page source, through everything a written page goes through.
     *
     * @param  string  $source  the page as it is now, before this answer
     * @param  string  $pml  the answer's page part
     * @param  string  $payload  the first ask's payload (a repair and a rewrite resend it)
     * @param  bool  $retypeForFaint  false for a mirror: never re-typed for faint text alone, and never written again
     * @return array{source: ?string, unavailable: bool, reply?: ?string, changed?: bool, repaired?: ?string, reason?: ?string, fault_line?: ?string}
     */
    public function finish(string $source, string $pml, ?string $reply, string $payload, PageBuildHost $host, bool $retypeForFaint = true): array
    {
        // A picture the person wants shown becomes a real image (stored once, even across the repairs), before the
        // save check, which only accepts real addresses.
        $stored = [];
        $attempt = $this->placedEdit($pml, $host, $stored, $source);
        // Text a visitor cannot read is a fault in the model's work too: white text on a light gradient replicated a
        // screenshot's layout and nobody could read it. Only what the model BROUGHT IN counts; a colour the person
        // chose earlier is theirs.
        $before = $this->nodesOf($source);
        $theme = $host->theme();
        $faint = $attempt['error'] === null ? $this->contrast->newFault($attempt['nodes'], $before, $theme) : null;

        // Faint text is first fixed in place, with no model: the one colour that made it faint is dropped or replaced.
        // ⚠ Sending the page back to be re-typed for one grey line lost a mirrored page's last three sections (2026-11-08).
        if ($faint !== null) {
            $lightened = $this->contrast->lighten($attempt['nodes'], $theme);

            if ($this->contrast->newFault($lightened, $before, $theme) === null) {
                $attempt['nodes'] = $lightened;
                $faint = null;
            }
        }

        // Mended without a model (a stray closing tag): said, so the person sees what was fixed.
        if (($attempt['mended'] ?? []) !== []) {
            $host->said(implode('; ', $attempt['mended']), 'Fixed the markup of the draft');
        }

        $first = $attempt;

        // One repair pass: the exact located fault goes back once. Never a loop. A mirror is never re-typed for faint
        // text alone (`$retypeForFaint`): it is said instead.
        $repairedFor = $attempt['error'] ?? ($retypeForFaint ? $faint : null);

        if ($repairedFor !== null) {
            $host->said($repairedFor);

            $repair = $host->ask('edit_repair', ['error' => $repairedFor, 'prior_output' => $pml, 'payload' => $payload]);

            if ($repair !== null) {
                $repaired = PageReply::split($repair);
                // The first attempt's words describe what the person asked for; a repair only fixes a detail they never
                // saw, so its words ("this fixes the publish error") are the fallback.
                $reply ??= $repaired['reply'];
                $attempt = $this->placedEdit($repaired['pml'], $host, $stored, $source);
                $faint = $attempt['error'] === null ? $this->contrast->newFault($attempt['nodes'], $before, $theme) : null;
            }

            // A SECOND RUNG before giving up: the same repair once more on the product's stronger route, given the first
            // repair's own fault. Never a third.
            if ($attempt['error'] !== null && $first['error'] !== null) {
                $secondFor = $attempt['error'];
                $prior = isset($repaired) ? $repaired['pml'] : $pml;

                $host->said($secondFor, 'Fixing it again, more carefully');
                $strong = $host->ask('edit_repair', ['error' => $secondFor, 'prior_output' => $prior, 'payload' => $payload], true);

                if ($strong !== null) {
                    $second = PageReply::split($strong);
                    $reply ??= $second['reply'];
                    $tried = $this->placedEdit($second['pml'], $host, $stored, $source);
                    if ($tried['error'] === null) {
                        $attempt = $tried;
                        $faint = $this->contrast->newFault($attempt['nodes'], $before, $theme);
                    }
                }
            }

            // STILL broken after both repairs: the page is written AGAIN, from the ask, told what went wrong. Once. A
            // mirror is never re-typed (`$retypeForFaint`): its skeleton is the point.
            if ($attempt['error'] !== null && $first['error'] !== null && $retypeForFaint) {
                $host->said($attempt['error'], 'Writing the page again');

                $again = $this->rewrite($host, $payload, $attempt['error']);

                if ($again !== null) {
                    $fresh = PageReply::split($again);
                    $tried = $this->placedEdit($fresh['pml'], $host, $stored, $source);

                    if ($tried['error'] === null && $tried['nodes'] !== []) {
                        $reply = $fresh['reply'] ?? $reply;
                        $attempt = $tried;
                        $faint = $this->contrast->newFault($attempt['nodes'], $before, $theme);
                    }
                }
            }

            // A first draft whose ONLY fault was faint text was a valid page: when the repair broke something else (or
            // never came back), keep the first draft and say what to check.
            if ($attempt['error'] !== null && $first['error'] === null) {
                $attempt = $first;
                $faint = $this->contrast->newFault($first['nodes'], $before, $theme);
            }
        }

        // Still faulty (or empty) after the repairs -> unavailable WITH the reason, so the person sees why and source
        // the page would refuse never lands. A clean result is echoed back CANONICALLY.
        if ($attempt['error'] !== null || $attempt['nodes'] === []) {
            return [
                // The line the check stopped at, so a fault that repeats can be found and taught (the product logs it).
                'fault_line' => $attempt['error'] !== null ? self::lineOf((string) ($attempt['pml'] ?? ''), $attempt['error']) : null,
                'source' => null,
                'unavailable' => true,
                'reason' => $attempt['error'] !== null
                    // The person and the model both read this reason: it names no speaker.
                    ? 'The draft still had something this page cannot hold ('.rtrim($attempt['error'], '.').') after it was fixed and written again, so nothing changed.'
                    : $reply,
            ];
        }

        // Every element leaves with a permanent id: kept ones as the model copied them, new ones fresh. A note the
        // answer dropped is put back where it belongs (P5-1c: one Build on a post's opening lost the template's note
        // for it); a note it kept or added stays as it is.
        $nodes = PmlUids::ensure(DesignNotes::restoreMissing($before, $attempt['nodes']));
        $canonical = $this->serializer->serialize(['imports' => $attempt['imports'], 'nodes' => $nodes]);

        // Did anything change? A refusal ("I left the guarantee out") returns the page as it was, and the box must say
        // that instead of "Done". Ids are not a change.
        $changed = $this->canonical($this->serializer->serialize(['imports' => $attempt['imports'], 'nodes' => PmlUids::strip($nodes)]))
            !== $this->canonical($source, true);

        // The model does not always write its reply line; then the page itself says what changed (no AI).
        $reply ??= $changed ? $this->summarise($before, $nodes) : null;

        // Faint text that survived the repair never blocks the page (it is valid, and the person may want those
        // colours), but the person is told, in the reply they read anyway.
        if ($faint !== null) {
            $reply = trim(($reply ?? '').' One thing to check: '.$faint.' Pick a darker text colour or another background.');
        }

        // `repaired`: what the check refused in the first draft, kept so the cause can be found and taught.
        return ['source' => $canonical, 'unavailable' => false, 'reply' => $reply, 'changed' => $changed, 'repaired' => $repairedFor];
    }

    /**
     * What changed, in plain words, from the page itself (no AI): the reply when the model wrote none. ONE count of
     * what changed: the same element-by-element list a console draws under "Changed N elements" ({@see DesignChanges}).
     *
     * @param  list<array<string, mixed>>  $before
     * @param  list<array<string, mixed>>  $after
     */
    public function summarise(array $before, array $after): string
    {
        $words = DesignChanges::sentence(DesignChanges::between($before, $after));

        // The assistant's own reply when it wrote none, so it says "I".
        return $words === '' ? 'I changed the page.' : 'I '.$words.'.';
    }

    /**
     * Place the attached pictures the model used, mend what needs no model, then run the product's save check.
     *
     * @param  array<int, string>  $stored
     * @return array{nodes: list<array<string, mixed>>, imports: array<int, mixed>, error: ?string, mended?: list<string>, pml?: string}
     */
    private function placedEdit(string $pml, PageBuildHost $host, array &$stored, string $current): array
    {
        // ⚠ WORDS ARE NOT A PAGE (2026-11-09). PML reads bare words as a paragraph, so an answer that is only the model's
        // words passes the check, and saving it REPLACES every element with one sentence. It goes back as a fault
        // instead. The one exception is a page that is itself only words, where words are a legitimate edit.
        if (trim($pml) !== '' && ! PageReply::hasMarkup($pml) && (trim($current) === '' || PageReply::hasMarkup($current))) {
            return ['nodes' => [], 'imports' => [], 'error' => PageReply::NO_PAGE_FAULT];
        }

        $placed = $host->place($pml, $stored);

        if ($placed['error'] !== null) {
            return ['nodes' => [], 'imports' => [], 'error' => $placed['error']];
        }

        // A stray or missing closing tag is mended here, with no model, before any repair is paid for. What was mended
        // is named for the step log.
        $mend = PmlSyntaxMend::mend($placed['pml'], fn (string $source) => $this->parser->parse($source));

        $source = $mend['pml'];
        $parsed = $host->check($source);

        // A prop an element cannot hold (HTML's `id=` becomes `anchor=`), element by element, at most three.
        for ($i = 0; $i < 3 && $parsed['error'] !== null; $i++) {
            $renamed = PmlSyntaxMend::refusedProp($source, $parsed['error']);

            if ($renamed === null) {
                break;
            }

            $source = $renamed['pml'];
            $mend['mended'][] = $renamed['mended'];
            $parsed = $host->check($source);
        }

        return $parsed + ['mended' => $mend['mended'], 'pml' => $source];
    }

    /** The page written again after its draft could not be fixed: the same ask, with what went wrong. */
    private function rewrite(PageBuildHost $host, string $payload, string $fault): ?string
    {
        $decoded = json_decode($payload, true);
        $decoded = is_array($decoded) ? $decoded : [];
        $decoded['earlier_draft_fault'] = 'An earlier draft of this answer could not be read ('.$fault.'), and fixing it did not work. '
            .'Write the whole page again from the instruction. Close every tag you open, in order, and write nothing after the page but the reply line.';

        return $host->ask('edit', ['payload' => json_encode($decoded, JSON_PRETTY_PRINT) ?: '{}']);
    }

    /** The source line a located fault points at ("... (line 3, column 9)"), cut to 300 characters. */
    public static function lineOf(string $pml, string $fault): ?string
    {
        if (preg_match('/\(line (\d+), column \d+\)/', $fault, $m) !== 1) {
            return null;
        }

        $line = explode("\n", $pml)[(int) $m[1] - 1] ?? null;

        return $line === null ? null : mb_substr(trim($line), 0, 300);
    }

    /** @return list<array<string, mixed>> */
    private function nodesOf(string $source): array
    {
        try {
            return array_values($this->parser->parse($source)['nodes'] ?? []);
        } catch (\Throwable) {
            return [];
        }
    }

    /** The page source as the serializer writes it, so "did anything change" compares like with like. */
    private function canonical(string $source, bool $withoutIds = false): string
    {
        try {
            $parsed = $this->parser->parse($source);
        } catch (\Throwable) {
            return $source;
        }

        $nodes = array_values($parsed['nodes'] ?? []);

        return $this->serializer->serialize(['imports' => $parsed['imports'] ?? [], 'nodes' => $withoutIds ? PmlUids::strip($nodes) : $nodes]);
    }
}
