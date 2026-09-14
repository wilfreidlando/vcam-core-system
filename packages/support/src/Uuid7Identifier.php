<?php

declare(strict_types=1);

namespace Core\Support;

use Illuminate\Support\Str;

/**
 * UUIDv7: time-ordered, unlike v4. New rows sort and index the same way
 * whether their id was generated here (an Application use case) or by
 * Eloquent's `HasUuids` trait, which defaults to the same v7 format — no
 * index fragmentation from mixing the two.
 */
final class Uuid7Identifier implements Identifier
{
    public function generate(): string
    {
        return (string) Str::uuid7();
    }
}
