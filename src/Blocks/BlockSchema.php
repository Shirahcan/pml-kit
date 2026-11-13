<?php

namespace Shirahcan\Pml\Blocks;

use Shirahcan\Pml\ElementCatalog;

/**
 * The security boundary for consultant-authored content.
 *
 * A consultant's page renders on portify.shirah.co: the SAME ORIGIN as the authenticated
 * app. A stored XSS here does not deface a marketing page, it steals the session of every
 * client, consultant, and admin who visits, and one compromised BYO account becomes every
 * account. So this is not a validation nicety; it is the thing standing between a
 * marketing feature and a platform-wide breach.
 *
 * The rules, and why each one is absolute:
 *
 *  1. NO RAW-HTML BLOCK EXISTS. Not now, not later as a "power user" feature. The moment
 *     one exists, everything below is theatre.
 *  2. Rich text is a CONSTRAINED AST (Tiptap-shaped). An unknown node type is REJECTED,
 *     not stripped-and-accepted: a strip-and-accept sanitizer quietly teaches an attacker
 *     what passes, and silently mangles honest content.
 *  3. EVERY url is validated to http/https, on save AND again at render. `javascript:`,
 *     `data:`, `vbscript:`, and protocol-relative `//evil.com` are all rejected.
 *  4. Nothing here ever produces markup. The renderer walks the AST and emits elements.
 */
class BlockSchema
{
    /**
     * Every block type the system knows. The frontend registry imports the same list
     * (exported alongside the reserved handles), so a block cannot exist on one side only.
     *
     * @var list<string>
     */
    public const BLOCK_TYPES = [
        // content
        'hero', 'about', 'rich_text', 'image_text', 'process_timeline', 'faq',
        'stats', 'team', 'trust_bar', 'cta_banner', 'contact_form', 'office_map',
        'social_links', 'testimonials',
        // structure (frontend LayoutBlocks.tsx, GridBlock.tsx, TableBlock.tsx)
        'button_row', 'callout', 'divider', 'image', 'grid', 'table',
        // lists (frontend ListBlocks.tsx); all repeat-capable (SitePlaceholderResolver)
        'bullet_list', 'numbered_list', 'checklist', 'icon_list',
        // modules
        'packages', 'porter_intake', 'credentials',
    ];

    /**
     * Container blocks and the config key that holds their children (mirrors the
     * frontend blockTree.ts CONTAINER_TYPES). A cell may hold ANY block type — module
     * blocks included, because SiteSnapshotBuilder resolves recursively and the
     * compliance scanner walks containers. The one structural rule left is depth:
     * container CHAINS are capped so a hostile or accidental hundred-deep page cannot
     * be stored, validated, or rendered.
     *
     * @var array<string, string>
     */
    public const CONTAINER_TYPES = ['grid' => 'items'];

    public const MAX_CONTAINER_DEPTH = 4;

    /** Node types permitted inside a rich-text document. Nothing else may appear. */
    private const ALLOWED_NODES = [
        'doc', 'paragraph', 'heading', 'text', 'hardBreak',
        'bulletList', 'orderedList', 'listItem', 'blockquote',
        // PML v2 S1-05: a code block (a Markdown fence). Plain text only: never highlighted by running
        // author input, never marked up; the renderer escapes it like any other text.
        'codeBlock',
        // PML v3 L4: a `{...}` inside rich text, compiled from the Markdown (never authored as a node).
        // It carries a PSL expression the browser evaluates; the server never runs it.
        'expr',
    ];

    /**
     * The languages a code block may name (a label only; nothing is executed or highlighted). One list, in
     * resources/pml/elements.json (`code_language`), shared with PML's `pre` and the frontend.
     *
     * @return list<string>
     */
    public static function codeLanguages(): array
    {
        return ElementCatalog::enum('code_language');
    }

    /** Marks permitted on a text node. Note the absence of anything that carries script. */
    private const ALLOWED_MARKS = ['bold', 'italic', 'underline', 'link', 'code'];

    /** Attributes permitted per node/mark. Anything else (onclick, style, ...) is rejected. */
    private const ALLOWED_ATTRS = [
        'heading' => ['level'],
        'link' => ['href', 'target'],
        'orderedList' => ['start'],
        'codeBlock' => ['language'],
        // `kind` (component props p10): `rich_text` when the value is a rich-text setting, drawn formatted.
        'expr' => ['expr', 'ast', 'kind'],
    ];

    /** Config keys whose values are URLs and must be scheme-checked. */
    private const URL_KEYS = ['href', 'url', 'link', 'booking_url', 'image_url', 'canonical'];

