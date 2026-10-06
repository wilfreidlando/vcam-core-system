<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

use Core\Notification\Domain\Exception\InvalidNotificationStatusTransition;
use Core\Notification\NotificationStatus;
use Core\Support\Environment;
use DateTimeImmutable;

/**
 * One notification this Core sent (or attempted to). `providerCode`/
 * `providerMessageId` are null until a provider attempt is pinned —
 * unlike `Core\Payment\Domain\Model\Payment` (always pinned at creation),
 * a notification can reach `failed` without ever having a provider
 * attempt succeed enough to pin one (every fallback failed at send time).
 *
 * `contentType` (`text`/`image`/`file`) records what kind of content was
 * sent, for audit — the content itself is never persisted (ADR-0043: no
 * template subsystem, content travels inline in the send request and is
 * gone once handed to the adapter).
 */
final readonly class Notification
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $channel,
        public string $toPartyRef,
        public string $contentType,
        public string $idempotencyKey,
        public NotificationStatus $status,
        public ?string $providerCode,
        public ?string $providerMessageId,
        public ?string $errorMessage,
        // ADR-0060 — the environment of the API key that sent it.
        public Environment $environment = Environment::Live,
        // ADR-0062 — what was sent and to whom, for the operator console.
        /** @var array<string, mixed> */
        public array $content = [],
        /** @var array<string, mixed> */
        public array $variables = [],
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {}

    /**
     * @throws InvalidNotificationStatusTransition when the transition is not
     *                                             valid per `NotificationStatus::canTransitionTo()`
     */
    public function withStatusChange(NotificationStatus $status, ?string $reason): self
    {
        if (! $this->status->canTransitionTo($status)) {
            throw InvalidNotificationStatusTransition::from($this->status, $status);
        }

        return new self(
            id: $this->id,
            projectId: $this->projectId,
            channel: $this->channel,
            toPartyRef: $this->toPartyRef,
            contentType: $this->contentType,
            idempotencyKey: $this->idempotencyKey,
            status: $status,
            providerCode: $this->providerCode,
            providerMessageId: $this->providerMessageId,
            errorMessage: $reason,
            environment: $this->environment,
            content: $this->content,
            variables: $this->variables,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }

    /**
     * Pins which provider actually accepted this message — set once, at
     * the moment a `send()` attempt succeeds, so a later status check or
     * inbound callback resolves the right adapter directly (mirrors
     * ADR-0038 §3/§4 for Payment).
     */
    public function withProviderAttempt(string $providerCode, string $providerMessageId): self
    {
        return new self(
            id: $this->id,
            projectId: $this->projectId,
            channel: $this->channel,
            toPartyRef: $this->toPartyRef,
            contentType: $this->contentType,
            idempotencyKey: $this->idempotencyKey,
            status: $this->status,
            providerCode: $providerCode,
            providerMessageId: $providerMessageId,
            errorMessage: $this->errorMessage,
            environment: $this->environment,
            content: $this->content,
            variables: $this->variables,
            createdAt: $this->createdAt,
            updatedAt: $this->updatedAt,
        );
    }
}
