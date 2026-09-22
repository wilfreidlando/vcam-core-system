<?php

declare(strict_types=1);

namespace Core\Notification\Presentation\Http\Controllers\Waha;

use Core\Identity\Contracts\ProjectContext;
use Core\Notification\Contracts\ChannelDoesNotSupportSessions;
use Core\Notification\Contracts\GetChannelSessionCommand;
use Core\Notification\Contracts\GetChannelSessionQrCode;
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
 * `GET /v1/notification-channel-sessions/{channel}/{sessionRef}/qr` — a
 * thin proxy to whichever provider is routed for `channel` (ADR-0042
 * §4): the Core persists nothing about the session, `sessionRef` is
 * forwarded to the provider as-is.
 */
final class GetChannelSessionQrCodeController
{
    public function __construct(
        private readonly GetChannelSessionQrCode $getQrCode,
    ) {}

    /**
     * Get a scannable QR code for linking a phone to this channel session.
     *
     * Requires the `notification_channel_sessions:read` scope. Lazily
     * starts the session on the resolved provider if it isn't already
     * running.
     *
     * @throws ChannelDoesNotSupportSessions this channel's provider has no linked-device session concept (422)
     * @throws ProviderPermanentError|ProviderAuthError the provider rejected the request outright (502)
     * @throws ProviderTransientError|ProviderDisabledException|CircuitOpenException the provider is temporarily unavailable (503)
     */
    public function __invoke(string $channel, string $sessionRef, ProjectContext $project): JsonResponse|Response
    {
        if (! $project->hasScope('notification_channel_sessions:read')) {
            return ProblemDetails::forbidden('The notification_channel_sessions:read scope is required.');
        }

        $qr = $this->getQrCode->get(new GetChannelSessionCommand(
            projectId: $project->projectId,
            channel: NotificationChannel::fromString($channel),
            sessionRef: $sessionRef,
        ));

        return new JsonResponse([
            'mime_type' => $qr->mimeType,
            'data' => $qr->base64Data,
        ]);
    }
}
