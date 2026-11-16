<?php

namespace Shirahcan\Pml\Email;

/**
 * The checks an email design is held to before it is saved (pml-email A2), the same for every product: what the
 * profile refuses, variables the template does not have, and, against the email as it was, a declared variable or the
 * main button that went missing. `error` refuses the save; `issue` is said and saved.
 *
 * Used by the email editor's save, Porter's email Build (one repair with the errors) and the converter's proof.
 */
final class EmailChecks
{
    /**
     * @param  list<array<string, mixed>>  $after  the design to save
     * @param  list<array<string, mixed>>|null  $before  the email as it is now, or null for a new email
     * @return list<array{kind: 'error'|'issue', code: string, text: string}>
     */
    public static function check(array $after, ?array $before, EmailVariables $variables): array
    {
        $out = [];

        foreach (EmailProfile::violations($after) as $violation) {
            $out[] = ['kind' => 'error', 'code' => $violation['code'], 'text' => $violation['text'].' (at '.$violation['at'].')'];
        }

        $known = array_map('strtolower', [...$variables->declared(), ...$variables->alwaysAvailable()]);
        $used = EmailProfile::variables($after);

        foreach (array_diff($used, $known) as $unknown) {
            $out[] = ['kind' => 'error', 'code' => 'unknown_variable', 'text' => "{{{$unknown}}} is not a variable this email has, so it would print as written."];
        }

        if ($before !== null) {
            $declared = array_map('strtolower', $variables->declared());

            foreach (array_diff(array_intersect(EmailProfile::variables($before), $declared), $used) as $dropped) {
                $out[] = ['kind' => 'error', 'code' => 'dropped_variable', 'text' => "{{{$dropped}}} was in this email and is gone: the sender fills it in, so keep it unless the change is to remove it."];
            }

            if (EmailProfile::hasMainButton($before) && ! EmailProfile::hasMainButton($after)) {
                $out[] = ['kind' => 'issue', 'code' => 'main_button', 'text' => 'The email\'s main button is gone.'];
            }
        }

        return $out;
    }

    /**
     * @param  list<array{kind: string}>  $findings
     * @return list<array{kind: string}>
     */
    public static function errors(array $findings): array
    {
        return array_values(array_filter($findings, fn (array $f) => $f['kind'] === 'error'));
    }
}
