<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class RevokeApiKeyCommand
{
    public function __construct(
        public string $apiKeyId,
    ) {}
}
