<?php

declare(strict_types=1);

namespace Core\Webhook\Application\UseCase;

use Core\Support\Clock;
use Core\Support\Integration\Configuration\SignatureAlgorithm;
use Core\Support\Integration\Configuration\SignaturePolicy;
use Core\Support\Integration\Signing\RequestSigner;
use Core\Webhook\Contracts\SignedEndpointRequest;
use Core\Webhook\Contracts\SignEndpointRequest;
use Core\Webhook\Contracts\SignEndpointRequestCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Domain\Port\WebhookEndpointRepository;

/**
 * ADR-0133 §3 — sign a request addressed to a client application.
 *
 * The scheme is the one already in service for the events:
 * `X-Signature = HMAC-SHA256(timestamp + "." + body)`, `X-Timestamp`.
 * The secret is the endpoint's own — the one the two sides already share —
 * and it is decrypted here, used, and forgotten: it is never returned, and
 * never rendered.
 *
 * ADR-0082 §5 — while a rotation's overlap lasts, the same body is signed
 * with the previous secret too (`X-Signature-Previous`): the application
 * that has not switched yet still accepts the request, and the rotation
 * does not break the branchement.
 */
final readonly class SignEndpointRequestUseCase implements SignEndpointRequest
{
    public function __construct(
        private WebhookEndpointRepository $endpoints,
        private RequestSigner $signer,
        private Clock $clock,
    ) {}

    public function sign(SignEndpointRequestCommand $command): SignedEndpointRequest
    {
        $endpoint = $this->endpoints->find($command->endpointId);

        // The endpoint must belong to the project: a signed request never
        // crosses the boundary of the project that asked for it.
        if ($endpoint === null || $endpoint->projectId !== $command->projectId) {
            throw WebhookEndpointNotFound::withId($command->endpointId);
        }

        $now = $this->clock->now();
        $timestamp = $command->timestamp ?? $now->getTimestamp();

        $headers = [];

        $this->signer->sign(
            new SignaturePolicy(algorithm: SignatureAlgorithm::HmacSha256, secret: $endpoint->secret),
            $headers,
            $command->body,
            $timestamp,
        );

        $previousSecret = $endpoint->previousSecretAt($now);

        if ($previousSecret !== null) {
            $previousHeaders = [];

            $this->signer->sign(
                new SignaturePolicy(algorithm: SignatureAlgorithm::HmacSha256, secret: $previousSecret),
                $previousHeaders,
                $command->body,
                $timestamp,
            );

            $headers['X-Signature-Previous'] = $previousHeaders['X-Signature'];
        }

        return new SignedEndpointRequest($headers);
    }
}
