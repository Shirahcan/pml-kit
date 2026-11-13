<?php

namespace Shirahcan\Pml;

use RuntimeException;

/**
 * A PML node did not match the primitive schema.
 *
 * Mirrors {@see \Shirahcan\Pml\Blocks\InvalidBlockException}: it carries the offending
 * path so the builder (and the future PML pane) can point at the exact node/prop rather than
 * saying "invalid content" and leaving the consultant to hunt.
 */
class InvalidPmlException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $path = '',
    ) {
        parent::__construct($message);
    }

    public static function unknownPrimitive(string $primitive, string $path): self
    {
        return new self("Unknown primitive \"{$primitive}\".", $path);
    }

    public static function unknownProp(string $prop, string $primitive, string $path): self
    {
        return new self("The prop \"{$prop}\" is not allowed on \"{$primitive}\".", $path);
    }

    /**
     * @param  list<string>  $allowed  the values the key takes, named in the message (2026-11-09): a
     *                                 repair told only "not valid" wrote the same wrong value again
     */
    public static function badToken(string $key, string $value, string $path, array $allowed = []): self
    {
        $takes = $allowed === [] ? '' : ' (it takes '.implode(', ', array_slice($allowed, 0, -1)).(count($allowed) > 1 ? ' or ' : '').end($allowed).')';

        return new self("\"{$value}\" is not a valid \"{$key}\"{$takes}.", $path);
    }

    public static function unknownStyleToken(string $key, string $path): self
    {
        return new self("\"{$key}\" is not a style token.", $path);
    }
}
