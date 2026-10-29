<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0075 §Sécurité — who a portal request is, once its cookie is checked.
 *
 * Every read the portal does is filtered by `(projectId, customerId)` of
 * this object; nothing in a URL or a form can widen it.
 */
final readonly class PortalPrincipal
{
    public function __construct(
        public string $sessionId,
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $flow,
        public ?string $flowRef,
        public ?string $returnUrl,
        public PortalBranding $branding,
    ) {}
}
