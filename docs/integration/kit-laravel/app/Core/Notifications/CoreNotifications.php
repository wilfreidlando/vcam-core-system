<?php

declare(strict_types=1);

namespace App\Core\Notifications;

use App\Core\CoreClient;

/**
 * Send a message (WhatsApp today) through the Core. The content is yours: the
 * Core stores no template.
 *
 * `toPartyRef` is YOUR opaque reference of the recipient (logs only). The phone
 * number travels in `variables`, never in the reference. The WhatsApp session
 * is the one your Core administrator linked to the project
 * (`config('core.notifications.whatsapp_session')`); without it the Core
 * answers `422 channel-session-not-found` and sends nothing.
 *
 * Sending is asynchronous: `status` starts at `queued`/`sent`; the end state
 * arrives as `notification.delivered` / `notification.read` / `notification.failed`.
 */
final class CoreNotifications
{
    public function __construct(private readonly CoreClient $core) {}

    /**
     * @param string $clientReference YOUR id of this message: sent twice, delivered once
     * @return array{id: string, channel: string, status: string, error_message?: string|null}
     */
    public function text(string $toPartyRef, string $phone, string $text, string $clientReference): array
    {
        /** @var array{id: string, channel: string, status: string, error_message?: string|null} */
        return $this->core->post('notifications/text', $this->base($toPartyRef, $phone, $clientReference) + ['text' => $text], $clientReference);
    }

    /**
     * @return array{id: string, channel: string, status: string, error_message?: string|null}
     */
    public function image(string $toPartyRef, string $phone, string $imageUrl, string $clientReference, ?string $caption = null): array
    {
        /** @var array{id: string, channel: string, status: string, error_message?: string|null} */
        return $this->core->post('notifications/image', $this->base($toPartyRef, $phone, $clientReference) + array_filter(['media_url' => $imageUrl, 'caption' => $caption]), $clientReference);
    }

    /**
     * @return array{id: string, channel: string, status: string, error_message?: string|null}
     */
    public function file(string $toPartyRef, string $phone, string $fileUrl, string $filename, string $mimetype, string $clientReference, ?string $caption = null): array
    {
        /** @var array{id: string, channel: string, status: string, error_message?: string|null} */
        return $this->core->post('notifications/file', $this->base($toPartyRef, $phone, $clientReference) + array_filter([
            'media_url' => $fileUrl, 'filename' => $filename, 'mimetype' => $mimetype, 'caption' => $caption,
        ]), $clientReference);
    }

    /**
     * @return array{id: string, channel: string, status: string, error_message?: string|null}
     */
    public function status(string $notificationId): array
    {
        /** @var array{id: string, channel: string, status: string, error_message?: string|null} */
        return $this->core->get("notifications/{$notificationId}");
    }

    /**
     * @return array<string, mixed>
     */
    private function base(string $toPartyRef, string $phone, string $clientReference): array
    {
        return [
            'channel' => 'whatsapp',
            'to_party_ref' => $toPartyRef,
            'client_reference' => $clientReference,
            'variables' => ['phone_number' => $phone, 'session' => (string) config('core.notifications.whatsapp_session')],
        ];
    }
}
