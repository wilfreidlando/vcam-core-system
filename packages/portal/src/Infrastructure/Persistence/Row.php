<?php

declare(strict_types=1);

namespace Core\Portal\Infrastructure\Persistence;

/**
 * Typed reads of a database row: the query builder hands back `mixed`, the
 * domain wants strings. One place, so no repository casts blindly.
 */
final readonly class Row
{
    /**
     * @param array<string, mixed> $columns
     */
    private function __construct(private array $columns) {}

    public static function of(object $row): self
    {
        /** @var array<string, mixed> $columns */
        $columns = get_object_vars($row);

        return new self($columns);
    }

    public function string(string $column): string
    {
        $value = $this->columns[$column] ?? '';

        return is_scalar($value) ? (string) $value : '';
    }

    public function nullableString(string $column): ?string
    {
        $value = $this->columns[$column] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function bool(string $column): bool
    {
        return (bool) ($this->columns[$column] ?? false);
    }
}
