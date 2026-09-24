<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Model\ChannelSession;
use Core\Notification\Domain\Port\ChannelSessionRepository;
use DateTimeImmutable;

final class EloquentChannelSessionRepository implements ChannelSessionRepository
{
    public function save(ChannelSession $session): void
    {
        if ($this->find($session->projectId, $session->channel, $session->sessionRef) !== null) {
            return;
        }

        ChannelSessionRecord::query()->create([
            'id' => $session->id,
            'project_id' => $session->projectId,
            'channel' => $session->channel,
            'session_ref' => $session->sessionRef,
            'created_at' => $session->createdAt,
        ]);
    }

    public function find(?string $projectId, string $channel, string $sessionRef): ?ChannelSession
    {
        // `where('project_id', null)` is compiled to `IS NULL` by Laravel.
        $record = ChannelSessionRecord::query()
            ->where('project_id', $projectId)
            ->where('channel', $channel)
            ->where('session_ref', $sessionRef)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function findUsableFor(string $projectId, string $channel, string $sessionRef): ?ChannelSession
    {
        return $this->find($projectId, $channel, $sessionRef) ?? $this->find(null, $channel, $sessionRef);
    }

    public function findAllFor(?string $projectId, string $channel): array
    {
        $models = ChannelSessionRecord::query()
            ->where('project_id', $projectId)
            ->where('channel', $channel)
            ->get();

        $sessions = [];
        foreach ($models as $record) {
            $sessions[] = $this->toDomain($record);
        }
        usort(
            $sessions,
            static fn (ChannelSession $a, ChannelSession $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id],
        );

        return $sessions;
    }

    private function toDomain(ChannelSessionRecord $record): ChannelSession
    {
        return new ChannelSession(
            id: $record->id,
            projectId: $record->project_id,
            channel: $record->channel,
            sessionRef: $record->session_ref,
            createdAt: DateTimeImmutable::createFromInterface($record->created_at),
        );
    }
}
