<?php

declare(strict_types=1);

namespace Core\Identity\Application\Dto;

final readonly class OperatorSession
{
    public function __construct(
        public string $operatorId,
        public string $accessToken,
    ) {}
}
