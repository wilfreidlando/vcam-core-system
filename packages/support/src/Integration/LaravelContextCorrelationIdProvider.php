<?php

declare(strict_types=1);

namespace Core\Support\Integration;

use Illuminate\Support\Facades\Context;

/**
 * Default `CorrelationIdProvider` — reads from Laravel's Context.
 *
 * The `CorrelationId` middleware writes the inbound correlation id
 * (or a freshly-generated one) into the Context before any business
 * logic runs. This implementation simply forwards whatever is there.
 *
 * Returns `null` when no correlation id is set, which the caller
 * treats as "don't add a Correlation-Id header on the wire".
 */
final readonly class LaravelContextCorrelationIdProvider implements CorrelationIdProvider
{
    public function current(): ?string
    {
        $value = Context::get('correlation_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
