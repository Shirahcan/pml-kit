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
     * Lines an email must carry AS SENT, whichever chrome it uses (pml-email A4). The product names them per email
     * (a marketing email: its unsubscribe link, `unsubscribe_url`; under CASL, its sender's mailing address). With the
     * standard chrome the footer may carry them; an email that opts out (`chrome: none`) must carry them in its own
     * body, so opting out of the header and footer can never drop a required line.
     *
     * @param  list<array<string, mixed>>  $nodes  the email's own design
     * @param  array{chrome?: string}  $config
     * @param  list<array<string, mixed>>  $footer  the product's standard footer
     * @param  list<string>  $required  variables that must appear, e.g. ['unsubscribe_url']
     * @return list<array{kind: 'error', code: string, text: string}>
     */
    public static function requiredLines(array $nodes, array $config, array $footer, array $required): array
    {
        $standard = ($config['chrome'] ?? 'standard') !== 'none';
        $carried = [...EmailProfile::variables($nodes), ...($standard ? EmailProfile::variables($footer) : [])];
        $out = [];

        foreach (array_diff(array_map('strtolower', $required), $carried) as $missing) {
            $out[] = ['kind' => 'error', 'code' => 'required_line', 'text' => $standard
                ? "{{{$missing}}} must be in this email: neither its body nor the standard footer has it."
                : "{{{$missing}}} must be in this email: it leaves out the standard footer, so its own body has to carry it."];
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
