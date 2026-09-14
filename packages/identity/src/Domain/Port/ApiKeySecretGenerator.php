<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Port;

use Core\Identity\Domain\Model\GeneratedApiKeySecret;
use Core\Support\Environment;

/**
 * A port rather than a call to `random_bytes()` inline in the use case, for
 * one reason: it keeps `IssueApiKey` deterministically testable. A fake
 * implementation can hand back a fixed prefix/secret in a unit test without
 * touching the CSPRNG.
 */
interface ApiKeySecretGenerator
{
    public function generate(Environment $environment): GeneratedApiKeySecret;
}
