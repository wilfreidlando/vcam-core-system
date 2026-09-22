<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use InvalidArgumentException;

/**
 * A Project-owned delivery target. `secret` is always the plaintext value
 * while held in memory — encryption at rest is an Infrastructure concern
 * (`EloquentWebhookEndpointRepository`, ADR-0040 §5), never something the
 * Domain knows about.
 */
final readonly class WebhookEndpoint
{
    /**
     * @param list<string> $subscribedEventTypes empty or containing `*` means "every event type"
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $url,
        public string $secret,
        public array $subscribedEventTypes,
        public bool $active,
    ) {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new InvalidArgumentException(sprintf(
                'Webhook endpoint URL must be http(s), got "%s".',
                $url,
            ));
        }
    }

    public function subscribesTo(string $eventType): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->subscribedEventTypes === []
            || in_array('*', $this->subscribedEventTypes, true)
            || in_array($eventType, $this->subscribedEventTypes, true);
    }

    public function deactivated(): self
    {
        return new self($this->id, $this->projectId, $this->url, $this->secret, $this->subscribedEventTypes, false);
    }

    public function activated(): self
    {
        return new self($this->id, $this->projectId, $this->url, $this->secret, $this->subscribedEventTypes, true);
    }

    /**
     * @param list<string> $subscribedEventTypes
     */
    public function withUrlAndEvents(string $url, array $subscribedEventTypes): self
    {
        return new self($this->id, $this->projectId, $url, $this->secret, $subscribedEventTypes, $this->active);
    }
}
