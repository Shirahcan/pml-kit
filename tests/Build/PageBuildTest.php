<?php

namespace Shirahcan\Pml\Tests\Build;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\Build\PageBuild;
use Shirahcan\Pml\Build\PageBuildHost;
use Shirahcan\Pml\Build\PageReply;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;

/**
 * One page Build, the same for every product (shared-page-build S2a/S2b): a clean draft lands canonically; markup is
 * mended with no model; ONE repair, then the strong rung, then ONE rewrite; every fix failing changes nothing and says
 * why; faint text is fixed in place; ids are not a change; words alone are never a page.
 */
final class PageBuildTest extends TestCase
{
    private const SOURCE = '<box uid="box001ab"><heading uid="head01ab" text="Old title" level="2"/></box>';

    private const NEW = '<box uid="box001ab"><heading uid="head01ab" text="New title" level="2"/></box>';

    /** @param  list<?string>  $answers  what the model says, in order */
    private function host(array $answers): PageBuildHost
    {
        return new class($answers) implements PageBuildHost {
            /** @var list<array{prompt: string, vars: array<string, string>, strong: bool}> */
            public array $asks = [];

            /** @var list<array{what: string, label: ?string}> */
            public array $said = [];

            private PmlParser $parser;

            /** @param  list<?string>  $answers */
            public function __construct(private array $answers)
            {
                $this->parser = new PmlParser(new PmlScanner, new MarkdownToDoc);
            }

            public function ask(string $prompt, array $vars, bool $strong = false): ?string
            {
                $this->asks[] = compact('prompt', 'vars', 'strong');

                return array_shift($this->answers);
            }

            public function place(string $pml, array &$stored): array
            {
                return ['pml' => $pml, 'error' => null];
            }

            public function check(string $pml): array
            {
                try {
                    $parsed = $this->parser->parse($pml);
                } catch (\Throwable $e) {
                    return ['nodes' => [], 'imports' => [], 'error' => $e->getMessage()];
                }

                // The product's save gate: this product refuses a raw link.
                if (str_contains($pml, 'javascript:')) {
                    return ['nodes' => [], 'imports' => [], 'error' => 'A link to javascript: is not allowed (line 1, column 10)'];
                }

                return ['nodes' => array_values($parsed['nodes'] ?? []), 'imports' => $parsed['imports'] ?? [], 'error' => null];
            }

            public function theme(): ?array
            {
                return null;
            }

            public function said(string $what, ?string $label = null): void
            {
                $this->said[] = compact('what', 'label');
            }
        };
    }

    private const BAD = '<box uid="box001ab"><button uid="btn001ab" label="Go" href="javascript:alert(1)"/></box>';

