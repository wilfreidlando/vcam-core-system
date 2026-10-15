<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ChannelSessionNotFound;
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
