<?php

namespace Shirahcan\Pml\Blocks;

use RuntimeException;

/**
 * A block did not match its schema.
 *
 * Carries the offending path so the builder can point at the exact field rather than
 * saying "invalid content" and leaving the consultant to hunt.
 */
class InvalidBlockException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $path = '',
    ) {
        parent::__construct($message);
    }

    public static function unknownBlockType(string $type): self
    {
        return new self("Unknown block type \"{$type}\".", 'type');
    }

    public static function unknownNode(string $nodeType, string $path): self
    {
        return new self("Rich text may not contain a \"{$nodeType}\" element.", $path);
    }

    public static function unsafeUrl(string $url, string $path): self
    {
        return new self(
            'Links must start with http:// or https://. "' . mb_substr($url, 0, 40) . '" is not allowed.',
            $path,
        );
    }
}
