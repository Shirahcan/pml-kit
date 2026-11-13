<?php

namespace Shirahcan\Pml\Compliance;

use Shirahcan\Pml\Blocks\BlockSchema;
use Shirahcan\Pml\Compliance\Rules\ComplianceRule;
use Shirahcan\Pml\Compliance\Rules\GovernmentAffiliationRule;
use Shirahcan\Pml\Compliance\Rules\LicenseeIdentityRequiredRule;
use Shirahcan\Pml\Compliance\Rules\OutcomeGuaranteeRule;
use Shirahcan\Pml\Compliance\Rules\TestimonialAttestationRule;

/**
 * The ONE pack of deterministic rules every piece of public-facing copy passes through (shirahcan/pml 0.6.0, shared
 * program E2g; moved from Portify's ComplianceScanner). Portify's pages, Growth's posts, landing pages and campaigns
 * all read this pack, so a claim a page may not make is a claim a newsletter may not make either: one list, never a
 * second copy that drifts.
 *
 * Hosting a licensee's public copy means hosting REGULATED ADVERTISING. A page that guarantees an approval is a
 * breach of the CICC Code (s.44(2)) that the licensee is accountable for and the platform published for them.
 *
 * DETERMINISTIC on purpose: authoritative for the hard blocks, repeatable, testable, and unaffected by an AI
 * provider having a bad day. A product may ADD an advisory AI pass on top (Portify's PorterComplianceAdvisor); it may
 * never downgrade a block.
 */
class ComplianceRulePack
{
    /** @var list<ComplianceRule> */
    private array $textRules;

    public function __construct(
        private readonly TestimonialAttestationRule $testimonials = new TestimonialAttestationRule(),
        private readonly LicenseeIdentityRequiredRule $identity = new LicenseeIdentityRequiredRule(),
    ) {
        $this->textRules = [
            new OutcomeGuaranteeRule(),
            new GovernmentAffiliationRule(),
        ];
    }

    /**
     * The structural s.45 check for a set of baked testimonial items (each `{quote, attestation}`): an item whose
     * quote is non-empty but carries no real written-approval attestation is a BLOCK.
     *
     * @param  list<array<string, mixed>>  $items
     */
    public function scanTestimonials(array $items): ComplianceResult
    {
        return new ComplianceResult($this->testimonials->checkBlock(['items' => $items]));
    }

    /**
     * The s.44 required-elements check for a whole resolved page: the licensee identity (registered name and
     * registration number) and the mandated no-guarantee notice must all be present in the page's prose. Absence is a
     * BLOCK: a page that would advertise a licensee without their registered identity does not go public.
     */
    public function scanRequiredIdentity(string $prose, LicenseeIdentity $identity): ComplianceResult
    {
        return new ComplianceResult($this->identity->check($prose, $identity));
    }

    /** Scan a single piece of prose: a post's body, a campaign's subject, a package's description. */
    public function scanText(string $text, ?string $blockType = null, ?string $field = null): ComplianceResult
    {
        $violations = [];

        foreach ($this->textRules as $rule) {
            foreach ($rule->check($text, $blockType, $field) as $violation) {
                $violations[] = $violation;
            }
        }

        return new ComplianceResult($violations);
    }

    /**
     * Scan a whole page: every block, every text field inside it, plus the structural checks (an unattested
     * testimonial is a block regardless of what it says).
     *
     * @param  list<array<string, mixed>>  $blocks  the RESOLVED snapshot, not the draft: what the public will see.
     */
    public function scanBlocks(array $blocks): ComplianceResult
    {
        $result = new ComplianceResult();

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            $result = $result->merge($this->scanBlock($block));
        }

        return $result;
    }

    /**
     * Every human-readable string of a page's blocks, one per line: what an advisory pass reads.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    protected function flattenCopy(array $blocks): string
    {
        $chunks = [];

        foreach ($blocks as $block) {
            if (! is_array($block) || ! is_array($block['config'] ?? null)) {
                continue;
            }

            foreach ($this->extractText($block['config']) as $text) {
                $chunks[] = $text;
            }
        }

        return implode("\n", $chunks);
    }

    /**
     * Scan one block, DESCENDING into containers: a testimonial inside a grid cell is exactly as much on the page as
     * a top-level one, and nesting must never become the bypass. The container's own text is scanned WITHOUT its
     * children key, because each child is scanned as its own block (double-scanning would report every nested
     * violation twice).
     *
     * @param  array<string, mixed>  $block
     */
    private function scanBlock(array $block): ComplianceResult
    {
        $result = new ComplianceResult();

        $type = (string) ($block['type'] ?? 'unknown');
        $config = $block['config'] ?? [];

        if (! is_array($config)) {
            return $result;
        }

        if ($type === 'testimonials') {
            $result = $result->merge(new ComplianceResult($this->testimonials->checkBlock($config)));
        }

        $ownConfig = $config;
        $childrenKey = BlockSchema::CONTAINER_TYPES[$type] ?? null;

        if ($childrenKey !== null) {
            unset($ownConfig[$childrenKey]);

            foreach ($config[$childrenKey] ?? [] as $child) {
                if (is_array($child)) {
                    $result = $result->merge($this->scanBlock($child));
                }
            }
        }

        foreach ($this->extractText($ownConfig) as $field => $text) {
            if (trim($text) === '') {
                continue;
            }

            $result = $result->merge($this->scanText($text, $type, $field));
        }

        return $result;
    }

    /**
     * Pull every human-readable string out of a block config, including nested arrays (FAQ answers, timeline steps,
     * testimonial quotes) and rich-text AST nodes. Deliberately greedy: a rule that only reads the headline is a rule
     * a prohibited claim walks straight past by living in the third bullet of an FAQ answer.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, string>  field path => text
     */
    protected function extractText(array $config, string $prefix = ''): array
    {
        $found = [];

        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_string($value)) {
                // Skip machine values: ids, urls, colours, enum-ish tokens.
                if (self::looksLikeProse($key, $value)) {
                    $found[$path] = $value;
                }

                continue;
            }

            if (is_array($value)) {
                $found = array_merge($found, $this->extractText($value, $path));
            }
        }

        return $found;
    }

    /**
     * Whether a value is words a reader sees, not a machine value (an id, a url, a colour, a layout token). Also used
     * for the words inside a page's `{...}` values by the compiler.
     */
    public static function looksLikeProse(int|string $key, string $value): bool
    {
        $key = mb_strtolower((string) $key);

        $machineKeys = ['id', 'href', 'url', 'src', 'color', 'colour', 'type', 'icon',
            'slug', 'align', 'variant', 'position', 'image', 'photo', 'media_id',
            // Section-style tokens: "spacious" or "accent" is a layout choice, not a claim a reader sees as prose.
            // `_size` also covers heading_size / body_size; `kind` is the divider block's line/space switch.
            'padding', 'background', 'width', 'size', 'tone', 'kind',
            // A repeater's `source` is an expression, machine text. What it PRODUCES is substituted into prose fields
            // before the scan and IS scanned there.
            'source'];

        foreach ($machineKeys as $machineKey) {
            if ($key === $machineKey || str_ends_with($key, "_{$machineKey}")) {
                return false;
            }
        }

        // Anything that is plainly a URL, a path, an anchor, or a data URI is a machine value, not a claim a reader
        // will ever see as prose. (Delimiter is ~, NOT #: a # inside the pattern would close a #-delimited regex.)
        if (preg_match('~^(https?://|/|\#|data:)~i', trim($value))) {
            return false;
        }

        return mb_strlen(trim($value)) > 2;
    }
}
