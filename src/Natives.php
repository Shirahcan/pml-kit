<?php

namespace Shirahcan\Pml;

use RuntimeException;

/**
 * The PSL natives: every name a page expression may start with (shirahcan/pml 0.2.0, shared program E2f).
 *
 * The LANGUAGE owns the browser natives (state, location, device, now, props, self): they mean the same on every
 * product. The PRODUCT declares its server natives (Portify: owner, site, page) with their fields, once, at boot
 * (`Natives::declare()`), because what a page may read about its owner is the product's fact list.
 *
 * ⚠ UNDECLARED FAILS LOUDLY. Parsing or linting a page before the product declared its natives throws, rather
 * than quietly treating `owner.name` as an unknown name.
 */
final class Natives
{
    /** Natives that live in the visitor's browser only (never sent by the server). */
    public const BROWSER = [
        'state' => 'The page\'s reactive values: read state.open, write state.open = true or state.count++ in an on-click. Changing one redraws what reads it.',
        'location' => 'Where the visitor is, as yes/no checks only: location.path, location.hash_is("pricing"), location.query_is("utm_source", "linkedin"), location.has_query("ref"). A link\'s words are never shown on the page.',
        'device' => 'The visitor\'s screen once the page has loaded: "phone", "tablet" or "desktop" (null before).',
        'now' => 'Today as YYYY-MM-DD, the same in the server render and the browser.',
        'props' => 'Inside a component: its settings, as props.title. Filled in where the component is used.',
        'self' => 'Inside a component: that copy\'s own values, as self.open (write self.open = true in an on-click). Every copy on a page has its own; state is shared by the whole page.',
    ];

    /** @var array<string, array{description: string, fields: array<string, array<string, mixed>>}>|null */
    private static ?array $server = null;

    /** @var array<string, array<string, array<string, mixed>>> item scopes: `package` => fields, `p` => fields */
    private static array $items = [];

    /**
     * The product's server natives, in order, and the fields of the items its lists repeat over.
     *
     * @param  array<string, array{description: string, fields: array<string, array<string, mixed>>}>  $server
     * @param  array<string, array<string, array<string, mixed>>>  $items
     */
    public static function declare(array $server, array $items = []): void
    {
        foreach (array_keys($server) as $name) {
            if (isset(self::BROWSER[$name])) {
                throw new RuntimeException("PSL native \"{$name}\" belongs to the browser; a product cannot redeclare it.");
            }
        }

        self::$server = $server;
        self::$items = $items;
    }

    public static function declared(): bool
    {
        return self::$server !== null;
    }

    /** @return list<string> every name a page expression may start with: the server natives first, then the browser's */
    public static function roots(): array
    {
        return [...array_keys(self::server()), ...array_keys(self::BROWSER)];
    }

    /**
     * What each native holds, for the analyzer, the reference and the assistant.
     *
     * @return array<string, array{description: string, fields: array<string, array<string, mixed>>, browser: bool}>
     */
    public static function manifest(): array
    {
        $out = [];

        foreach (self::server() as $name => $native) {
            $out[$name] = ['description' => $native['description'], 'fields' => $native['fields'], 'browser' => false];
        }

        foreach (self::BROWSER as $name => $description) {
            $out[$name] = ['description' => $description, 'fields' => [], 'browser' => true];
        }

        return $out;
    }

    /** @return array<string, array<string, array<string, mixed>>> the fields of each repeated item, by its alias */
    public static function items(): array
    {
        self::server();

        return self::$items;
    }

    /** @return array<string, array{description: string, fields: array<string, array<string, mixed>>}> */
    private static function server(): array
    {
        if (self::$server === null) {
            throw new RuntimeException('PML server natives are not declared: the product must call Shirahcan\\Pml\\Natives::declare() at boot.');
        }

        return self::$server;
    }
}
