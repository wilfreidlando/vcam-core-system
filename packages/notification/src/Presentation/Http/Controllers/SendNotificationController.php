<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendNotification;
use Core\Notification\Contracts\SendNotificationCommand;
use Core\Notification\Presentation\Http\Requests\SendNotificationRequest;
use Core\Support\Http\Middleware\RequireIdempotencyKey;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/notifications` — the one public entry point for sending a
 * notification (ADR-0041 §1: self-service only, nothing in the Core
 * triggers this automatically from an outbox event).
 */
final class SendNotificationController
{
    public function __construct(
        private readonly SendNotification $sendNotification,
    ) {}

    /**
     * Send a notification.
     *
     * Requires the `notifications:write` scope and an `Idempotency-Key`
     * header (spec §13). `to_party_ref` is a correlation reference only
     * — resolving it to an actual delivery address is a provider adapter
     * concern, not the Core's (ADR-0041 §5).
     */
    public function __invoke(SendNotificationRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:write')) {
            return ProblemDetails::forbidden('The notifications:write scope is required.');
        }

        $idempotencyKey = $request->header(RequireIdempotencyKey::HEADER);
        if (! is_string($idempotencyKey) || $idempotencyKey === '') {
            throw new LogicException('SendNotificationController reached without a validated Idempotency-Key header.');
        }

        /** @var array{channel: string, to_party_ref: string, template: string, variables?: array<string, mixed>} $data */
        $data = $request->validated();

        $view = $this->sendNotification->send(new SendNotificationCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($data['channel']),
            toPartyRef: $data['to_party_ref'],
            template: $data['template'],
            variables: $data['variables'] ?? [],
            idempotencyKey: $idempotencyKey,
        ));

        return new JsonResponse([
            'id' => $view->id,
            'channel' => $view->channel,
            'status' => $view->status->value,
            'template' => $view->templateKey,
            'error_message' => $view->errorMessage,
        ], 201);
    }
}
