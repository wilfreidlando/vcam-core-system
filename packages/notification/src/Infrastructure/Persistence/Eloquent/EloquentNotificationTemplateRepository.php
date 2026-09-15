<?php

declare(strict_types=1);

namespace Core\Notification\Infrastructure\Persistence\Eloquent;

use Core\Notification\Domain\Model\NotificationTemplate;
use Core\Notification\Domain\Port\NotificationTemplateRepository;
use Core\Notification\NotificationTemplateKind;
use Core\Notification\NotificationTemplateStatus;

final class EloquentNotificationTemplateRepository implements NotificationTemplateRepository
{
    public function findLatest(string $projectId, string $key, string $channel): ?NotificationTemplate
    {
        // Eloquent forwards orderByDesc() to the query builder via __call;
        // its `@method static` docblock is a Laravel-wide documentation
        // convention, not a real static method (see EloquentWalletBalanceRepository).
        // @phpstan-ignore staticMethod.dynamicCall
        $record = NotificationTemplateRecord::query()
            ->where('project_id', $projectId)
            ->where('key', $key)
            ->where('channel', $channel)
            ->orderByDesc('version')
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    private function toDomain(NotificationTemplateRecord $record): NotificationTemplate
    {
        return new NotificationTemplate(
            id: $record->id,
            projectId: $record->project_id,
            key: $record->key,
            channel: $record->channel,
            kind: NotificationTemplateKind::from($record->kind),
            status: NotificationTemplateStatus::from($record->template_status),
            version: $record->version,
            body: $record->body,
        );
    }
}
