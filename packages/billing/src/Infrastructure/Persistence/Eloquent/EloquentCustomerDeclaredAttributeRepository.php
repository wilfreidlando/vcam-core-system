<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Port\CustomerDeclaredAttributeRepository;
use DateTimeImmutable;

/**
 * ADR-0122 §5 — les valeurs déclarées d'un client.
 *
 * Les valeurs sont en **clair**, comme `display_name` : ni chiffrées, ni
 * indexées, ni cherchées. Le seul index de la table porte la clé
 * `(customer_id, key)` — l'unicité de la valeur d'une clé pour un client.
 *
 * Le geste remplace **l'ensemble** : une clé qui n'est plus donnée
 * disparaît, une clé qui l'est est écrite.
 */
final class EloquentCustomerDeclaredAttributeRepository implements CustomerDeclaredAttributeRepository
{
    public function forCustomer(string $projectId, string $customerId): array
    {
        // Built on `toBase()` and re-hydrated, like the listing of
        // `EloquentCustomerRepository` (Larastan false positive on the
        // calls Eloquent's builder forwards).
        $rows = CustomerDeclaredAttributeRecord::query()->toBase()
            ->where('project_id', $projectId)
            ->where('customer_id', $customerId)
            ->orderBy('key')
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        $values = [];

        foreach ($rows as $row) {
            $key = $row['key'] ?? null;
            $value = $row['value'] ?? null;

            if (! is_string($key) || ! is_string($value)) {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    public function replace(string $projectId, string $customerId, array $values, DateTimeImmutable $at): void
    {
        $now = $at->format('Y-m-d H:i:sP');

        // The keys that leave the set.
        $removed = array_values(array_diff(
            array_keys($this->forCustomer($projectId, $customerId)),
            array_keys($values),
        ));

        if ($removed !== []) {
            CustomerDeclaredAttributeRecord::query()->toBase()
                ->where('project_id', $projectId)
                ->where('customer_id', $customerId)
                ->whereIn('key', $removed)
                ->delete();
        }

        if ($values === []) {
            return;
        }

        $rows = [];

        foreach ($values as $key => $value) {
            $rows[] = [
                'customer_id' => $customerId,
                'project_id' => $projectId,
                'key' => $key,
                'value' => $value,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        // The unique index `(customer_id, key)` carries the upsert: the
        // creation instant of a value is kept, its value and its instant
        // of last change move.
        CustomerDeclaredAttributeRecord::query()->toBase()->upsert(
            $rows,
            ['customer_id', 'key'],
            ['value', 'updated_at'],
        );
    }
}
