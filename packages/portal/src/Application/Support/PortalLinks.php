<?php

declare(strict_types=1);

namespace Core\Portal\Application\Support;

/**
 * The absolute address of the portal. One place, so a link given to a
 * customer and a return address given to a payment provider agree.
 */
final readonly class PortalLinks
{
    public function __construct(private string $baseUrl) {}

    public function enter(string $token): string
    {
        return $this->absolute('/portal/enter/'.$token);
    }

    public function absolute(string $path): string
    {
        return rtrim($this->baseUrl, '/').$path;
    }
}