    public function test_a_clean_draft_lands_canonically_with_its_reply_and_no_model_call(): void
    {
        $host = $this->host([]);
        $result = (new PageBuild)->finish(self::SOURCE, self::NEW, 'I renamed the title.', '{}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertTrue($result['changed']);
        $this->assertSame('I renamed the title.', $result['reply']);
        $this->assertNull($result['repaired']);
        $this->assertStringContainsString('text="New title"', (string) $result['source']);
        $this->assertSame([], $host->asks);
    }

    public function test_a_note_the_answer_dropped_is_put_back_on_its_element(): void
    {
        // A template's Purpose note pinned to the heading; the answer rewrote the heading and left the note out (P5-1c,
        // measured 2026-11-25 on a post's opening).
        $source = '<box uid="box001ab"><comment for="head01ab" author="Template">Purpose: the title, from the post fields.</comment><heading uid="head01ab" text="Old title" level="2"/></box>';
        $result = (new PageBuild)->finish($source, self::NEW, null, '{}', $this->host([]));

        $this->assertFalse($result['unavailable']);
        $this->assertTrue($result['changed']);
        $this->assertSame(1, substr_count((string) $result['source'], 'Purpose: the title, from the post fields.'));
        $this->assertMatchesRegularExpression('/<comment[^>]*for="head01ab"[^>]*>Purpose: the title[^<]*<\/comment>\s*<heading text="New title"[^>]*uid="head01ab"/', (string) $result['source']);
    }

    public function test_a_note_the_answer_kept_or_added_is_not_doubled(): void
    {
        $note = '<comment for="head01ab" author="Template">Purpose: the title.</comment>';
        $source = '<box uid="box001ab">'.$note.'<heading uid="head01ab" text="Old title" level="2"/></box>';
        $answer = '<box uid="box001ab">'.$note.'<comment for="head01ab" author="Porter">Check: the title names the year.</comment><heading uid="head01ab" text="New title" level="2"/></box>';

        $result = (new PageBuild)->finish($source, $answer, null, '{}', $this->host([]));

        $this->assertSame(1, substr_count((string) $result['source'], 'Purpose: the title.'));
        $this->assertStringContainsString('Check: the title names the year.', (string) $result['source']);
    }

    public function test_a_note_whose_element_is_gone_goes_to_the_end_to_be_re_pinned(): void
    {
        $source = '<box uid="box001ab"><heading uid="head01ab" text="Old title" level="2"/><comment for="para01ab">Purpose: the intro.</comment><text uid="para01ab">Intro</text></box>';
        $result = (new PageBuild)->finish($source, self::NEW, null, '{}', $this->host([]));

        $this->assertStringContainsString('Purpose: the intro.', (string) $result['source']);
        $this->assertStringEndsWith('</comment>', trim((string) $result['source']));
    }

    public function test_a_stray_closing_tag_is_mended_with_no_model_and_said(): void
    {
        $host = $this->host([]);
        $result = (new PageBuild)->finish(self::SOURCE, self::NEW.'</box>', null, '{}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertSame([], $host->asks);
        $this->assertSame('Fixed the markup of the draft', $host->said[0]['label']);
        $this->assertSame('I changed 1 element.', $result['reply'], 'no reply line: the page itself says what changed');
    }

    public function test_one_repair_fixes_a_refused_draft_and_the_first_words_stay(): void
    {
        $host = $this->host([self::NEW."\n".PageReply::REPLY_MARKER."\nThis fixes the publish error."]);
        $result = (new PageBuild)->finish(self::SOURCE, self::BAD, 'I renamed the title.', '{"instruction":"rename"}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertSame('I renamed the title.', $result['reply'], 'the repair only fixes a detail the person never saw');
        $this->assertStringStartsWith('A link to javascript:', (string) $result['repaired']);
        $this->assertSame([['prompt' => 'edit_repair', 'strong' => false]], array_map(fn ($a) => ['prompt' => $a['prompt'], 'strong' => $a['strong']], $host->asks));
        $this->assertSame(self::BAD, $host->asks[0]['vars']['prior_output']);
        $this->assertSame('{"instruction":"rename"}', $host->asks[0]['vars']['payload']);
    }

    public function test_the_strong_rung_is_tried_when_the_first_repair_fails_too(): void
    {
        $host = $this->host([self::BAD, self::NEW]);
        $result = (new PageBuild)->finish(self::SOURCE, self::BAD, null, '{}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertSame([false, true], array_column($host->asks, 'strong'));
        $this->assertContains('Fixing it again, more carefully', array_column($host->said, 'label'));
    }

    public function test_the_page_is_written_again_once_when_both_repairs_fail(): void
    {
        $host = $this->host([self::BAD, self::BAD, self::NEW."\n".PageReply::REPLY_MARKER."\nI wrote it again."]);
        $result = (new PageBuild)->finish(self::SOURCE, self::BAD, 'First words.', '{"instruction":"rename"}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertSame('I wrote it again.', $result['reply']);
        $this->assertSame('edit', $host->asks[2]['prompt']);
        $this->assertStringContainsString('earlier_draft_fault', $host->asks[2]['vars']['payload']);
        $this->assertStringContainsString('"instruction": "rename"', $host->asks[2]['vars']['payload']);
    }

    public function test_every_fix_failing_changes_nothing_and_says_why(): void
    {
        $host = $this->host([self::BAD, self::BAD, self::BAD]);
        $result = (new PageBuild)->finish(self::SOURCE, self::BAD, null, '{}', $host);

        $this->assertTrue($result['unavailable']);
        $this->assertNull($result['source']);
        $this->assertSame('The draft still had something this page cannot hold (A link to javascript: is not allowed (line 1, column 10)) after it was fixed and written again, so nothing changed.', $result['reason']);
        $this->assertSame('<box uid="box001ab"><button uid="btn001ab" label="Go" href="javascript:alert(1)"/></box>', $result['fault_line']);
        $this->assertCount(3, $host->asks, 'one repair, one strong repair, one rewrite: never a fourth');
    }

    public function test_a_mirror_is_never_written_again(): void
    {
        $host = $this->host([self::BAD, self::BAD]);
        $result = (new PageBuild)->finish(self::SOURCE, self::BAD, null, '{}', $host, retypeForFaint: false);

        $this->assertTrue($result['unavailable']);
        $this->assertCount(2, $host->asks);
    }

    public function test_ids_are_not_a_change_and_a_page_given_back_says_nothing_changed(): void
    {
        $host = $this->host([]);
        $result = (new PageBuild)->finish(self::SOURCE, '<box><heading text="Old title" level="2"/></box>', null, '{}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertFalse($result['changed']);
        $this->assertNull($result['reply']);
        $this->assertMatchesRegularExpression('/uid="[a-z0-9]+"/', (string) $result['source'], 'every element leaves with a permanent id');
    }

    public function test_words_alone_are_never_a_page(): void
    {
        $host = $this->host([self::NEW]);
        $result = (new PageBuild)->finish(self::SOURCE, 'I could not do that.', null, '{}', $host);

        $this->assertSame(PageReply::NO_PAGE_FAULT, $result['repaired']);
        $this->assertFalse($result['unavailable']);
    }

    public function test_faint_text_is_fixed_in_place_with_no_model(): void
    {
        $host = $this->host([]);
        $result = (new PageBuild)->finish(self::SOURCE, '<box uid="box001ab"><text uid="txt001ab" value="Small print" text-color-custom="#94a3b8"/></box>', 'Done.', '{}', $host);

        $this->assertFalse($result['unavailable']);
        $this->assertSame([], $host->asks);
        $this->assertNull($result['repaired']);
        $this->assertStringNotContainsString('#94a3b8', (string) $result['source']);
    }

    public function test_edit_asks_once_then_finishes_and_an_unreachable_model_is_unavailable(): void
    {
        $host = $this->host([self::NEW."\n".PageReply::REPLY_MARKER."\nI renamed it."]);
        $result = (new PageBuild)->edit(self::SOURCE, '{"instruction":"rename"}', $host);

        $this->assertSame('I renamed it.', $result['reply']);
        $this->assertSame([['prompt' => 'edit', 'vars' => ['payload' => '{"instruction":"rename"}'], 'strong' => false]], $host->asks);

        $this->assertSame(['source' => null, 'unavailable' => true], (new PageBuild)->edit(self::SOURCE, '{}', $this->host([null])));
    }

    public function test_the_reply_is_found_even_when_it_comes_first(): void
    {
        $this->assertSame(['pml' => self::NEW, 'reply' => 'I renamed it.'], PageReply::split("I renamed it.\n".PageReply::REPLY_MARKER."\n".self::NEW));
    }

    public function test_a_reply_is_plain_words_and_a_long_one_is_folded_at_a_word(): void
    {
        $this->assertSame('I made the title bold and kept setup.', PageReply::plain('I made the **title** *bold* and kept `setup`.'));
        $this->assertNull(PageReply::plain("  \n "));

        $long = PageReply::plain(str_repeat('word ', 1000));
        $this->assertLessThanOrEqual(4000, mb_strlen((string) $long));
        $this->assertStringEndsWith('word…', (string) $long);
    }
}
