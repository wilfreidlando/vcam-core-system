<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers\Waha;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\ChannelSessionNotFound;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionScreenshot;
use Core\Notification\Contracts\NotificationChannel;
use Core\Support\Http\ProblemDetails;
use Core\Support\Integration\CircuitBreaker\CircuitOpenException;
use Core\Support\Integration\Exception\ProviderAuthError;
use Core\Support\Integration\Exception\ProviderDisabledException;
use Core\Support\Integration\Exception\ProviderPermanentError;
use Core\Support\Integration\Exception\ProviderTransientError;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /v1/notification-channel-sessions/{channel}/{sessionRef}/screenshot`
 * — same thin-proxy shape as `GetChannelSessionQrCodeController`.
 */
final class GetChannelSessionScreenshotController
{
    public function __construct(
        private readonly GetChannelSessionScreenshot $getScreenshot,
    ) {}

    /**
     * Get a live screenshot of the linked-device session's current screen.
     *
     * Requires the `notification_channel_sessions:read` scope. Only for a session already
     * created for your project by the platform administrator (otherwise
     * `422 channel-session-not-found`; this call never creates one). Useful to check whether the
     * session is linked to a phone.
     *
     * @throws ChannelSessionNotFound the session does not exist for this project (422)
     * @throws ChannelDoesNotSupportSessions this channel's provider has no linked-device session concept (422)
     * @throws ProviderPermanentError|ProviderAuthError the provider rejected the request outright (502)
     * @throws ProviderTransientError|ProviderDisabledException|CircuitOpenException the provider is temporarily unavailable (503)
     */
    public function __invoke(string $channel, string $sessionRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notification_channel_sessions:read')) {
            return ProblemDetails::forbidden('The notification_channel_sessions:read scope is required.');
        }

        $screenshot = $this->getScreenshot->get(new GetChannelSessionCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($channel),
            sessionRef: $sessionRef,
        ));

        return new JsonResponse([
            'mime_type' => $screenshot->mimeType,
            'data' => $screenshot->base64Data,
        ]);
    }
}
