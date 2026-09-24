<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

use RuntimeException;

/**
 * The session is registered but the provider refused to send from it
 * because no phone is linked (yet, or any more). Raised by the adapter
 * from the provider's refusal — the Core's registry cannot know this, it
 * only knows the session was registered — so the send ends `failed` with
 * this explicit message instead of an opaque "HTTP 422".
 */
final class ChannelSessionNotLinked extends RuntimeException
{
    /**
     * @param string $state `awaiting_link` (created, waiting for the scan) or `disconnected`
     */
    public function __construct(public readonly string $state)
    {
        parent::__construct(sprintf(
            'The WhatsApp session used for this send is not linked to a phone (%s). Ask the platform administrator to link it (QR code or pairing code in the admin console), then retry. Nothing was sent.',
            $state === 'awaiting_link' ? 'waiting for the QR code to be scanned' : 'disconnected',
        ));
    }
}
