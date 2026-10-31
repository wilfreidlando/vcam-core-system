<?php

declare(strict_types=1);

namespace Tests\Contract;

use Core\Webhook\Contracts\WebhookEventTypes;
use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Tests\TestCase;

/**
 * ADR-0107 §3 — the catalogue of subscribable events must list **everything
 * the Core emits**.
 *
 * `customer.created` was emitted since the lot 10 and could not be
 * subscribed by name: the catalogue closed on 25 types while `billing`
 * emitted eight more. A client application waiting for its tenant to exist
 * had no way to ask for that signal — and nothing said so.
 *
 * This test confronts the catalogue with the **source**: every event type a
 * module writes into the outbox must be in it, and the catalogue must not
 * promise an event nobody emits.
 */
final class EventCatalogueTest extends TestCase
{
    /**
     * Strings that look like an event type but are not one.
     *
     * @var list<string>
     */
    private const NOT_EVENT_TYPES = [
        // The quota capability of a WhatsApp send (ADR-0074 §2), not an event.
        'notification.whatsapp',
    ];

    #[Test]
    public function every_event_the_core_emits_is_subscribable_by_name(): void
    {
        $emitted = $this->emittedEventTypes();

        self::assertNotEmpty($emitted, 'the scan found no event at all — it is broken');

        foreach ($emitted as $type) {
            self::assertContains(
                $type,
                WebhookEventTypes::ALL,
                sprintf('`%s` is emitted by the Core but is not in WebhookEventTypes::ALL: a project cannot subscribe to it by name.', $type),
            );
        }
    }

    #[Test]
    public function the_catalogue_promises_no_event_the_core_never_emits(): void
    {
        $emitted = $this->emittedEventTypes();

        foreach (WebhookEventTypes::ALL as $type) {
            self::assertContains(
                $type,
                $emitted,
                sprintf('`%s` is in WebhookEventTypes::ALL but the Core never emits it: a subscriber would wait for an event that never comes.', $type),
            );
        }
    }

    /**
     * Every event type the modules write into the outbox, read from the
     * source: a module that emits an event names it as a literal.
     *
     * @return list<string>
     */
    private function emittedEventTypes(): array
    {
        $found = [];

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('packages')));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, 'new DomainEvent(')) {
                continue;
            }

            preg_match_all("/'([a-z][a-z0-9]*\.[a-z][a-z0-9_]*)'/", $source, $matches);

            foreach ($matches[1] as $literal) {
                if (preg_match('/^(payment|notification|wallet|subscription|invoice|customer|usage|plan|webhook)\./', $literal) !== 1) {
                    continue;
                }

                if (in_array($literal, self::NOT_EVENT_TYPES, true)) {
                    continue;
                }

                $found[$literal] = true;
            }
        }

        $types = array_keys($found);
        sort($types);

        return $types;
    }
}
