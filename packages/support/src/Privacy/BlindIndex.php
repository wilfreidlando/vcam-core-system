<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

/**
 * ADR-0083 §2 — exact-match lookup and uniqueness over encrypted personal
 * data: `HMAC-SHA256(PII_BLIND_INDEX_KEY, normalised value)`, hex. Without
 * the key, a dictionary of emails or phone numbers reveals nothing (a plain
 * hash would). Persists nothing (G3d).
 */
interface BlindIndex
{
    /** @return string 64 lowercase hex characters */
    public function of(string $value, PersonalDataKind $kind): string;
}
