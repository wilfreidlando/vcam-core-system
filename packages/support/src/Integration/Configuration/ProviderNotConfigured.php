<?php

declare(strict_types=1);

namespace Core\Support\Integration\Configuration;

use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * Raised when a provider is referenced by code that has not been
 * declared in `config/providers.php`.
 */
final class ProviderNotConfigured extends RuntimeException
{
    private readonly ProviderCode $providerCode;

    public function __construct(ProviderCode $code)
    {
        $this->providerCode = $code;
        parent::__construct(sprintf(
            'No provider configuration found for code "%s" in config/providers.php.',
            $code->toString(),
        ));
    }

    public function providerCode(): ProviderCode
    {
        return $this->providerCode;
    }
}
