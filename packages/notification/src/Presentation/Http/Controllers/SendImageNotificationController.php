<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\SendImageNotification;
use Core\Notification\Contracts\SendImageNotificationCommand;
use Core\Notification\Presentation\Http\Requests\SendImageNotificationRequest;
use Core\Support\Http\ProblemDetails;
use Core\Support\Identifier;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/notifications/image` — see `SendTextNotificationController`
 * for the shared design rationale (ADR-0043, ADR-0056).
 */
final class SendImageNotificationController
{
    public function __construct(
        private readonly SendImageNotification $sendImage,
        private readonly Identifier $identifier,
    ) {}

    /**
     * Send an image message.
     *
     * Requires the `notifications:write` scope. No `Idempotency-Key`
     * HTTP header (ADR-0056) — supply `client_reference` in the body
     * if you want your own retry loop to be safe.
     */
    public function __invoke(SendImageNotificationRequest $request, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notifications:write')) {
            return ProblemDetails::forbidden('The notifications:write scope is required.');
        }

        /**
         * @var array{
         *     channel: string, to_party_ref: string, media_url: string,
         *     caption?: string, filename?: string, mimetype?: string,
         *     variables?: array<string, mixed>, client_reference?: string|null,
         * } $data
         */
        $data = $request->validated();

        $clientReference = $data['client_reference'] ?? null;
        $idempotencyKey = is_string($clientReference) && $clientReference !== ''
            ? $clientReference
            : $this->identifier->generate();

        $view = $this->sendImage->send(new SendImageNotificationCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($data['channel']),
            toPartyRef: $data['to_party_ref'],
            mediaUrl: $data['media_url'],
            caption: $data['caption'] ?? null,
            filename: $data['filename'] ?? null,
            mimetype: $data['mimetype'] ?? null,
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
