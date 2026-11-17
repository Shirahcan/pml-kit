<?php

namespace Shirahcan\Pml\Layout;

use Shirahcan\Pml\InvalidPmlException;
use Shirahcan\Pml\PrimitiveSchema;

/**
 * A layout: the fixed skeleton a blog post or a newsletter follows (post layouts A1b, owner 2026-11-16: "blog posts
 * ... require well-defined templates/layout/starters"). One validated shape, so every product, the designer and the
 * structural check read the same thing.
 *
 * A layout is a list of PARTS in order. Each part is one of:
 * - `bound`: draws the document's own fields (title, byline, cover, related) and is never typed into;
 * - `fill`: words the writer (or Porter) fills;
 * - `repeat`: a part that repeats between `min` and `max` times (question sections, FAQs); `inside` puts it inside
 *   another part (each FAQ inside the FAQ part);
 * - `cta`: a call to action of one `stage` (awareness, consideration, decision), its copy from the CTA library;
 *   `moves_within` names the part it may move inside (the consideration CTA anywhere in the body);
 * - `record`: picked from records, never written (a consented client story).
 *
 * The design marks each part on the PML with `part="<key>"` (0.14.0); `skeleton` is the PML a part starts as.
 * ⚠ Rules here are what a layout MAY say; whether a post FOLLOWS its layout is LayoutChecks (A3).
 */
final class LayoutDefinition
{
    public const KINDS = ['post', 'email'];

    public const PART_KINDS = ['bound', 'fill', 'repeat', 'cta', 'record'];

    public const STAGES = ['awareness', 'consideration', 'decision'];

    public const VARIANTS = ['inline', 'rail', 'ask', 'banner'];

    private const KEY = '/^[a-z][a-z0-9_-]{0,63}$/';

    /**
     * @param  list<array<string, mixed>>  $parts
     * @param  list<string>  $allowedFormats
     */
    private function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $kind,
        public readonly array $parts,
        public readonly array $allowedFormats,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidPmlException naming the part and the reason
     */
    public static function fromArray(array $data, ?PrimitiveSchema $schema = null): self
    {
        $key = self::key($data['key'] ?? null, 'layout.key');
        $name = self::text($data['name'] ?? null, 'layout.name');
        $kind = $data['kind'] ?? null;

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidPmlException('A layout is for a "post" or an "email".', 'layout.kind');
        }

        if (! is_array($data['parts'] ?? null) || $data['parts'] === [] || ! array_is_list($data['parts'])) {
            throw new InvalidPmlException('A layout lists its parts in order, at least one.', 'layout.parts');
        }

        $parts = [];

        foreach ($data['parts'] as $i => $part) {
            $parts[] = self::readPart($part, "layout.parts.{$i}", $schema);
        }

        self::crossCheck($parts);

        $formats = $data['allowed_formats'] ?? [];

        if (! is_array($formats) || ! array_is_list($formats)) {
            throw new InvalidPmlException('"allowed_formats" lists writing format keys.', 'layout.allowed_formats');
        }

        foreach ($formats as $i => $format) {
            self::key($format, "layout.allowed_formats.{$i}");
        }

