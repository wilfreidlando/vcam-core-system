<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers\Waha;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\NotificationChannel;
use Core\Notification\Contracts\RequestChannelSessionPairingCode;
use Core\Notification\Contracts\RequestChannelSessionPairingCodeCommand;
use Core\Notification\Presentation\Http\Requests\RequestChannelSessionPairingCodeRequest;
use Core\Support\Http\ProblemDetails;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
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
     *
     * Requires the `notification_channel_sessions:write` scope. Only for a session already
     * created for your project by the platform administrator (otherwise
     * `422 channel-session-not-found`; this call never creates one). The code is entered in
     * WhatsApp › Linked devices › Link with phone number, and WhatsApp notifies the given number
     * — only use a number you own.
     *
     * @throws ChannelSessionNotFound the session does not exist for this project (422)
     * @throws ChannelDoesNotSupportSessions this channel's provider has no linked-device session concept (422)
     * @throws ProviderPermanentError|ProviderAuthError the provider rejected the request outright (502)
     * @throws ProviderTransientError|ProviderDisabledException|CircuitOpenException the provider is temporarily unavailable (503)
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
