<?php

namespace Shirahcan\Pml\Tests;

use PHPUnit\Framework\TestCase;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PmlSyntaxException;
use Shirahcan\Pml\RichTextCompiler;
use Shirahcan\Pml\TemplateVariable;

/**
 * `{{name}}` in PML words is a template variable kept as literal text (pml-email, A1: "variables stay {{name}}"),
 * read the same by the scanner, the rich-text compiler and the source writer; `{...}` stays a PSL expression.
 */
final class TemplateVariableTest extends TestCase
{
    private function parser(): PmlParser
    {
        return new PmlParser(new PmlScanner(), new MarkdownToDoc());
    }

    /** @return list<string> */
    private static function words(array $nodes): array
    {
        $out = [];
        array_walk_recursive($nodes, function ($v, $k) use (&$out): void {
            if ($k === 'value' && is_string($v)) {
                $out[] = $v;
            }
        });

        return $out;
    }

    public function test_a_variable_in_words_is_literal_and_an_expression_still_is_an_expression(): void
    {
        $nodes = $this->parser()->parse('<p>Hi {{recipient_name}}, {owner.name} wrote.</p>');

        $this->assertContains('Hi {{recipient_name}}, ', self::words($nodes));
        $this->assertStringContainsString('"$expr"', (string) json_encode($nodes), 'the PSL expression is still compiled');
    }

    public function test_only_the_exact_shape_is_a_variable(): void
    {
        $this->assertSame('{{a_b1}}', TemplateVariable::at('x {{a_b1}} y', 2));
        $this->assertNull(TemplateVariable::at('{{ a }}', 0), 'spaces: not a variable');
        $this->assertNull(TemplateVariable::at('{{a.b}}', 0), 'a dot: not a variable');

        $this->expectException(PmlSyntaxException::class);
        $this->parser()->parse('<p>Hi {{ spaced }}</p>');
    }

    public function test_the_source_writer_keeps_a_variable_as_typed_and_still_escapes_braces(): void
    {
        $nodes = [['primitive' => 'p', 'children' => [['primitive' => 'text', 'props' => ['value' => 'Hi {{recipient_name}}, a {b} c']]]]];

        $source = (new PmlSerializer(new DocToMarkdown()))->serialize(['imports' => [], 'nodes' => $nodes]);

        $this->assertStringContainsString('Hi {{recipient_name}}, a \{b\} c', $source);
        $this->assertContains('Hi {{recipient_name}}, a {b} c', self::words($this->parser()->parse($source)), 'and it reads back the same');
    }

    public function test_rich_text_keeps_a_variable_as_words(): void
    {
        $doc = (new RichTextCompiler(new MarkdownToDoc()))->doc('Your code is **{{code}}** and {owner.name} sent it.');
        $json = (string) json_encode($doc);

        $this->assertStringContainsString('{{code}}', $json);
        $this->assertStringContainsString('"type":"expr"', $json, 'the PSL expression is still an expression');
    }
}
