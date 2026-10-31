<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

use Core\Portal\Domain\Model\PortalSession;

/**
 * ADR-0075 §Sécurité — who a portal request is, once its cookie is checked.
 *
 * Every read the portal does is filtered by `(projectId, customerId)` of
 * this object; nothing in a URL or a form can widen it.
 */
final readonly class PortalPrincipal
{
    /**
     * ADR-0075 §1 — the `read` flow opens a **window**, not a hand: the
     * session shows everything the customer sees and refuses every
     * gesture.
     *
     * The flag lives on the principal (and not on the workspace alone)
     * because the views read it too: a button that would be refused must
     * not be drawn. The refusal itself is the workspace's business — a
     * hand-crafted POST must fail the same way.
     */
    public bool $readOnly;

    public function __construct(
        public string $sessionId,
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $flow,
        public ?string $flowRef,
        public ?string $returnUrl,
        public PortalBranding $branding,
    ) {
        $this->readOnly = $flow === PortalSession::FLOW_READ;
    }
}
