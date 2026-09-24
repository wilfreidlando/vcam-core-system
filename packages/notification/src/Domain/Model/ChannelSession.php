<?php

declare(strict_types=1);

namespace Core\Notification\Domain\Model;

use DateTimeImmutable;

/**
 * A session an operator registered — for one Project, or global
 * (`projectId === null`, usable by every Project): a name and its owner,
 * nothing about the session's live state.
 */
final readonly class ChannelSession
{
    public function __construct(
        public string $id,
        public ?string $projectId,
        public string $channel,
        public string $sessionRef,
        public DateTimeImmutable $createdAt,
    ) {}

    /**
     * The name this session has at the provider.
     */
    public function providerName(): string
    {
        return ScopedSessionRef::forOwner($this->projectId, $this->sessionRef)->value;
    }
}
