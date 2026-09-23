<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendTextNotification;
use Core\Notification\Contracts\SendTextNotificationCommand;
use Core\Notification\Presentation\Http\Requests\SendTextNotificationRequest;
use Core\Support\Http\ProblemDetails;
use Core\Support\Identifier;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/notifications/text` — one of three content-typed send
 * endpoints (ADR-0043, replaces the single template-based
 * `POST /v1/notifications`). Routing by `channel` is unchanged: this
 * endpoint's shape never depends on which provider is configured behind
 * a channel — adding a second WhatsApp provider or an SMS provider is a
 * config + adapter change, never an endpoint change.
 */
final class SendTextNotificationController
{
    public function __construct(
        private readonly SendTextNotification $sendText,
        private readonly Identifier $identifier,
    ) {}

    /**
     * Send a text message.
     *
     * Requires the `notifications:write` scope. `to_party_ref` is a
     * correlation reference only — resolving it to an actual delivery
     * address is a provider adapter concern, not the Core's
     * (ADR-0041 §5).
     *
     * No `Idempotency-Key` HTTP header (ADR-0056): deduplication, when
     * the caller wants it, is supplied through the optional
     * `client_reference` body field (see the request schema). Without
     * that field, the Core generates a fresh UUID per request.
     */
    public function __invoke(SendTextNotificationRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:write')) {
            return ProblemDetails::forbidden('The notifications:write scope is required.');
        }

        /** @var array{
         *     channel: string, to_party_ref: string, text: string,
         *     variables?: array<string, mixed>, client_reference?: string|null,
         * } $data
         */
        $data = $request->validated();

        // ADR-0056: caller-supplied `client_reference` wins, otherwise
        // a fresh UUID per request. The domain layer's unique
        // constraint on `(project_id, idempotency_key)` is what
        // actually de-duplicates — no HTTP-level replay cache here.
        $clientReference = $data['client_reference'] ?? null;
        $idempotencyKey = is_string($clientReference) && $clientReference !== ''
            ? $clientReference
            : $this->identifier->generate();

        $view = $this->sendText->send(new SendTextNotificationCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($data['channel']),
            toPartyRef: $data['to_party_ref'],
            text: $data['text'],
            variables: $data['variables'] ?? [],
            idempotencyKey: $idempotencyKey,
        ));

        return new JsonResponse([
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status,
            'content_type' => $view->contentType,
            'error_message' => $view->errorMessage,
        ], 201);
    }
}
