<?php

namespace Shirahcan\Pml\Card;

use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Throwable;

/**
 * One console Build on a share card (shared program E4c-2b), the same for every product: the card goes to the model
 * as PML, the answer is read back, kept to the card subset, checked, given ONE repair when a check refuses it, and
 * guarded. The product supplies only the model call (ai-service's `shared.pml.card` with its own key), its facts and
 * its policy, and stores the result.
 *
 * ⚠ THE MODEL NEVER DECIDES WHAT IS STORED. Its answer is a proposal: what a card cannot hold is removed, a design with
 * nothing to show or far too large is refused, and {@see CardGuard} puts a removed pin back and keeps refused words off
 * the picture. A refusal changes nothing and says why.
 */
final class CardBuild
{
    /** The line the prompt puts between the card and the reply (ai-service `shared.pml.card`). */
    public const REPLY_MARK = '=== PORTER REPLY ===';

    /** The finding lines the prompt allows after the reply, in the console only. */
    public const NOTE_PREFIXES = ['Issue:', 'Gap:', 'Suggestion:'];

    public const UNREADABLE = 'The card that came back could not be read, so nothing changed.';

    public const NO_CARD = 'The answer was only words, with no card before the reply line, so nothing changed. Write the card source first, then the reply line, then the words.';

    private readonly PmlParser $parser;

    private readonly PmlSerializer $serializer;

    public function __construct(
        ?PmlParser $parser = null,
        ?PmlSerializer $serializer = null,
        private readonly CardGuard $guard = new CardGuard,
    ) {
        $this->parser = $parser ?? new PmlParser(new PmlScanner, new MarkdownToDoc);
        $this->serializer = $serializer ?? new PmlSerializer(new DocToMarkdown);
    }

    /** The card as the model reads it. */
    public function source(array $nodes): string
    {
        return $this->serializer->serialize(['nodes' => array_values($nodes)]);
    }

    /**
     * @param  list<array<string, mixed>>  $current  the card as it is now (the guarded nodes the builder shows)
     * @param  callable(string, list<string>): string  $ask  the model call: (card source, problems to fix) => raw answer
     * @return array{changed: bool, refused: bool, reason: ?string, nodes: list<array<string, mixed>>, reply: string, notes: list<string>, checks: list<array{code: string, at: string, text: string}>, dropped: list<string>, restored_identity: bool, fell_back: bool, repaired: bool}
     */
    public function run(array $current, CardFacts $facts, CardPolicy $policy, callable $ask): array
    {
        $source = $this->source($current);
        $attempt = $this->attempt((string) $ask($source, []));
        $repaired = false;

        if (($problems = $this->problems($attempt)) !== []) {
            // ONE repair: the problems go back with the attempt (or the card as it was, when the attempt was unreadable).
            $repaired = true;
            $attempt = $this->attempt((string) $ask($attempt['nodes'] !== [] ? $this->source($attempt['nodes']) : $source, $problems));
        }

        if ($attempt['error'] !== null) {
            return $this->result(false, true, $attempt['error'], $current, $attempt, [], false, false, $repaired);
        }

        $guarded = $this->guard->guard($attempt['nodes'], $facts, $policy);
        $changed = self::canonical($attempt['nodes']) !== self::canonical($current);

        return $this->result($changed, false, $guarded['fell_back'] ? $guarded['reason'] : null, $attempt['nodes'], $attempt, $guarded['findings'], $guarded['restored_identity'], $guarded['fell_back'], $repaired);
    }

    /**
     * The model's answer, read: the card (kept to the subset), the reply sentences and the finding lines.
     *
     * @return array{nodes: list<array<string, mixed>>, error: ?string, dropped: list<string>, reply: string, notes: list<string>}
     */
    private function attempt(string $answer): array
    {
        [$card, $after] = array_pad(explode(self::REPLY_MARK, str_replace("\r\n", "\n", $answer), 2), 2, '');
        $card = trim((string) preg_replace('/\A\s*```[a-z]*\s*|\s*```\s*\z/i', '', $card));

        $reply = [];
        $notes = [];
        foreach (preg_split('/\n+/', trim($after)) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (array_filter(self::NOTE_PREFIXES, fn (string $p) => str_starts_with($line, $p)) !== []) {
                $notes[] = $line;
            } else {
                $reply[] = $line;
            }
        }

        // ⚠ WORDS ARE NOT A CARD (2026-11-25, as the email Build): bare words would read as one paragraph and replace
        // the card. A card is always markup, so words alone go back as a problem (the one repair), then change nothing.
        if ($card !== '' && ! \Shirahcan\Pml\Build\PageReply::hasMarkup($card)) {
            return ['nodes' => [], 'error' => self::NO_CARD, 'dropped' => [], 'reply' => implode(' ', $reply), 'notes' => $notes];
        }

        try {
            $nodes = $card === '' ? [] : ($this->parser->parse($card)['nodes'] ?? []);
        } catch (Throwable) {
            return ['nodes' => [], 'error' => self::UNREADABLE, 'dropped' => [], 'reply' => implode(' ', $reply), 'notes' => $notes];
        }

        $accepted = CardDesign::accept($nodes);

        return ['nodes' => $accepted['nodes'], 'error' => $accepted['error'], 'dropped' => $accepted['dropped'], 'reply' => implode(' ', $reply), 'notes' => $notes];
    }

    /**
     * What the one repair is asked to fix: an unreadable or empty card, elements a card cannot hold, and the readability
     * checks (size, blocks, words).
     *
     * @return list<string>
     */
    private function problems(array $attempt): array
    {
        if ($attempt['error'] !== null) {
            return [$attempt['error']];
        }

        return [...$attempt['dropped'], ...array_column(CardChecks::findings($attempt['nodes']), 'text')];
    }

    /** @return array<string, mixed> */
    private function result(bool $changed, bool $refused, ?string $reason, array $nodes, array $attempt, array $checks, bool $restored, bool $fellBack, bool $repaired): array
    {
        return [
            'changed' => $changed,
            'refused' => $refused,
            'reason' => $reason,
            'nodes' => array_values($nodes),
            'reply' => $attempt['reply'],
            'notes' => $attempt['notes'],
            'checks' => $checks,
            'dropped' => $attempt['dropped'],
            'restored_identity' => $restored,
            'fell_back' => $fellBack,
            'repaired' => $repaired,
        ];
    }

    private static function canonical(array $nodes): string
    {
        return (string) json_encode(array_values($nodes), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
