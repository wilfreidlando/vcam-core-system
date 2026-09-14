<?php

declare(strict_types=1);

namespace Core\Identity\Infrastructure\Persistence\Eloquent;

use Core\Identity\Domain\Model\Organization;
use Core\Identity\Domain\Port\OrganizationRepository;

final class EloquentOrganizationRepository implements OrganizationRepository
{
    public function save(Organization $organization): void
    {
        OrganizationRecord::query()->updateOrCreate(
            ['id' => $organization->id],
            ['name' => $organization->name(), 'status' => $organization->status()],
        );
    }

    public function find(string $id): ?Organization
    {
        $record = OrganizationRecord::query()->find($id);

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(OrganizationRecord $record): Organization
    {
        return new Organization($record->id, $record->name, $record->status);
    }
}
