<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Support\Http\ProblemDetails;
use Core\Webhook\Contracts\RotateWebhookSecret;
use Core\Webhook\Contracts\RotateWebhookSecretCommand;
use Core\Webhook\Domain\Exception\WebhookEndpointNotFound;
use Core\Webhook\Presentation\Http\Requests\RotateWebhookSecretRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

final class RotateWebhookSecretController
{
    public function __construct(
        private readonly RotateWebhookSecret $rotateWebhookSecret,
    ) {}

    /**
     * Rotate a webhook endpoint's signing secret.
     *
     * Returns the new secret, **shown once**. For `overlap_hours` (24 by
     * default, 1 to 72), every delivery carries two signatures:
     * `X-Signature` with the new secret and `X-Signature-Previous` with the
     * old one, same format and timestamp. Switch your verifier to the new
     * secret within that window; afterwards only `X-Signature` remains.
     * Requires the `webhooks:write` scope.
     *
     * @throws WebhookEndpointNotFound the endpoint doesn't exist, or belongs to another project (404)
     */
    public function __invoke(string $endpointId, RotateWebhookSecretRequest $request, ProjectContext $project): JsonResponse
    {
        if (! $project->hasScope('webhooks:write')) {
            return ProblemDetails::forbidden('The webhooks:write scope is required.');
        }

        $rotated = $this->rotateWebhookSecret->rotate(new RotateWebhookSecretCommand(
            projectId: $project->projectId,
            endpointId: $endpointId,
            overlapHours: $request->overlapHours(),
        ));

        // S-20 — a rotation hands out a fresh signing secret: warning level,
        // so an alert can fire on an unexpected one (the `webhook` module
        // does not reach `audit`; the console path audits its own).
        Log::warning('Webhook endpoint secret rotated.', [
            'endpoint_id' => $rotated->endpointId,
            'project_id' => $project->projectId,
            'api_key_id' => $project->apiKeyId,
            'previous_secret_expires_at' => $rotated->previousSecretExpiresAt->format(DATE_ATOM),
        ]);

        return new JsonResponse([
            'id' => $rotated->endpointId,
            /** The new signing secret. Shown once, never retrievable again. */
            'secret' => $rotated->secret,
            /** Until then, deliveries also carry `X-Signature-Previous`. */
            'previous_secret_expires_at' => $rotated->previousSecretExpiresAt->format(DATE_ATOM),
        ]);
    }
}
