<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * `plaintextPassword` exists only here, in memory, for the caller (CLI or
 * HTTP response) to display once — mirrors `IssuedApiKey::plaintextToken`.
 * Nothing downstream ever sees it again; only its hash is persisted.
 */
final readonly class CreatedOperatorView
{
    public function __construct(
        public string $operatorId,
        public string $plaintextPassword,
    ) {}
}
