<?php

declare(strict_types=1);

namespace Core\Parties\Domain\Port;

use Core\Parties\Domain\Model\Party;

interface PartyRepository
{
    public function save(Party $party): void;

    public function find(string $id): ?Party;

    public function findByExternalRef(string $projectId, string $externalRef): ?Party;
}
