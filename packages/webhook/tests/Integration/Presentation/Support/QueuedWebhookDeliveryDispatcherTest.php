<?php

declare(strict_types=1);

namespace Core\Webhook\Tests\Integration\Presentation\Support;

use Core\Webhook\Contracts\DeliverWebhookCommand;
use Core\Webhook\Presentation\Jobs\DeliverWebhookJob;
use Core\Webhook\Presentation\Support\QueuedWebhookDeliveryDispatcher;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Tests\TestCase;

final class QueuedWebhookDeliveryDispatcherTest extends TestCase
{
    #[Test]
    public function it_dispatches_the_delivery_job_for_the_given_delivery(): void
    {
        Bus::fake();

        (new QueuedWebhookDeliveryDispatcher)->queue(new DeliverWebhookCommand('delivery-1'));

        Bus::assertDispatched(
            DeliverWebhookJob::class,
            static function (DeliverWebhookJob $job): bool {
                $property = new ReflectionProperty($job, 'command');
                /** @var DeliverWebhookCommand $command */
                $command = $property->getValue($job);

                return $command->deliveryId === 'delivery-1';
            },
        );
    }
}
