<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

/**
 * ADR-0083 §2 — how a personal value is normalised before its blind index is
 * computed, so that `Ali@Example.CM ` and `ali@example.cm` index the same.
 */
enum PersonalDataKind: string
{
    case Email = 'email';
    case Phone = 'phone';
    case Text = 'text';

    public function normalize(string $value): string
    {
        $value = trim($value);

        return match ($this) {
            self::Email => mb_strtolower($value),
            // E.164-like: digits only, keeping a leading `+` (spaces, dots,
            // dashes and parentheses are presentation).
            self::Phone => (str_starts_with($value, '+') ? '+' : '').preg_replace('/\D+/', '', $value),
            self::Text => mb_strtolower((string) preg_replace('/\s+/u', ' ', $value)),
        };
    }
}
