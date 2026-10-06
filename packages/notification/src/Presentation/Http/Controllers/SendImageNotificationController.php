<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ChannelSessionNotFound;
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
     * Requires the `notifications:write` scope. The recipient's phone number goes in
     * `variables.phone_number`; `to_party_ref` is only a reference of your own (never a
     * contact address).
     *
     * The message is sent from the WhatsApp session named in `variables.session`. Sessions are
     * created and linked by the platform administrator — ask for one for your project. A name
     * that is not registered for your project (or shared with all projects) is refused with
     * `422 channel-session-not-found` and nothing is sent.
     *
     * The send is synchronous. The response is `201` **even when the send failed**: always check
     * `status` (`sent` or `failed`) and, when `failed`, `error_message` — for example a session
     * whose phone is not linked yet says so explicitly.
     *
     * Deduplication, when you want it, is done with the optional `client_reference` body field:
     * the same value on the same project returns the original notification without a second
     * send. Without it, every request is a new send. There is no `Idempotency-Key` header here.
     *
     * @throws ChannelSessionNotFound the `variables.session` named is not registered for this project — nothing is sent (422)
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
            environment: $project->environment,
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
