<?php

declare(strict_types=1);

namespace Core\Identity\Domain\Model;

/**
 * The raw material for a new credential, fresh out of the generator: a
 * visible prefix and a high-entropy secret, neither hashed nor persisted yet.
 */
final readonly class GeneratedApiKeySecret
{
    public function __construct(
        public string $prefix,
        public string $secret,
    ) {}
}
