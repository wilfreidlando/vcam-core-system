<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Laravel's `array` cast JSON-encodes; PostgreSQL's native `text[]` column
 * (used for `api_keys.scopes` — spec technique §6.1) needs its own literal
 * format instead: `{a,b,c}`, with double-quoting for any value containing a
 * character the format treats specially.
 *
 * @implements CastsAttributes<list<string>, iterable<string>>
 */
final class PostgresTextArrayCast implements CastsAttributes
{
    /**
     * @param array<string, mixed> $attributes
     * @return list<string>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = substr($value, 1, -1);

        if ($inner === '') {
            return [];
        }

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"|[^,]+/', $inner, $matches);

        return array_map(
            static fn (string $raw): string => str_starts_with($raw, '"')
                ? stripcslashes(substr($raw, 1, -1))
                : $raw,
            $matches[0],
        );
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        /** @var iterable<string> $value */
        $quoted = array_map(
            static fn (string $item): string => '"'.addcslashes($item, '"\\').'"',
            is_array($value) ? $value : iterator_to_array($value),
        );

        return '{'.implode(',', $quoted).'}';
    }
}