        return new self($key, $name, $kind, $parts, array_values(array_unique($formats)));
    }

    /** @return array<string, mixed>|null */
    public function part(string $key): ?array
    {
        foreach ($this->parts as $part) {
            if ($part['key'] === $key) {
                return $part;
            }
        }

        return null;
    }

    /**
     * A fresh document for this layout: each part's skeleton in order, a repeating part as many times as its minimum
     * (at least once). A part that sits inside another is already in that part's skeleton, so it is skipped here.
     * What "New post" starts from, so a new post follows its layout before anyone types a word.
     *
     * @return list<array<string, mixed>>
     */
    public function starter(): array
    {
        $nodes = [];

        foreach ($this->parts as $part) {
            if (isset($part['inside']) || $part['skeleton'] === []) {
                continue;
            }

            $times = $part['kind'] === 'repeat' ? max(1, $part['min']) : 1;

            for ($i = 0; $i < $times; $i++) {
                array_push($nodes, ...$part['skeleton']);
            }
        }

        return $nodes;
    }

    /** The definition in one canonical form: the same layout always writes the same array. */
    public function toArray(): array
    {
        return ['key' => $this->key, 'name' => $this->name, 'kind' => $this->kind, 'allowed_formats' => $this->allowedFormats, 'parts' => $this->parts];
    }

    /**
     * @param  mixed  $part
     * @return array<string, mixed>
     */
    private static function readPart($part, string $path, ?PrimitiveSchema $schema): array
    {
        if (! is_array($part)) {
            throw new InvalidPmlException('Each part is an object.', $path);
        }

        $kind = $part['kind'] ?? null;

        if (! in_array($kind, self::PART_KINDS, true)) {
            throw new InvalidPmlException('A part is bound, fill, repeat, cta or record.', "{$path}.kind");
        }

        $out = [
            'key' => self::key($part['key'] ?? null, "{$path}.key"),
            'label' => self::text($part['label'] ?? null, "{$path}.label"),
            'kind' => $kind,
            'required' => self::flag($part['required'] ?? true, "{$path}.required"),
            'locked' => self::flag($part['locked'] ?? false, "{$path}.locked"),
        ];

        if ($kind === 'repeat') {
            $min = $part['min'] ?? 1;
            $max = $part['max'] ?? null;

            if (! is_int($min) || $min < 0 || ($max !== null && (! is_int($max) || $max < 1 || $max < $min))) {
                throw new InvalidPmlException('A repeating part says how many: "min" (0 or more) up to "max" (at least min).', $path);
            }

            $out['min'] = $min;
            $out['max'] = $max;

            if (isset($part['inside'])) {
                $out['inside'] = self::key($part['inside'], "{$path}.inside");
            }
        } elseif (isset($part['min']) || isset($part['max']) || isset($part['inside'])) {
            throw new InvalidPmlException('Only a repeating part has "min", "max" or "inside".', $path);
        }

        if ($kind === 'cta') {
            if (! in_array($part['stage'] ?? null, self::STAGES, true)) {
                throw new InvalidPmlException('A call to action is for one stage: awareness, consideration or decision.', "{$path}.stage");
            }

            $out['stage'] = $part['stage'];
            $out['variant'] = $part['variant'] ?? 'inline';

            if (! in_array($out['variant'], self::VARIANTS, true)) {
                throw new InvalidPmlException('A call to action is inline, rail, ask or banner.', "{$path}.variant");
            }

            if (isset($part['moves_within'])) {
                $out['moves_within'] = self::key($part['moves_within'], "{$path}.moves_within");
            }
        } elseif (isset($part['stage']) || isset($part['variant']) || isset($part['moves_within'])) {
            throw new InvalidPmlException('Only a call to action has "stage", "variant" or "moves_within".', $path);
        }

        $out['rules'] = self::rules($part['rules'] ?? [], "{$path}.rules");
        $out['skeleton'] = self::skeleton($part['skeleton'] ?? [], "{$path}.skeleton", $schema, $out['key']);

        return $out;
    }

    /**
     * Rules a part's words follow: `words` {min, max} and `chars` {max}. Counters, never prose.
     *
     * @return array<string, array<string, int>>
     */
    private static function rules(mixed $rules, string $path): array
    {
        if (! is_array($rules)) {
            throw new InvalidPmlException('"rules" is an object.', $path);
        }

        $out = [];

        foreach ($rules as $name => $range) {
            if (! in_array($name, ['words', 'chars'], true) || ! is_array($range) || $range === []) {
                throw new InvalidPmlException('A rule counts "words" or "chars" with a min and/or a max.', "{$path}.{$name}");
            }

            foreach ($range as $bound => $value) {
                if (! in_array($bound, ['min', 'max'], true) || ! is_int($value) || $value < 0) {
                    throw new InvalidPmlException('A rule\'s "min" and "max" are whole numbers.', "{$path}.{$name}.{$bound}");
                }
            }

            if (isset($range['min'], $range['max']) && $range['min'] > $range['max']) {
                throw new InvalidPmlException('A rule\'s "min" cannot be above its "max".', "{$path}.{$name}");
            }

            ksort($range);
            $out[$name] = $range;
        }

        ksort($out);

        return $out;
    }

    /** @return list<array<string, mixed>> */
    /**
     * A part's skeleton belongs to that part, so each top-level element carries `part="<key>"` (0.23.2). Validation
     * stamps it when the author left it out and refuses an element that names ANOTHER part: without the mark, a
     * fresh document from {@see starter()} would fail its own checks (`part_missing`), and every new post or issue
     * started on an edited layout would be refused at publish for a reason its author never caused.
     */
    private static function skeleton(mixed $nodes, string $path, ?PrimitiveSchema $schema, string $partKey): array
    {
        if (! is_array($nodes) || ! array_is_list($nodes)) {
            throw new InvalidPmlException('A part\'s "skeleton" is a list of PML elements.', $path);
        }

        foreach ($nodes as $i => $node) {
            if (! is_array($node)) {
                throw new InvalidPmlException('Each skeleton element is a PML element.', "{$path}.{$i}");
            }

            $named = $node['part'] ?? null;

            if ($named !== null && $named !== $partKey) {
                throw new InvalidPmlException("This skeleton element is marked part=\"{$named}\", but it belongs to the \"{$partKey}\" part.", "{$path}.{$i}.part");
            }

            $nodes[$i]['part'] = $partKey;
            $schema?->validateNode($nodes[$i], "{$path}.{$i}");
        }

        return PrimitiveSchema::canonical($nodes);
    }

    /** References between parts point at real parts, in a shape the designer can honour. */
    private static function crossCheck(array $parts): void
    {
        $byKey = [];

        foreach ($parts as $i => $part) {
            if (isset($byKey[$part['key']])) {
                throw new InvalidPmlException("Two parts are both called \"{$part['key']}\".", "layout.parts.{$i}.key");
            }

            $byKey[$part['key']] = $part;
        }

        foreach ($parts as $i => $part) {
            foreach (['inside', 'moves_within'] as $field) {
                $target = $part[$field] ?? null;

                if ($target === null) {
                    continue;
                }

                if ($target === $part['key'] || ! isset($byKey[$target])) {
                    throw new InvalidPmlException("\"{$field}\" must name another part of this layout.", "layout.parts.{$i}.{$field}");
                }

                if ($field === 'inside' && isset($byKey[$target]['inside'])) {
                    throw new InvalidPmlException('A part can sit inside another part, but only one level deep.', "layout.parts.{$i}.inside");
                }
            }
        }
    }

    private static function key(mixed $value, string $path): string
    {
        if (! is_string($value) || preg_match(self::KEY, $value) !== 1) {
            throw new InvalidPmlException('A key is a short id: a lowercase letter, then lowercase letters, digits, "_" or "-".', $path);
        }

        return $value;
    }

    private static function text(mixed $value, string $path): string
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 120) {
            throw new InvalidPmlException('A name is a few words (1 to 120 characters).', $path);
        }

        return trim($value);
    }

    private static function flag(mixed $value, string $path): bool
    {
        if (! is_bool($value)) {
            throw new InvalidPmlException('This is true or false.', $path);
        }

        return $value;
    }
}
