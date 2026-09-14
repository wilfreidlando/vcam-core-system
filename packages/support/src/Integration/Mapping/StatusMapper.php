<?php

declare(strict_types=1);

namespace Core\Support\Integration\Mapping;

use BackedEnum;
use Core\Support\Integration\Exception\UnknownProviderStatusException;

/**
 * Translates a raw provider status string into the canonical status
 * enum of the calling module.
 *
 * The translation is what makes G6 bite: every adapter MUST funnel its
 * raw statuses through a mapper that returns a domain enum, never let a
 * string provider status leak past the adapter boundary.
 *
 * Implementations are stateless apart from any injected mapping table.
 * Implementations SHOULD throw {@see UnknownProviderStatusException}
 * (not silently return a default) when the raw value is unrecognised —
 * a new status is information, not a bug to hide.
 *
 * @template T of \BackedEnum
 */
interface StatusMapper
{
    /**
     * @return T The canonical status enum value.
     *
     * @throws UnknownProviderStatusException when $raw is unrecognised.
     */
    public function map(string $raw): BackedEnum;
}
