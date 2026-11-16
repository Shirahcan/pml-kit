<?php

namespace Shirahcan\Pml;

use Shirahcan\Pml\ElementCatalog;
use Shirahcan\Pml\PmlSyntaxException;

/**
 * The tag layer of the PML parser: turns PML source into the node AST.
 *
 * PML is tags + Markdown (MDX-inspired). This scanner reads the TAG structure (imports, open
 * and self-closing tags, attributes, nesting) and leaves the Markdown text between tags as raw
 * `{"#text": "..."}` markers. Phase 0b2 parses those markers into the constrained rich-text
 * doc AST; phase 0b3 splices them in. Keeping the two concerns apart is what keeps each small.
 *
 * This scanner is NOT a security boundary: it produces a tree, and PrimitiveSchema/PmlCompiler
 * validate it. But it is deliberately strict (a mismatched or unclosed tag is an error, not a
 * best-effort guess) so the future PML pane can report a precise location.
 */
class PmlScanner
{
    private string $src = '';

    private int $len = 0;

    private int $pos = 0;

    private int $line = 1;

    private int $col = 1;

    private int $nodeCount = 0;

    /**
     * When true, {@see readElement} attaches a `_span` (source start line/col) to every scanned
     * element node, so {@see PmlParser::parseWithSpans} can build a path->line/col sidecar for the
     * Code-view diagnostics. Off by default so the ordinary {@see scan} output stays byte-identical
     * for the parser, the serializer round-trip, and every existing caller.
     */
    private bool $withSpans = false;

    private const MAX_SOURCE = 100_000;

    private const MAX_NODES = 2_000;

    private const MAX_DEPTH = 32;

    /**
     * Raw-text elements: their body is read VERBATIM to the matching close tag, never tag-scanned.
     * A `<script>` holds PSL, whose `<` / `>` / `<=` are operators, so tag-scanning it would break
     * on the first comparison. Mirrors how HTML treats `<script>` / `<style>` as raw-text elements.
     * A studio `<comment>` (D1) is a person's note in plain words, so a `<` typed in it is text too.
     */
    private const RAW_TEXT_TAGS = ['script', 'comment', 'code', 'rich_text', 'pre'];

    /**
     * Where a raw body goes (PML v3 L4): a script's PSL is its `source`, inline code its verbatim `text`
     * (braces and `<` are just characters in code), and rich text its `markdown`, as written.
     */
    private const RAW_BODY_PROP = ['code' => 'text', 'rich_text' => 'markdown', 'pre' => 'text'];

    /**
     * @return array{imports: list<array{name: string, from: string, handle: string}>, nodes: list<array<string, mixed>>}
     *
     * @throws PmlSyntaxException
     */
    public function scan(string $source, bool $withSpans = false): array
    {
        if (strlen($source) > self::MAX_SOURCE) {
            throw new PmlSyntaxException('PML source is too large.', 1, 1);
        }

        $this->src = $source;
        $this->len = strlen($source);
        $this->pos = 0;
        $this->line = 1;
        $this->col = 1;
        $this->nodeCount = 0;
        $this->withSpans = $withSpans;

        $imports = $this->scanImports();
        $nodes = $this->scanContent(0, null);

        return ['imports' => $imports, 'nodes' => $nodes];
    }

    /**
     * @return list<array{name: string, from: string, handle: string}>
     *
     * @throws PmlSyntaxException
     */
    private function scanImports(): array
    {
        $imports = [];
        $this->skipWs();

        while ($this->wordAhead('import')) {
            $this->expect('import');
            $this->skipWs();
            $name = $this->readTagName();
            $this->skipWs();

            if (! $this->wordAhead('from')) {
                throw new PmlSyntaxException('Expected "from" in an import.', $this->line, $this->col);
            }
            $this->expect('from');
            $this->skipWs();

            if ($this->peekChar() !== '"') {
                throw new PmlSyntaxException('Expected a quoted "@site/..." path after "from".', $this->line, $this->col);
            }
            $from = $this->readString();

            if (! str_starts_with($from, '@site/')) {
                throw new PmlSyntaxException('Imports may only reference "@site/<handle>".', $this->line, $this->col);
            }

            $imports[] = ['name' => $name, 'from' => $from, 'handle' => substr($from, strlen('@site/'))];
            $this->skipWs();
        }

        return $imports;
    }

