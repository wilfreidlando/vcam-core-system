<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers\Waha;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Notification\Presentation\Http\Requests\RequestChannelSessionPairingCodeRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `POST /v1/notification-channel-sessions/{channel}/{sessionRef}/pairing-code`
 * — same thin-proxy shape as the QR endpoint, plus a `phone_number` body
 * field forwarded verbatim to the provider.
 */
final class RequestChannelSessionPairingCodeController
{
    public function __construct(
        private readonly RequestChannelSessionPairingCode $requestPairingCode,
    ) {}

    /**
     * Request a phone-number pairing code for linking a phone to this
     * channel session, as an alternative to scanning a QR code.
     *
     * Requires the `notification_channel_sessions:write` scope. Lazily
     * starts the session on the resolved provider if it isn't already
     * running.
     */
    public function __invoke(RequestChannelSessionPairingCodeRequest $request, string $channel, string $sessionRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notification_channel_sessions:write')) {
            return ProblemDetails::forbidden('The notification_channel_sessions:write scope is required.');
        }

        /** @var array{phone_number: string} $data */
        $data = $request->validated();

        $view = $this->requestPairingCode->request(new RequestChannelSessionPairingCodeCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($channel),
            sessionRef: $sessionRef,
            phoneNumber: $data['phone_number'],
        ));

        return new JsonResponse(['code' => $view->code]);
    }
}
