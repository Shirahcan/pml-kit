<?php

namespace Shirahcan\Pml\Email;

use Closure;
use Shirahcan\Pml\Html\HtmlTokens;

/**
 * The semantic email body's tokens for {@see \Shirahcan\Pml\Html\HtmlToPml} (moved out of SemanticToPml unchanged,
 * pml-kit 0.24.0). Block tokens become elements: `{{action_button}}` the main button (bound to `{{action_label}}` /
 * `{{action_url}}` and left out at send time when either is empty), `{{button:LABEL = URL}}` a second button,
 * `{{divider}}` a divider, `{{alert_*:TEXT}}` a toned callout, `{{code:LABEL = VALUE}}` a labelled code panel. A
 * paragraph that is only a prepared-HTML variable (`{{summary_html}}`) becomes a block of its own. Inline
 * `{{badge_*:TEXT}}` becomes a `mark`.
 */
final class EmailBodyTokens implements HtmlTokens
{
    private const ALERT_TONE = ['alert_info' => 'info', 'alert_success' => 'success', 'alert_warning' => 'warning', 'alert_error' => 'danger'];

    private const BADGE = '/\{\{badge_[a-z]+:([^{}]*)\}\}/';

    public function split(string $html): array
    {
        return SemanticTokens::split($html);
    }

    public function wholeVariable(string $html): ?array
    {
        $words = trim(strip_tags($html));
        if (($name = EmailValues::preparedPlaceholder($words)) !== null && $words === trim($html)) {
            return ['primitive' => 'text', 'props' => ['value' => '{{'.$name.'}}']];
        }

        return null;
    }

    public function block(string $name, string $body, Closure $inlines): ?array
    {
        if ($name === 'action_button') {
            return [['primitive' => 'button', 'props' => ['label' => '{{action_label}}', 'href' => '{{action_url}}', 'variant' => 'primary'], 'style' => ['align' => 'center']]];
        }
        if ($name === 'divider') {
            return [['primitive' => 'divider']];
        }
        if (isset(self::ALERT_TONE[$name])) {
            return [['primitive' => 'callout', 'props' => ['tone' => self::ALERT_TONE[$name]], 'children' => [['primitive' => 'p', 'children' => $inlines($body)]]]];
        }
        if ($name === 'button') {
            [$label, $href] = SemanticTokens::pair($body);

            return [['primitive' => 'button', 'props' => ['label' => $label, 'href' => $href, 'variant' => 'primary'], 'style' => ['align' => 'center']]];
        }
        if ($name === 'code') {
            [$label, $value] = SemanticTokens::pair($body);

            return [['primitive' => 'box', 'style' => ['background' => 'surface', 'padding' => 'normal', 'radius' => 'md'], 'children' => [
                ['primitive' => 'p', 'children' => [['primitive' => 'small', 'children' => [['primitive' => 'text', 'props' => ['value' => $label]]]]]],
                ['primitive' => 'pre', 'props' => ['text' => $value]],
            ]]];
        }

        return null;
    }

    public function inlineText(string $text): array
    {
        $out = [];
        foreach (preg_split('/(\{\{badge_(?:info|success|warning|error):[^{}]*\}\})/', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $part) {
            $out[] = preg_match('/^\{\{badge_[a-z]+:([^{}]*)\}\}$/', $part, $m) === 1
                ? ['primitive' => 'mark', 'children' => [['primitive' => 'text', 'props' => ['value' => $m[1]]]]]
                : ['primitive' => 'text', 'props' => ['value' => $part]];
        }

        return $out;
    }

    public function words(string $text): string
    {
        return (string) preg_replace(self::BADGE, '$1', $text);
    }

    public function hasInlineToken(string $text): bool
    {
        return str_contains($text, '{{badge_');
    }
}
