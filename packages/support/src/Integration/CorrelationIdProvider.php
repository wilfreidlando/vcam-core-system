<?php

declare(strict_types=1);

namespace Core\Support\Integration;

/**
 * Port for retrieving the current correlation id.
 *
 * The HTTP `CorrelationId` middleware
 * ({@see \Core\Support\Http\Middleware\CorrelationId}) writes the
 * inbound correlation id to the Laravel Context. The
 * `ExternalApiCaller` needs to forward that id on every outbound call
 * so that an incident on the provider side is correlatable back to
 * the consumer's request.
 *
 * Splitting this read into a port lets the foundation stay decoupled
 * from the framework (the production implementation reads from
 * Laravel Context; tests provide a fake that returns a fixed value).
 */
interface CorrelationIdProvider
{
    public function current(): ?string;
}
