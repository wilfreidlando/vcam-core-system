<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * The cookie value to set (shown once, never stored) and where to land.
 */
final readonly class ExchangedPortalSession
{
    public function __construct(
        public string $cookieValue,
        public string $landingPath,
        public int $cookieLifetimeSeconds,
    ) {}
}
