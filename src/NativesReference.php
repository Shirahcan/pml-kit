<?php

namespace Shirahcan\Pml;


/**
 * The reference the builder shows for "what can a page read?" (PML v3 L3): every PSL native and its
 * fields, written the way an author uses them (`{owner.registered_name}`). Built from
 * {@see Natives::manifest()}, the one declaration, so the reference can never list a field a page
 * cannot read or miss one it can. Replaces the old placeholder catalog.
 */
class NativesReference
{
    /**
     * @return list<array{token: string, scope: string, description: string, example: string, repeats: bool, item_alias: string|null}>
     */
    public function all(): array
    {
        $entries = [];

        foreach (Natives::manifest() as $root => $native) {
            if ($native['fields'] === []) {
                $entries[] = $this->entry($root, $root, ['description' => $native['description'], 'example' => '']);

                continue;
            }

            foreach ($native['fields'] as $key => $meta) {
                $entries[] = $this->entry("{$root}.{$key}", $root, $meta);
            }
        }

        // Inside each={...} over a declared list: each item's fields under its alias (package, p).
        foreach (Natives::items() as $alias => $fields) {
            foreach ($fields as $key => $meta) {
                $entries[] = $this->entry("{$alias}.{$key}", $alias, $meta);
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array{token: string, scope: string, description: string, example: string, repeats: bool, item_alias: string|null}
     */
    private function entry(string $token, string $scope, array $meta): array
    {
        $alias = isset($meta['item']) ? (string) $meta['item'] : (isset($meta['repeat_alias']) ? (string) $meta['repeat_alias'] : null);

        return [
            'token' => $token,
            'scope' => $scope,
            'description' => (string) ($meta['description'] ?? ''),
            'example' => (string) ($meta['example'] ?? ''),
            // A list is repeated with each={item in list}; anything else is read in braces.
            'repeats' => $alias !== null,
            'item_alias' => $alias,
        ];
    }
}
