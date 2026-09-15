<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

/**
 * `plaintextPassword` exists only here, in memory, for the CLI to display
 * once — mirrors `IssuedApiKey::plaintextToken`. Nothing downstream ever
 * sees it again; only its hash is persisted.
 */
final readonly class CreatedOperator
{
    public function __construct(
        public string $operatorId,
        public string $plaintextPassword,
    ) {}
}