    /**
     * Validate one block. Throws on anything it does not positively recognise.
     *
     * @param  array<string, mixed>  $block
     *
     * @throws InvalidBlockException
     */
    public function validate(array $block, int $containerDepth = 0): bool
    {
        $type = $block['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::BLOCK_TYPES, true)) {
            throw InvalidBlockException::unknownBlockType((string) $type);
        }

        $config = $block['config'] ?? [];

        if (! is_array($config)) {
            throw new InvalidBlockException('Block config must be an object.', 'config');
        }

        if (isset(self::CONTAINER_TYPES[$type])) {
            if ($containerDepth + 1 > self::MAX_CONTAINER_DEPTH) {
                throw new InvalidBlockException(
                    'Sections can only nest '.self::MAX_CONTAINER_DEPTH.' levels deep.',
                    'type',
                );
            }

            $childrenKey = self::CONTAINER_TYPES[$type];
            $this->validateContainerItems(
                $config[$childrenKey] ?? [],
                "config.{$childrenKey}",
                $containerDepth + 1,
            );
        }

        // Recursion below also covers nested cell configs: a `doc` or a URL key is
        // validated at ANY depth, so a cell gets the same gate as a top-level block.
        $this->validateConfig($config, 'config');

        return true;
    }

    /**
     * A container's children are FULL BLOCKS, validated exactly like top-level ones —
     * any type, recursively, with the container-chain depth threaded through.
     *
     * @throws InvalidBlockException
     */
    private function validateContainerItems(mixed $items, string $path, int $containerDepth): void
    {
        if (! is_array($items)) {
            throw new InvalidBlockException('Container cells must be a list.', $path);
        }

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidBlockException('Each cell must be an object.', "{$path}.{$index}");
            }

