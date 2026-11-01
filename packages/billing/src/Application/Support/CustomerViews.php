<?php

declare(strict_types=1);

namespace Core\Billing\Application\Support;

use Core\Billing\Contracts\CustomerView;
use Core\Billing\Domain\Model\Customer;

/**
 * The mapping from the customer of the domain to the read model of
 * `Contracts/` (G3c — the domain model itself never leaves the module).
 */
final readonly class CustomerViews
{
    public static function from(Customer $customer): CustomerView
    {
        return new CustomerView(
            id: $customer->id,
            projectId: $customer->projectId,
            externalRef: $customer->externalRef,
            displayName: $customer->displayName,
            email: $customer->email,
            phone: $customer->phone,
            locale: $customer->locale,
            metadata: $customer->metadata,
            status: $customer->status->value,
            version: $customer->version,
            createdAt: Iso8601::of($customer->createdAt),
            updatedAt: Iso8601::of($customer->updatedAt),
            erasedAt: Iso8601::ofNullable($customer->erasedAt),
        );
    }
}
