<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use Core\Payment\PaymentStatus;

/**
 * Port that translates a raw provider status string into the canonical
 * `PaymentStatus` enum.
 *
 * The integration foundation provides a generic `StatusMapper` port
 * (`Core\Support\Integration\Mapping\StatusMapper`) but it is templated
 * over any `\BackedEnum`. The payment module narrows it to its own
 * canonical enum so callers don't have to assert the return type at every
 * call site — and so the `Presentation` layer never needs to import an
 * `Infrastructure` concrete mapper (hexagonal G3c).
 *
 * Adapter implementations live in `Core\Payment\Infrastructure\Provider\*`
 * and MUST be the only place where raw provider status strings appear.
 */
interface PaymentStatusMapper
{
    /**
     * @throws \Core\Support\Integration\Exception\UnknownProviderStatusException
     *                                                                            when the raw value is not part of the adapter's vocabulary.
     */
    public function map(string $raw): PaymentStatus;
}
