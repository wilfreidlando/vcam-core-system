<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendTextNotification;
use Core\Notification\Contracts\SendTextNotificationCommand;
use Core\Notification\Presentation\Http\Requests\SendTextNotificationRequest;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use LogicException;
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
    ) {}

    /**
     * Send a text message.
     *
     * Requires the `notifications:write` scope and an `Idempotency-Key`
     * header (spec §13). `to_party_ref` is a correlation reference only
     * — resolving it to an actual delivery address is a provider adapter
     * concern, not the Core's (ADR-0041 §5).
     */
    public function __invoke(SendTextNotificationRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:write')) {
            return ProblemDetails::forbidden('The notifications:write scope is required.');
        }

        $idempotencyKey = $request->header(RequireIdempotencyKey::HEADER);
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            throw new LogicException('SendTextNotificationController reached without a validated Idempotency-Key header.');
        }

        /** @var array{channel: string, to_party_ref: string, text: string, variables?: array<string, mixed>} $data */
        $data = $request->validated();

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
