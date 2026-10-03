<?php

declare(strict_types=1);

namespace Core\Notification\Contracts;

/**
 * ADR-0062 — everything the operator console shows about one notification.
 */
final readonly class NotificationDetailView
{
    /**
     * @param array<string, mixed> $content what was sent (text, media URL, caption…)
     * @param array<string, mixed> $variables recipient phone number, session…
     * @param list<array{provider_code: string, provider_message_id: string|null, status: string, attempt: int, error: string|null, created_at: string}> $attempts
     */
    public function __construct(
        public string $id,
        public string $projectId,
        public string $environment,
        public string $channel,
        public string $contentType,
        public string $toPartyRef,
        public string $clientReference,
        public string $status,
        public bool $isFinal,
        public ?string $providerCode,
        public ?string $providerMessageId,
        public ?string $errorMessage,
        public array $content,
        public array $variables,
        public ?string $createdAt,
        public ?string $updatedAt,
        public array $attempts,
    ) {}
}
