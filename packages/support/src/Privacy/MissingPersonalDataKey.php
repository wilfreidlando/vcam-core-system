<?php

declare(strict_types=1);

namespace Core\Support\Privacy;

use RuntimeException;

/** Raised at first use, so a deployment without the keys fails loudly, never silently in clear. */
final class MissingPersonalDataKey extends RuntimeException
{
    public static function encryption(string $why): self
    {
        return new self('PII_ENCRYPTION_KEYS is missing or invalid: '.$why.' Generate one with `php artisan privacy:generate-key`.');
    }

    public static function blindIndex(string $why): self
    {
        return new self('PII_BLIND_INDEX_KEY is missing or invalid: '.$why.' Generate one with `php artisan privacy:generate-key --blind-index`.');
    }
}
