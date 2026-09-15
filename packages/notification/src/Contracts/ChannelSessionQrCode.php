<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * A scannable QR code image, base64-encoded so it travels safely over
 * JSON. The Core never persists this — it's fetched live from the
 * provider on every call.
 */
final readonly class ChannelSessionQrCode
{
    public function __construct(
        public string $mimeType,
        public string $base64Data,
    ) {}
}
