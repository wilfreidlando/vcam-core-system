<?php

declare(strict_types=1);

namespace Core\Notification\Tests\Unit\Fakes;

use Core\Notification\Domain\Model\NotificationTemplate;
use Core\Notification\Domain\Port\NotificationTemplateRepository;

final class InMemoryNotificationTemplateRepository implements NotificationTemplateRepository
{
    /** @var list<NotificationTemplate> */
    private array $templates = [];

    public function add(NotificationTemplate $template): void
    {
        $this->templates[] = $template;
    }

    public function findLatest(string $projectId, string $key, string $channel): ?NotificationTemplate
    {
        $latest = null;
        foreach ($this->templates as $template) {
            if ($template->projectId !== $projectId || $template->key !== $key || $template->channel !== $channel) {
                continue;
            }
            if ($latest === null || $template->version > $latest->version) {
                $latest = $template;
            }
        }

        return $latest;
    }
}
