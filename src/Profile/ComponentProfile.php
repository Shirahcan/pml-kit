<?php

namespace Shirahcan\Pml\Profile;

use Shirahcan\Pml\Component\ParamSchema;
use Shirahcan\Pml\Component\ParamShape;
use Shirahcan\Pml\DocToMarkdown;
use Shirahcan\Pml\ExpressionNames;
use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\MarkdownToDoc;
use Shirahcan\Pml\PmlComponentExpander;
use Shirahcan\Pml\PmlParser;
use Shirahcan\Pml\PmlScanner;
use Shirahcan\Pml\PmlSerializer;
use Shirahcan\Pml\PmlSnapshotBuilder;
use Shirahcan\Pml\PmlSyntaxException;

/**
 * A reusable component as a profile (shared PML Studio P3c-5, 2026-11-25): what a component's design is, so the
 * Studio, a host's save and Porter's Build all read ONE rule.
 *
 * A component's design is ONE outer element (the tag a page writes stands for it); it reads its settings as
 * `{props.name}` and only the settings it declares; it may offer `<slot/>` (and named `<slot name="x"/>`) where a page
 * adds its own elements; and it compiles once each setting holds a sample value of its kind (its default, else one of
 * the right shape), which is exactly what a host checks before it publishes a version.
 *
 * Left to the host, on purpose (as on a page): the host's own components and sections (pass them as `$partials`),
 * the claims scan on its sample words, and who may use which elements (a platform-only form, a licensee's records).
 */
final class ComponentProfile implements Profile
{
    /** What a person reads when a component's design is not one element. The Studio's sentence since 2026-11-07. */
    public const ONE_ROOT = 'The visual editor needs exactly one outer element.';

    private readonly PmlSnapshotBuilder $builder;

    /**
     * @param  list<array<string, mixed>>  $params  the component's declared settings ({@see ParamSchema::normalise()})
     * @param  array<string, callable>  $partials  the host's own components and sections (handle => expander)
     */
    public function __construct(private readonly array $params = [], private readonly array $partials = [], ?PmlSnapshotBuilder $builder = null)
    {
        $this->builder = $builder ?? PageProfile::standardBuilder();
    }

    public function key(): string
    {
        return 'component';
    }

    public function elements(): ?array
    {
        return null;
    }

    public function violations(array $nodes): array
    {
        $roots = array_values(array_filter($nodes, static fn ($n) => is_array($n) && ! array_key_exists('comment', $n)));
        if (count($roots) !== 1) {
            return [['code' => 'one_root', 'at' => '', 'text' => self::ONE_ROOT]];
        }

        $names = array_map(static fn (array $p) => (string) $p['name'], $this->params);
        $out = array_map(
            static fn (string $text) => ['code' => 'unknown_setting', 'at' => '1', 'text' => $text],
            ExpressionNames::settingErrors($roots, $names),
        );
        if ($out !== []) {
            return $out;
        }

        try {
            $node = self::expander()->make(self::serializer()->serialize(['imports' => [], 'nodes' => $roots]), $names, $this->params)(
                (new ParamSchema())->sampleValues($this->params),
                [],
            );
            $this->builder->validate([$node], $this->partials);
        } catch (InvalidPmlException|PmlSyntaxException $e) {
            return [['code' => 'invalid', 'at' => '1', 'text' => $e->getMessage()]];
        }

        return [];
    }

    public function pieces(): array
    {
        return [];
    }

    /** What a Build writing a component is taught, from the same data the check reads (the kinds a setting can be). */
    public function spec(): ?string
    {
        $kinds = implode(', ', array_map(static fn (string $k) => str_replace('_', '-', $k), [...ParamShape::SCALAR, ...ParamShape::COLLECTION]));

        return <<<SPEC
            A COMPONENT is a reusable design a page writes as one tag. Its design is exactly ONE outer element.
            - Its settings are declared in a <props> block above the design: <prop name="title" kind="text" label="Title" default="..."/>.
              Kinds: {$kinds}. A list or a map holds its fields as <prop>s inside it.
            - The design reads a setting as {props.name}, never {name}, and only settings the block declares.
              A list is read with each={item in props.plans}.
            - <slot/> marks where a page adds its own elements; <slot name="actions"/> a named place a page fills with slot="actions".
            - Every setting has a default or a value of its kind, so the design compiles before anyone fills it in.
            SPEC;
    }

    private static function expander(): PmlComponentExpander
    {
        return new PmlComponentExpander(new PmlParser(new PmlScanner(), new MarkdownToDoc()));
    }

    private static function serializer(): PmlSerializer
    {
        return new PmlSerializer(new DocToMarkdown());
    }
}
