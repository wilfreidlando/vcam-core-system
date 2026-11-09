<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Notices\CoreNoticeDispatcher;
use Core\Events\Contracts\OutboxEventPublished;
use Illuminate\Contracts\Queue\ShouldQueue;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * ADR-0118 — the Core's notices, on the relayed events.
 *
 * Registered explicitly in `AppServiceProvider::boot()` (this codebase's
 * convention — no auto-discovery). `ShouldQueue` means the composition
 * runs on Horizon, not inside the process running `events:relay`
 * (ADR-0040 §2).
 *
 * It never throws: the business event is already in the outbox, and a
 * notice that cannot be composed must not fail the relay (ADR-0118 §7).
 */
final readonly class CoreNoticeListener implements ShouldQueue
{
    public function __construct(
        private CoreNoticeDispatcher $dispatcher,
        private LoggerInterface $logger,
    ) {}

    public function handle(OutboxEventPublished $published): void
    {
        try {
            $this->dispatcher->dispatch($published->event);
        } catch (Throwable $e) {
            $this->logger->warning('core_notice.failed', [
                'project_id' => $published->event->projectId,
                'event_type' => $published->event->type,
                'event_id' => $published->event->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
