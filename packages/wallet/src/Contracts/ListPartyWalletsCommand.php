<?php

declare(strict_types=1);

namespace Core\Wallet\Contracts;

final readonly class ListPartyWalletsCommand
{
    public function __construct(
        public string $projectId,
        public string $environment,
        public string $partyExternalRef,
    ) {}
}
