<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;

final class InMemoryChannelSessionRepository implements ChannelSessionRepository
{
    /** @var list<ChannelSession> */
    public array $sessions = [];

    public function save(ChannelSession $session): void
    {
        if ($this->find($session->projectId, $session->channel, $session->sessionRef) === null) {
            $this->sessions[] = $session;
        }
    }

    public function find(?string $projectId, string $channel, string $sessionRef): ?ChannelSession
    {
        foreach ($this->sessions as $session) {
            if ($session->projectId === $projectId && $session->channel === $channel && $session->sessionRef === $sessionRef) {
                return $session;
            }
        }

        return null;
    }

    public function findUsableFor(string $projectId, string $channel, string $sessionRef): ?ChannelSession
    {
        return $this->find($projectId, $channel, $sessionRef) ?? $this->find(null, $channel, $sessionRef);
    }

    public function findAllFor(?string $projectId, string $channel): array
    {
        return array_values(array_filter(
            $this->sessions,
            static fn (ChannelSession $s): bool => $s->projectId === $projectId && $s->channel === $channel,
        ));
    }
}
