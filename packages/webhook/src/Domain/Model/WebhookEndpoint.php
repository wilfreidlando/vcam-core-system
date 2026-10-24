<?php

declare(strict_types=1);

namespace Core\Webhook\Domain\Model;

use DateTimeImmutable;
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
        // ADR-0082 §5 — the secret replaced by the last rotation, kept until
        // `previousSecretExpiresAt` so deliveries carry both signatures.
        public ?string $previousSecret = null,
        public ?DateTimeImmutable $previousSecretExpiresAt = null,
        public ?DateTimeImmutable $secretRotatedAt = null,
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
        return $this->with(active: false);
    }

    public function activated(): self
    {
        return $this->with(active: true);
    }

    /**
     * @param list<string> $subscribedEventTypes
     */
    public function withUrlAndEvents(string $url, array $subscribedEventTypes): self
    {
        return $this->with(url: $url, subscribedEventTypes: $subscribedEventTypes);
    }

    /**
     * ADR-0082 §5 — the current secret becomes the previous one until
     * `$overlapEndsAt`. A second rotation inside the overlap drops the
     * oldest secret: only the last two are ever valid.
     */
    public function withRotatedSecret(string $newSecret, DateTimeImmutable $now, DateTimeImmutable $overlapEndsAt): self
    {
        return new self(
            $this->id,
            $this->projectId,
            $this->url,
            $newSecret,
            $this->subscribedEventTypes,
            $this->active,
            previousSecret: $this->secret,
            previousSecretExpiresAt: $overlapEndsAt,
            secretRotatedAt: $now,
        );
    }

    /** Ends the overlap now (emergency) or once it has run out. */
    public function withoutPreviousSecret(): self
    {
        return new self(
            $this->id,
            $this->projectId,
            $this->url,
            $this->secret,
            $this->subscribedEventTypes,
            $this->active,
            secretRotatedAt: $this->secretRotatedAt,
        );
    }

    /** The previous secret, while its overlap lasts; null otherwise. */
    public function previousSecretAt(DateTimeImmutable $now): ?string
    {
        if ($this->previousSecret === null || $this->previousSecretExpiresAt === null || $this->previousSecretExpiresAt <= $now) {
            return null;
        }

        return $this->previousSecret;
    }

    /**
     * @param list<string>|null $subscribedEventTypes
     */
    private function with(?string $url = null, ?array $subscribedEventTypes = null, ?bool $active = null): self
    {
        return new self(
            $this->id,
            $this->projectId,
            $url ?? $this->url,
            $this->secret,
            $subscribedEventTypes ?? $this->subscribedEventTypes,
            $active ?? $this->active,
            previousSecret: $this->previousSecret,
            previousSecretExpiresAt: $this->previousSecretExpiresAt,
            secretRotatedAt: $this->secretRotatedAt,
        );
    }
}
