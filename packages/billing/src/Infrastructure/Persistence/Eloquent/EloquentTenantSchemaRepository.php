<?php

declare(strict_types=1);

namespace Core\Billing\Infrastructure\Persistence\Eloquent;

use Core\Billing\Domain\Exception\TenantSchemaAlreadyAccepted;
use Core\Billing\Domain\Model\TenantSchema;
use Core\Billing\Domain\Model\TenantSchemaField;
use Core\Billing\Domain\Model\TenantSchemaPattern;
use Core\Billing\Domain\Model\TenantSchemaStatus;
use Core\Billing\Domain\Port\TenantSchemaRepository;
use DateTimeImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * ADR-0122 §1, §2 — les déclarations de forme, en base.
 *
 * La forme (`fields`) est sérialisée **à la main** : le jsonb est une
 * liste d'objets, et le dépôt veut savoir ce qu'il écrit (la même
 * prudence que `EloquentCustomerRepository::metadata()`).
 *
 * La garantie « une seule déclaration acceptée par projet » n'est pas
 * écrite ici : elle est portée par l'index partiel
 * `project_tenant_schemas_project_accepted_unique`. Cet adaptateur se
 * contente de traduire sa violation.
 */
final class EloquentTenantSchemaRepository implements TenantSchemaRepository
{
    public function findById(string $projectId, string $id): ?TenantSchema
    {
        $record = ProjectTenantSchemaRecord::query()
            ->where('project_id', $projectId)
            ->where('id', $id)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findAccepted(string $projectId): ?TenantSchema
    {
        $record = ProjectTenantSchemaRecord::query()
            ->where('project_id', $projectId)
            ->where('status', TenantSchemaStatus::Accepted->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findPending(string $projectId): ?TenantSchema
    {
        // Built on `toBase()` and re-hydrated: `orderBy()` is forwarded by
        // Eloquent's builder to the query builder rather than declared on
        // it, which trips a Larastan false positive (the same workaround
        // as `EloquentCustomerRepository`).
        $rows = ProjectTenantSchemaRecord::query()->toBase()
            ->where('project_id', $projectId)
            ->where('status', TenantSchemaStatus::Pending->value)
            ->orderBy('version', 'desc')
            ->limit(1)
            ->get()
            ->map(static fn (object $row): array => (array) $row)
            ->all();

        foreach (ProjectTenantSchemaRecord::hydrate($rows) as $record) {
            return $this->toDomain($record);
        }

        return null;
    }

    public function highestVersion(string $projectId): int
    {
        // `toBase()` — `max()` is forwarded by Eloquent's builder to the
        // query builder rather than declared on it, which trips a Larastan
        // false positive (the same workaround as
        // `EloquentCustomerRepository`).
        $highest = ProjectTenantSchemaRecord::query()->toBase()
            ->where('project_id', $projectId)
            ->max('version');

        return is_numeric($highest) ? (int) $highest : 0;
    }

    public function save(TenantSchema $schema): void
    {
        try {
            // SAVEPOINT (nested transaction) — always runs inside the use
            // case's outer transaction, the same way
            // `EloquentCustomerRepository::save()` does.
            DB::transaction(function () use ($schema): void {
                $attributes = $this->toAttributes($schema);

                // `toBase()` — `exists()` is forwarded by Eloquent's
                // builder to the query builder rather than declared on it
                // (the same Larastan workaround as
                // `EloquentCustomerRepository`).
                if (ProjectTenantSchemaRecord::query()->whereKey($schema->id)->toBase()->exists()) {
                    ProjectTenantSchemaRecord::query()
                        ->whereKey($schema->id)
                        ->update($attributes);

                    return;
                }

                ProjectTenantSchemaRecord::query()->create(
                    ['id' => $schema->id, 'created_at' => $schema->createdAt] + $attributes,
                );
            });
        } catch (UniqueConstraintViolationException) {
            // The partial unique index of the accepted declaration is the
            // one that trips: a project holds **one** form in force.
            throw TenantSchemaAlreadyAccepted::inProject($schema->projectId);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function toAttributes(TenantSchema $schema): array
    {
        return [
            'project_id' => $schema->projectId,
            'version' => $schema->version,
            'status' => $schema->status->value,
            'unique_key' => $schema->uniqueKey,
            'unique_key_pattern' => $schema->uniqueKeyPattern->pattern,
            'fields' => $this->fields($schema),
            'accepted_at' => $schema->acceptedAt,
            // The instant of the last change is the domain's, never the
            // clock of the database.
            'updated_at' => $schema->updatedAt,
        ];
    }

    /**
     * The declared fields, as the JSON array the column carries.
     */
    private function fields(TenantSchema $schema): string
    {
        $encoded = json_encode($schema->fieldsAsArray());

        return is_string($encoded) ? $encoded : '[]';
    }

    private function toDomain(ProjectTenantSchemaRecord $record): TenantSchema
    {
        return new TenantSchema(
            id: $record->id,
            projectId: $record->project_id,
            version: $record->version,
            status: TenantSchemaStatus::from($record->status),
            uniqueKey: $record->unique_key,
            uniqueKeyPattern: TenantSchemaPattern::of($record->unique_key_pattern),
            fields: $this->fieldsOf($record->fields),
            acceptedAt: $record->accepted_at === null
                ? null
                : DateTimeImmutable::createFromInterface($record->accepted_at),
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
            updatedAt: DateTimeImmutable::createFromInterface($record->updated_at),
        );
    }

    /**
     * The declared fields, read back from the JSON array.
     *
     * @return list<TenantSchemaField>
     */
    private function fieldsOf(string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return [];
        }

        $fields = [];

        foreach ($decoded as $field) {
            if (! is_array($field)) {
                continue;
            }

            $fields[] = TenantSchemaField::fromArray($field);
        }

        return $fields;
    }
}
