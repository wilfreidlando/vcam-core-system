<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

/**
 * ADR-0083 §3 — how personal data is shown by default in the console:
 * enough to recognise it, not enough to use it. Unmasking is an audited
 * action of the caller.
 */
final class PersonalDataMask
{
    /** `alice@domaine.cm` → `a***@d***.cm` */
    public static function email(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        $local = substr($email, 0, $at);
        $domain = substr($email, $at + 1);
        $dot = strrpos($domain, '.');
        $name = $dot === false ? $domain : substr($domain, 0, $dot);
        $tld = $dot === false ? '' : substr($domain, $dot);
        $maskedDomain = $name === '' ? '' : mb_substr($name, 0, 1);

        return mb_substr($local, 0, 1).'***@'.$maskedDomain.'***'.$tld;
    }

    /** `+237 6 90 00 11 12` → `+2376******12` */
    public static function phone(string $phone): string
    {
        $digits = (string) preg_replace('/\D+/', '', $phone);
        $plus = str_starts_with(trim($phone), '+') ? '+' : '';
        $length = strlen($digits);
        if ($length < 7) {
            return $plus.str_repeat('*', max(3, $length));
        }

        return $plus.substr($digits, 0, 4).str_repeat('*', $length - 6).substr($digits, -2);
    }

    /** `Ali Kamga` → `A*** K***` */
    public static function name(string $name): string
    {
        $split = preg_split('/\s+/u', trim($name));
        $words = $split === false ? [] : $split;
        $masked = array_map(
            static function (string $word): string {
                if ($word === '') {
                    return '';
                }

                return mb_substr($word, 0, 1).'***';
            },
            $words,
        );

        $joined = implode(' ', array_filter($masked, static fn (string $w): bool => $w !== ''));

        return $joined === '' ? '***' : $joined;
    }
}
