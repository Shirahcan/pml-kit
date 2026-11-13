<?php

namespace Shirahcan\Pml\Psl;

use Shirahcan\Pml\Psl\Diagnostic;
use Shirahcan\Pml\Psl\Psl;
use Shirahcan\Pml\Psl\PslException;
use Shirahcan\Pml\Natives;

/**
 * The errors in a page's `<script>`s that would break the page when it runs: a PSL parse fault, or an
 * error-severity diagnostic such as a name nothing defines. Warnings and info are advice and pass
 * (aid-not-enforce).
 *
 * One check, used by Porter's console Build (its save gate hands these to the repair pass, so Porter fixes
 * its own script before anyone sees it). It moved here from the retired "Ask Porter for interactivity"
 * composer (owner 2026-11-10: everything is handled by the console).
 */
final class ScriptLint
{
    /**
     * @param  list<mixed>  $nodes
     * @return list<string>
     */
    public static function errors(array $nodes, ?Psl $psl = null): array
    {
        $psl ??= new Psl();
        $errors = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $source = ($node['primitive'] ?? null) === 'script' ? ($node['props']['source'] ?? null) : null;

            if (is_string($source)) {
                try {
                    $diagnostics = $psl->compile($source, Natives::roots())['diagnostics'];
                } catch (PslException $e) {
                    $errors[] = 'A script has a PSL error: '.$e->getMessage();
                    $diagnostics = [];
                }

                foreach ($diagnostics as $d) {
                    if (is_array($d) && ($d['severity'] ?? null) === Diagnostic::ERROR) {
                        $errors[] = "A script has a PSL error: {$d['message']} (line {$d['line']}, column {$d['col']})";
                    }
                }
            }

            if (isset($node['children']) && is_array($node['children'])) {
                array_push($errors, ...self::errors($node['children'], $psl));
            }
        }

        return $errors;
    }
}