    /**
     * Read content until EOF (top level, $closeTag null) or the matching `</closeTag>`.
     *
     * @return list<array<string, mixed>>
     *
     * @throws PmlSyntaxException
     */
    private function scanContent(int $depth, ?string $closeTag, bool $inline = false): array
    {
        $nodes = [];
        $text = '';

        while (! $this->eof()) {
            // Inside `p` and the inline elements (PML v3 L4) the words are literal text runs, not
            // Markdown: `{...}` is a PSL expression run, and a backslash writes a literal `{`, `}`, `<`
            // or `\`. No fences here; code goes in <code>.
            if ($inline) {
                $c = $this->peekChar();

                if ($c === '\\' && $this->pos + 1 < $this->len && str_contains('{}<\\', $this->src[$this->pos + 1])) {
                    $this->advance();
                    $text .= $this->advance();

                    continue;
                }

                // `{{name}}` is a template variable (an email's {{recipient_name}}): literal words, never an expression.
                if ($c === '{' && ($variable = TemplateVariable::at($this->src, $this->pos)) !== null) {
                    for ($n = strlen($variable); $n > 0; $n--) {
                        $text .= $this->advance();
                    }

                    continue;
                }

                if ($c === '{') {
                    $this->flushRun($nodes, $text);
                    $text = '';
                    [$line, $col] = [$this->line, $this->col];
                    $nodes[] = ['#expr' => $this->readExpression(), '@at' => [$line, $col]];

                    continue;
                }

                if ($c === '}') {
                    throw new PmlSyntaxException('A "}" with no "{" before it: write \\} for a literal brace.', $this->line, $this->col);
                }
            }

            // A Markdown code fence (S1-05) is read VERBATIM up to its closing fence, so code that
            // holds `<` (an HTML snippet, a generic type, "a < b") is never mistaken for a tag. Only
            // at the start of a line, and only when a closing fence exists (else it is plain text).
            if (! $inline && $this->lookahead('```') && $this->atLineStart()) {
                $fenced = $this->readFenceVerbatim();

                if ($fenced !== null) {
                    $text .= $fenced;

                    continue;
                }
            }

            if ($this->peekChar() === '<') {
                // An HTML-style comment `<!-- ... -->` is a non-rendering note. It is read into a
                // `#comment` marker (like `#text`) so it survives to a first-class comment node, and
                // it is the surface the Code view detects to offer comment-driven scaffolding. Checked
                // before the element/close-tag branches because `<!--` also starts with `<`.
                if ($this->lookahead('<!--')) {
                    $this->flushContent($nodes, $text, $inline);
                    $text = '';
                    $nodes[] = $this->readComment();

                    continue;
                }

                if ($this->lookahead('</')) {
                    $this->flushContent($nodes, $text, $inline);
                    $text = '';

                    if ($closeTag === null) {
                        throw new PmlSyntaxException('Unexpected closing tag at the top level.', $this->line, $this->col);
                    }

                    $name = $this->readCloseTag();

                    if ($name !== $closeTag) {
                        throw new PmlSyntaxException("Closing tag </{$name}> does not match <{$closeTag}>.", $this->line, $this->col);
                    }

                    return $inline ? $this->finishRuns($nodes, $closeTag) : $nodes;
                }

                $this->flushContent($nodes, $text, $inline);
                $text = '';
                $nodes[] = $this->readElement($depth);

                continue;
            }

            $text .= $this->advance();
        }

        if ($closeTag !== null) {
            throw new PmlSyntaxException("Unclosed <{$closeTag}>.", $this->line, $this->col);
        }

        $this->flushContent($nodes, $text, $inline);

        return $nodes;
    }

    /**
     * Whitespace in words, the way HTML reads it: any run of spaces and line breaks is one space, and a
     * paragraph's words start and end without one. Runs stay where they are (the parser maps one node per
     * scanned node, which the Code view's line markers rely on); only a run that is left empty goes.
     *
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array<string, mixed>>
     */
    private function finishRuns(array $nodes, string $closeTag): array
    {
        foreach ($nodes as $i => $node) {
            if (array_key_exists('#run', $node)) {
                $nodes[$i]['#run'] = (string) preg_replace('/\s+/u', ' ', (string) $node['#run']);
            }
        }

        // An inline element's own edges are real spaces ("Hi<strong> there</strong>"); a block's are not.
        if (! in_array(str_replace('-', '_', $closeTag), ElementCatalog::inline(), true)) {
            $first = array_key_first($nodes);
            $last = array_key_last($nodes);

            if ($first !== null && array_key_exists('#run', $nodes[$first])) {
                $nodes[$first]['#run'] = ltrim((string) $nodes[$first]['#run']);
            }

            if ($last !== null && array_key_exists('#run', $nodes[$last])) {
                $nodes[$last]['#run'] = rtrim((string) $nodes[$last]['#run']);
            }
        }

        return array_values(array_filter($nodes, fn (array $node) => ! array_key_exists('#run', $node) || $node['#run'] !== ''));
    }

