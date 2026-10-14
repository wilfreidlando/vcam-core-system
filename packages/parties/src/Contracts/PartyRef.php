<?php

declare(strict_types=1);

namespace Core\Parties\Contracts;

/**
 * The read-model other modules get back — never `Core\Parties\Domain\Model\Party`,
 * which stays internal (G3c).
 */
final readonly class PartyRef
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $externalRef,
        public string $type,
        public string $kycStatus,
    ) {}
}