            try {
                $this->validate($item, $containerDepth);
            } catch (InvalidBlockException $e) {
                throw new InvalidBlockException($e->getMessage(), "{$path}.{$index}.".$e->path);
            }
        }
    }

    /**
     * @param  list<array<string, mixed>>  $blocks
     *
     * @throws InvalidBlockException
     */
    public function validateAll(array $blocks): bool
    {
        foreach ($blocks as $index => $block) {
            if (! is_array($block)) {
                throw new InvalidBlockException('Each block must be an object.', "blocks.{$index}");
            }

            try {
                $this->validate($block);
            } catch (InvalidBlockException $e) {
                throw new InvalidBlockException($e->getMessage(), "blocks.{$index}." . $e->path);
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws InvalidBlockException
     */
    private function validateConfig(array $config, string $path): void
    {
        foreach ($config as $key => $value) {
            $childPath = "{$path}.{$key}";

            // A rich-text document, wherever it appears (a body, an FAQ answer, a bio).
            if ($key === 'doc' && is_array($value)) {
                $this->validateDocument($value, $childPath);

                continue;
            }

            if (is_string($value) && $this->isUrlKey($key)) {
                $this->assertSafeUrl($value, $childPath);

                continue;
            }

            if (is_array($value)) {
                $this->validateConfig($value, $childPath);
            }
        }
    }

    /**
     * Walk a rich-text AST. Reject anything not on the allowlist, at any depth.
     *
     * @param  array<string, mixed>  $node
     *
     * @throws InvalidBlockException
     */
    private function validateDocument(array $node, string $path): void
    {
        $type = $node['type'] ?? null;

        if (! is_string($type) || ! in_array($type, self::ALLOWED_NODES, true)) {
            throw InvalidBlockException::unknownNode((string) $type, $path);
        }

        $this->assertAllowedAttrs($type, $node['attrs'] ?? [], $path);

        if ($type === 'codeBlock') {
            $this->assertCodeBlock($node, $path);
        }

        if ($type === 'expr' && (! is_string($node['attrs']['expr'] ?? null) || ! is_array($node['attrs']['ast'] ?? null) || isset($node['content'])
            || (array_key_exists('kind', $node['attrs']) && $node['attrs']['kind'] !== 'rich_text'))) {
            throw new InvalidBlockException('A rich-text expression holds its PSL source and compiled form only.', "{$path}.attrs");
        }

        foreach ($node['marks'] ?? [] as $i => $mark) {
            if (! is_array($mark)) {
                throw InvalidBlockException::unknownNode('mark', "{$path}.marks.{$i}");
            }

            $markType = $mark['type'] ?? null;

            if (! is_string($markType) || ! in_array($markType, self::ALLOWED_MARKS, true)) {
                throw InvalidBlockException::unknownNode((string) $markType, "{$path}.marks.{$i}");
            }

            $this->assertAllowedAttrs($markType, $mark['attrs'] ?? [], "{$path}.marks.{$i}");

            if ($markType === 'link') {
                $href = $mark['attrs']['href'] ?? '';
                $this->assertSafeUrl(is_string($href) ? $href : '', "{$path}.marks.{$i}.attrs.href");
            }
        }

        foreach ($node['content'] ?? [] as $i => $child) {
            if (! is_array($child)) {
                throw InvalidBlockException::unknownNode('child', "{$path}.content.{$i}");
            }

            $this->validateDocument($child, "{$path}.content.{$i}");
        }
    }

    /**
     * A code block (S1-05) names a language from the closed list (or none) and holds plain text only.
     *
     * @param  array<string, mixed>  $node
     *
     * @throws InvalidBlockException
     */
    private function assertCodeBlock(array $node, string $path): void
    {
        $language = $node['attrs']['language'] ?? null;

        if ($language !== null && ! in_array($language, self::codeLanguages(), true)) {
            throw new InvalidBlockException(
                'A code block names one of these languages, or none: '.implode(', ', self::codeLanguages()).'.',
                "{$path}.attrs.language",
            );
        }

        foreach ($node['content'] ?? [] as $i => $child) {
            if (! is_array($child) || ($child['type'] ?? null) !== 'text' || ! empty($child['marks'])) {
                throw new InvalidBlockException('A code block holds plain text only.', "{$path}.content.{$i}");
            }
        }
    }

    /**
     * @param  mixed  $attrs
     *
     * @throws InvalidBlockException
     */
    private function assertAllowedAttrs(string $type, mixed $attrs, string $path): void
    {
        if ($attrs === [] || $attrs === null) {
            return;
        }

        if (! is_array($attrs)) {
            throw new InvalidBlockException('Attributes must be an object.', "{$path}.attrs");
        }

        $allowed = self::ALLOWED_ATTRS[$type] ?? [];

        foreach (array_keys($attrs) as $attr) {
            if (! in_array((string) $attr, $allowed, true)) {
                // This is where `onclick`, `style`, `srcdoc`, and friends die.
                throw new InvalidBlockException(
                    "The attribute \"{$attr}\" is not allowed on \"{$type}\".",
                    "{$path}.attrs.{$attr}",
                );
            }
        }
    }

    /**
     * The single most important function in this file.
     *
     * @throws InvalidBlockException
     */
    public function assertSafeUrl(string $url, string $path): void
    {
        $trimmed = trim($url);

        if ($trimmed === '') {
            return; // an empty link is not a threat; the UI validates required-ness
        }

        // A URL that IS a placeholder ("@[$item.url]") cannot be scheme-checked until
        // it resolves. Deferring is safe: an '@'-led string has no scheme, so a browser
        // treats the raw draft value as a relative path, and SitePlaceholderResolver
        // re-runs this exact check on the RESOLVED value before anything publishes.
        // A dangerous scheme needs position 0, so "javascript:...@[x]" still dies here.
        if (str_starts_with($trimmed, '@')) {
            return;
        }

        // Internal links are fine and common ("/jane-doe/packages/study-permit").
        if (str_starts_with($trimmed, '/') && ! str_starts_with($trimmed, '//')) {
            return;
        }

        // A same-page anchor ("#packages") has no scheme and cannot leave the page.
        // The hero default and Porter's cta_banner drafts both point at "#packages";
        // rejecting it silently dropped every drafted call-to-action (found 2026-07-15).
        if (str_starts_with($trimmed, '#')) {
            return;
        }

        // "//evil.com" inherits the current scheme and is a real bypass.
        if (str_starts_with($trimmed, '//')) {
            throw InvalidBlockException::unsafeUrl($trimmed, $path);
        }

        // Contact links (owner 2026-11-07, ungated): "Email us" / "Call us" are on nearly every real
        // landing page, and refusing them failed Porter's drafts. Exactly ONE valid address and no
        // headers (no ?bcc= / ?body= a visitor did not write), or a phone number of digits.
        if ($this->isContactLink($trimmed)) {
            return;
        }

        $scheme = parse_url($trimmed, PHP_URL_SCHEME);

        if (! is_string($scheme) || ! in_array(strtolower($scheme), ['http', 'https'], true)) {
            throw InvalidBlockException::unsafeUrl($trimmed, $path);
        }

        if (! is_string(parse_url($trimmed, PHP_URL_HOST))) {
            throw InvalidBlockException::unsafeUrl($trimmed, $path);
        }
    }

    /** `mailto:` one address with no headers, or `tel:` a phone number (digits, +, spaces, dashes, dots, brackets). */
    private function isContactLink(string $url): bool
    {
        if (stripos($url, 'mailto:') === 0) {
            $address = substr($url, 7);

            return ! str_contains($address, '?') && ! str_contains($address, ',')
                && filter_var(rawurldecode($address), FILTER_VALIDATE_EMAIL) !== false;
        }

        if (stripos($url, 'tel:') === 0) {
            $number = substr($url, 4);

            return preg_match('/^\+?[0-9 ().-]{3,24}$/', $number) === 1
                && preg_match_all('/[0-9]/', $number) >= 3;
        }

        return false;
    }

    /**
     * Deep-validate a rich-text document AST from OUTSIDE the block flow (the PML compiler
     * calls this), reusing the ONE constrained-AST walker rather than a second copy. Purely
     * additive: it delegates to the existing private walker and changes no block behaviour.
     *
     * @param  array<string, mixed>  $doc
     *
     * @throws InvalidBlockException
     */
    public function assertSafeDocument(array $doc, string $path = 'doc'): void
    {
        $this->validateDocument($doc, $path);
    }

    public function isUrlKey(int|string $key): bool
    {
        $key = mb_strtolower((string) $key);

        if (in_array($key, self::URL_KEYS, true)) {
            return true;
        }

        return str_ends_with($key, '_url') || str_ends_with($key, '_href');
    }
}
