<?php

declare(strict_types=1);

namespace Core\Identity\Tests\Unit\Fakes;

use Core\Identity\Domain\Model\GeneratedApiKeySecret;
use Core\Identity\Domain\Port\ApiKeySecretGenerator;

final class FixedApiKeySecretGenerator implements ApiKeySecretGenerator
{
    public function __construct(
        private readonly string $prefix = 'pk_fixed01',
        private readonly string $secret = 'fixed-test-secret',
    ) {}

    public function generate(): GeneratedApiKeySecret
    {
        return new GeneratedApiKeySecret($this->prefix, $this->secret);
    }
}
