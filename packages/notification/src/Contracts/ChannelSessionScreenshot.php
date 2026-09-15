<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * A live screenshot of the linked-device session's current screen
 * (useful to debug why linking is stuck), base64-encoded. Never
 * persisted by the Core.
 */
final readonly class ChannelSessionScreenshot
{
    public function __construct(
        public string $mimeType,
        public string $base64Data,
    ) {}
}
