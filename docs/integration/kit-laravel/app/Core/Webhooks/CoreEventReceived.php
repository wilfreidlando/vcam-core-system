<?php

declare(strict_types=1);

namespace App\Core\Webhooks;

/**
 * Dispatched once per verified, non-duplicate event from the Core. Listen to it
 * (one listener per concern) — this is where YOUR business reacts:
 *
 *   Event::listen(CoreEventReceived::class, function (CoreEventReceived $e) {
 *       if ($e->type === 'payment.status_changed' && $e->data['status'] === 'succeeded') { ... }
 *   });
 */
final readonly class CoreEventReceived
{
    /**
     * @param array<string, mixed> $data the event's `data`, as documented in docs/integration/webhooks.md
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {}

    public function is(string $prefix): bool
    {
        return $this->type === $prefix || str_starts_with($this->type, $prefix.'.');
    }
}
