<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * ADR-0133 §3 — the Core signs a request it addresses to a client application.
 *
 * The Core does not only **deliver** events to an endpoint; it may also
 * **read** from the application behind it (the form of its tenants, the
 * tenants it already has). The credential is the same one both sides
 * already share: the endpoint's signing secret, used with the same scheme as
 * the events — `X-Signature = HMAC-SHA256(timestamp + "." + body)`,
 * `X-Timestamp`, 300 s of tolerance.
 *
 * This contract exists so that **the secret never leaves the module that
 * owns it**: the caller receives the headers to send, never the secret.
 * The previous secret is signed too, while a rotation's overlap lasts
 * (ADR-0082 §5): the rotation does not break the branchement.
 */
interface SignEndpointRequest
{
    public function sign(SignEndpointRequestCommand $command): SignedEndpointRequest;
}
