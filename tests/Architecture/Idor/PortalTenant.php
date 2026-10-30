<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

/**
 * One customer of the scenario, its project, and a random value planted in
 * its names and in its data (`$secret`) so a leak is unmistakable.
 *
 * The tenant of the portal is the **customer**, not the project: a project
 * hosts many customers, and the portal of a customer shows only what that
 * customer owns (ADR-0075 §3).
 */
final readonly class PortalTenant
{
    public function __construct(
        public string $projectId,
        public string $customerId,
        public string $customerRef,
        public string $secret,
        public string $invoiceId,
        public string $subscriptionId,
        public string $planCode,
    ) {}

    /**
     * The values of this customer that must never reach another customer.
     *
     * @return list<string>
     */
    public function markers(): array
    {
        return [
            $this->secret,
            $this->customerId,
            $this->customerRef,
            $this->invoiceId,
            $this->subscriptionId,
            $this->planCode,
        ];
    }
}