    /**
     * Markdown as written, less the indentation every non-blank line shares, with the blank lines at
     * either end dropped. The serializer indents it back to where it sits.
     */
    public static function dedent(string $text): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $text));

        while ($lines !== [] && trim($lines[0]) === '') {
            array_shift($lines);
        }

        while ($lines !== [] && trim($lines[count($lines) - 1]) === '') {
            array_pop($lines);
        }

        $indent = self::indentWidth($text);

        return implode("\n", array_map(
            fn (string $line) => trim($line) === '' ? '' : substr($line, $indent),
            $lines,
        ));
    }

    /** The indentation every non-blank line of this text shares (what {@see dedent} takes off). */
    public static function indentWidth(string $text): int
    {
        $indent = null;

        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $text)) as $line) {
            if (trim($line) === '') {
                continue;
            }

            $width = strlen($line) - strlen(ltrim($line, " \t"));
            $indent = $indent === null ? $width : min($indent, $width);
        }

        return $indent ?? 0;
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function flushContent(array &$nodes, string $text, bool $inline): void
    {
        $inline ? $this->flushRun($nodes, $text) : $this->flushText($nodes, $text);
    }

    /**
     * A text run inside `p` or an inline element. Whitespace between two inline elements is a real space
     * ("<strong>a</strong> <emphasis>b</emphasis>"), so even a whitespace-only run is kept; the parser
     * collapses whitespace the way HTML does.
     *
     * @param  list<array<string, mixed>>  $nodes
     */
    private function flushRun(array &$nodes, string $text): void
    {
        if ($text !== '') {
            $nodes[] = ['#run' => $text];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $nodes
     */
    private function flushText(array &$nodes, string $text): void
    {
        // Whitespace-only runs between tags are insignificant and dropped. Real Markdown text
        // is kept verbatim as a marker for 0b2 to parse.
        if (trim($text) !== '') {
            $nodes[] = ['#text' => $text];
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PmlSyntaxException
     */
    private function readElement(int $depth): array
    {
        // The element's source start (the '<'), captured before anything is consumed. B3 lifts this
        // into the span sidecar so a diagnostic can point at the exact element.
        $startLine = $this->line;
        $startCol = $this->col;

        if ($depth + 1 > self::MAX_DEPTH) {
            throw new PmlSyntaxException('PML is nested too deeply.', $this->line, $this->col);
        }

        if (++$this->nodeCount > self::MAX_NODES) {
            throw new PmlSyntaxException('PML has too many elements.', $this->line, $this->col);
        }

        $this->expect('<');
        $tag = $this->readTagName();
        [$props, $style] = $this->readAttrs();

        // A studio comment is a note, not an element: it has no look, so every attribute is its own
        // (for, placement, color...). ⚠ The `placement` style (static|sticky|fixed, 2026-11-08) took a
        // comment's `placement=bottom-left` as a style and the note lost where its pin sits.
        if ($tag === 'comment' && $style !== []) {
            $props = [...$props, ...$style];
            $style = [];
        }
        $this->skipWs();

        $selfClose = false;

        if ($this->lookahead('/>')) {
            $this->expect('/>');
            $selfClose = true;
        } elseif ($this->lookahead('>')) {
            $this->expect('>');
        } else {
            throw new PmlSyntaxException("Expected \">\" or \"/>\" to close the <{$tag}> tag.", $this->line, $this->col);
        }

        $astName = str_replace('-', '_', $tag);
        $node = PrimitiveSchema::isPrimitive($astName)
            ? ['primitive' => $astName]
            : ['component' => $astName];

        if ($props !== []) {
            $node['props'] = $props;
        }
        if ($style !== []) {
            $node['style'] = $style;
        }

        // A raw-text element (a <script>) swallows its body verbatim as props.source; it never
        // holds child nodes, so this returns before the generic content scan.
        if (! $selfClose && in_array($astName, self::RAW_TEXT_TAGS, true)) {
            // The line of the first body char (right after '>'), advanced past the blank lines
            // trim() removes, so a <script>'s span points at PSL line 1 - the offset B5 needs to
            // map a PSL diagnostic's script-local line to an absolute editor line.
            $bodyLine = $this->line;
            $raw = $this->readRawTextBody($tag);
            $leadingWs = substr($raw, 0, strlen($raw) - strlen(ltrim($raw)));
            $bodyLine += substr_count($leadingWs, "\n");
            // Rich text keeps its Markdown as written, less the indentation it sits at in the source
            // (re-added on write), so a nested rich-text round-trips unchanged.
            // A code block keeps its own indentation too (only the source's is removed), and so does a
            // script: trim() took the indent off its first line only, so a nested script's later lines came
            // back indented further than its first (2026-11-11).
            $source = in_array($astName, ['rich_text', 'pre', 'script'], true) ? self::dedent($raw) : trim($raw);

            if ($source !== '') {
                $props[self::RAW_BODY_PROP[$astName] ?? 'source'] = $source;
                $node['props'] = $props;
            }

            if ($this->withSpans) {
                // `col` is where the code starts on its lines (past the indent dedent took off), so a PSL
                // finding's column, counted in the dedented code, lands on the right character.
                $node['_span'] = ['line' => $bodyLine, 'col' => 1 + ($astName === 'script' ? self::indentWidth($raw) : 0)];
            }

            return $node;
        }

        if (! $selfClose) {
            // `p` and the inline elements hold words: literal text runs and `{...}` runs (PML v3 L4).
            $children = $this->scanContent($depth + 1, $tag, ElementCatalog::holdsInline($astName));

            if ($children !== []) {
                $node['children'] = $children;
            }
        }

        if ($this->withSpans) {
            $node['_span'] = ['line' => $startLine, 'col' => $startCol];
        }

        return $node;
    }

    /**
     * Read a `<!-- ... -->` comment into a `{'#comment': body}` marker. The body is captured VERBATIM
     * (no trim) so the serializer round-trips it exactly. Counts against the node cap like an element,
     * and attaches a `_span` (at the `<`) only when spans were requested.
     *
     * @return array<string, mixed>
     *
     * @throws PmlSyntaxException
     */
    private function readComment(): array
    {
        $startLine = $this->line;
        $startCol = $this->col;

        if (++$this->nodeCount > self::MAX_NODES) {
            throw new PmlSyntaxException('PML has too many elements.', $this->line, $this->col);
        }

        $this->expect('<!--');
        $start = $this->pos;

        while (! $this->eof()) {
            if ($this->lookahead('-->')) {
                $body = substr($this->src, $start, $this->pos - $start);
                $this->expect('-->');

                $node = ['#comment' => $body];

                if ($this->withSpans) {
                    $node['_span'] = ['line' => $startLine, 'col' => $startCol];
                }

                return $node;
            }

            $this->advance();
        }

        throw new PmlSyntaxException('Unclosed comment (missing "-->").', $startLine, $startCol);
    }

    /**
     * Read a raw-text element's body verbatim (no tag or brace interpretation) up to the matching
     * `</tag>`, which it consumes. The one sequence that cannot appear in the body is the literal
     * close tag itself; a PSL string containing "</script>" is the documented limitation, same as
     * an HTML <script>.
     *
     * @throws PmlSyntaxException
     */
    private function readRawTextBody(string $tag): string
    {
        $start = $this->pos;

        while (! $this->eof()) {
            if ($this->peekChar() === '<' && $this->rawCloseAhead($tag)) {
                $body = substr($this->src, $start, $this->pos - $start);
                $this->readCloseTag();

                return $body;
            }

            $this->advance();
        }

        throw new PmlSyntaxException("Unclosed <{$tag}>.", $this->line, $this->col);
    }

    /** True when the matching `</tag>` (optional inner whitespace) starts at the current position. */
    private function rawCloseAhead(string $tag): bool
    {
        $kebab = str_replace('_', '-', $tag);

        return preg_match(
            '/^<\/\s*'.preg_quote($kebab, '/').'\s*>/i',
            substr($this->src, $this->pos),
        ) === 1;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     *
     * @throws PmlSyntaxException
     */
    private function readAttrs(): array
    {
        $props = [];
        $style = [];
        $styleKeys = PrimitiveSchema::styleKeys();

        while (true) {
            $this->skipWs();
            $c = $this->peekChar();

            if ($c === '' || $c === '>' || $c === '/') {
                break;
            }

            $key = $this->readAttrName();
            $this->skipWs();

            if ($this->peekChar() === '=') {
                $this->advance();
                $this->skipWs();
                $value = $this->readAttrValue($key);
            } else {
                $value = true; // a bare attribute is boolean true
            }

            if (in_array($key, $styleKeys, true)) {
                $style[$key] = $value;
            } else {
                $props[$key] = $value;
            }
        }

        return [$props, $style];
    }

    /**
     * An attribute's value (PML v3 L2, owner 2026-11-10): `"text"` (always a STRING here; the parser gives
     * it the type the element declares, so level="2" is stored as the number 2) or `{...}`. Nothing else:
     * an unquoted value is a syntax error that shows the fix, the way the HTML a person or a model already
     * knows is written in practice. A bare attribute with no `=` is handled by the caller (true).
     *
     * @throws PmlSyntaxException
     */
    private function readAttrValue(string $key): mixed
    {
        $c = $this->peekChar();

        if ($c === '"') {
            return $this->readString();
        }

        if ($c === '{') {
            [$line, $col] = [$this->line, $this->col];

            return ['$expr' => $this->readExpression(), '@at' => [$line, $col]];
        }

        $line = $this->line;
        $col = $this->col;
        $word = $this->readBareword();

        if ($word === '') {
            throw new PmlSyntaxException("Expected a value in double quotes after {$key}=.", $line, $col);
        }

        $name = str_replace('_', '-', $key);

        throw new PmlSyntaxException("Put the value in double quotes: {$name}=\"{$word}\".", $line, $col);
    }

    /**
     * A `{...}` value (PML v3 L3, owner 2026-11-10): the PSL expression between the braces, read
     * verbatim (balanced braces; a brace or quote inside a PSL "string" does not count). The parser
     * compiles it with PSL's own parser, so PML never interprets it.
     *
     * @throws PmlSyntaxException
     */
    private function readExpression(): string
    {
        $line = $this->line;
        $col = $this->col;
        $this->expect('{');
        $start = $this->pos;
        $depth = 1;

        while (! $this->eof()) {
            $c = $this->advance();

            if ($c === '"') {
                while (! $this->eof()) {
                    $d = $this->advance();

                    if ($d === '\\' && ! $this->eof()) {
                        $this->advance();

                        continue;
                    }

                    if ($d === '"') {
                        break;
                    }
                }

                continue;
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;

                if ($depth === 0) {
                    $source = trim(substr($this->src, $start, $this->pos - 1 - $start));

                    if ($source === '') {
                        throw new PmlSyntaxException('Empty braces: write a PSL expression inside {...}, like {owner.name}.', $line, $col);
                    }

                    return $source;
                }
            }
        }

        throw new PmlSyntaxException('A "{" is never closed: end the expression with "}".', $line, $col);
    }

    /**
     * @throws PmlSyntaxException
     */
    private function readCloseTag(): string
    {
        $this->expect('</');
        $this->skipWs();
        $name = $this->readTagName();
        $this->skipWs();
        $this->expect('>');

        return $name;
    }

    /**
     * @throws PmlSyntaxException
     */
    private function readTagName(): string
    {
        $c = $this->peekChar();

        if (! ctype_alpha($c)) {
            throw new PmlSyntaxException('Expected a tag name.', $this->line, $this->col);
        }

        $start = $this->pos;

        while (! $this->eof()) {
            $c = $this->peekChar();

            // Underscore is part of a tag name: half the std-library components (faq_item,
            // contact_form, rich_text, image_text, ...) and the porter_intake module carry one.
            // Without this the scanner reads `faq` and then trips on `_item` (the bug that made
            // every Porter-authored section with an underscore name fail to parse).
            if (ctype_alnum($c) || $c === '-' || $c === '_') {
                $this->advance();

                continue;
            }

            break;
        }

        // Canonicalise to snake_case: PML SOURCE is kebab-case (the markup convention, e.g.
        // <faq-item heading-size=lg>), the internal component/primitive identifiers are snake
        // (faq_item, heading_size). Both forms in source resolve to the one internal name.
        return str_replace('-', '_', substr($this->src, $start, $this->pos - $start));
    }

    /**
     * @throws PmlSyntaxException
     */
    private function readAttrName(): string
    {
        $c = $this->peekChar();

        if (! ctype_alpha($c)) {
            throw new PmlSyntaxException('Expected an attribute name.', $this->line, $this->col);
        }

        $start = $this->pos;

        while (! $this->eof()) {
            $c = $this->peekChar();

            if (ctype_alnum($c) || $c === '-' || $c === '_') {
                $this->advance();

                continue;
            }

            // One breakpoint prefix (S2-01): `md:padding`, `lg:align`. Only directly after md/lg.
            $sofar = substr($this->src, $start, $this->pos - $start);
            if ($c === ':' && ($sofar === 'md' || $sofar === 'lg')) {
                $this->advance();

                continue;
            }

            break;
        }

        // Same canonicalisation as tag names: kebab in source (heading-size), snake internally
        // (heading_size) to match the primitive prop + style-token vocabulary.
        return str_replace('-', '_', substr($this->src, $start, $this->pos - $start));
    }

    private function readBareword(): string
    {
        $start = $this->pos;

        while (! $this->eof()) {
            $c = $this->peekChar();

            if (in_array($c, [' ', "\t", "\r", "\n", '>', '/', '"', '{', '}', '='], true)) {
                break;
            }

            $this->advance();
        }

        return substr($this->src, $start, $this->pos - $start);
    }

    /**
     * @throws PmlSyntaxException
     */
    private function readString(): string
    {
        $this->expect('"');
        $out = '';

        while (! $this->eof()) {
            $ch = $this->advance();

            if ($ch === '"') {
                return $out;
            }

            if ($ch === '\\' && ! $this->eof()) {
                $esc = $this->advance();
                $out .= match ($esc) {
                    'n' => "\n",
                    't' => "\t",
                    '"' => '"',
                    '\\' => '\\',
                    default => $esc,
                };

                continue;
            }

            $out .= $ch;
        }

        throw new PmlSyntaxException('Unterminated string.', $this->line, $this->col);
    }

    private function wordAhead(string $word): bool
    {
        if (! $this->lookahead($word)) {
            return false;
        }

        $after = $this->pos + strlen($word);

        if ($after >= $this->len) {
            return true;
        }

        return in_array($this->src[$after], [' ', "\t", "\r", "\n"], true);
    }

    private function skipWs(): void
    {
        while (! $this->eof()) {
            $c = $this->peekChar();

            if ($c === ' ' || $c === "\t" || $c === "\r" || $c === "\n") {
                $this->advance();

                continue;
            }

            break;
        }
    }

    /** Only whitespace precedes the cursor on its line. */
    private function atLineStart(): bool
    {
        $before = substr($this->src, 0, $this->pos);
        $newline = strrpos($before, "\n");
        $line = $newline === false ? $before : substr($before, $newline + 1);

        return trim($line) === '';
    }

    /**
     * Consume a fenced block (opening fence line through closing fence) and return it verbatim, or
     * null (consuming nothing) when there is no closing fence on a later line.
     */
    private function readFenceVerbatim(): ?string
    {
        if (preg_match('/\n[ \t]*```[ \t]*(?=\n|$)/', $this->src, $m, PREG_OFFSET_CAPTURE, $this->pos + 3) !== 1) {
            return null;
        }

        $end = $m[0][1] + strlen($m[0][0]);
        $out = '';

        while ($this->pos < $end) {
            $out .= $this->advance();
        }

        return $out;
    }

    private function lookahead(string $s): bool
    {
        return substr($this->src, $this->pos, strlen($s)) === $s;
    }

    /**
     * @throws PmlSyntaxException
     */
    private function expect(string $s): void
    {
        if (! $this->lookahead($s)) {
            throw new PmlSyntaxException("Expected \"{$s}\".", $this->line, $this->col);
        }

        $n = strlen($s);

        for ($i = 0; $i < $n; $i++) {
            $this->advance();
        }
    }

    private function peekChar(): string
    {
        return $this->pos < $this->len ? $this->src[$this->pos] : '';
    }

    private function advance(): string
    {
        if ($this->pos >= $this->len) {
            return '';
        }

        $ch = $this->src[$this->pos];
        $this->pos++;

        if ($ch === "\n") {
            $this->line++;
            $this->col = 1;
        } else {
            $this->col++;
        }

        return $ch;
    }

    private function eof(): bool
    {
        return $this->pos >= $this->len;
    }
}
