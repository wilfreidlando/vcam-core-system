<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Events\Contracts\RelayOutboxEvents;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Payment\Contracts\GetPaymentTimeline;
use Core\Payment\Contracts\PaymentTimelineEntryView;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Webhook\Contracts\GetWebhookDeliveryDetails;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ADR-0059 — the user's requirement, end to end: "si on fait un paiement
 * je dois voir vraiment tout le parcours du payload à la réponse finale,
 * l'appel des webhooks". Real HTTP in, `Http::fake()` only at the two
 * outbound boundaries (MyCoolPay, the integrator's webhook endpoint).
 */
final class PaymentJourneyFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PRIVATE_KEY = 'journey-private-key';

    #[Test]
    public function every_step_from_api_request_to_webhook_delivery_is_recorded_with_one_correlation_thread(): void
    {
        [$token, $projectId] = $this->authenticatedProject();
        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: 'my-coolpay',
            publicKey: 'pub-journey',
            privateKey: self::PRIVATE_KEY,
        ));

        Http::fake([
            'my-coolpay.com/api/pub-journey/payin' => Http::response([
                'status' => 'success',
                'transaction_ref' => 'tx-journey-1',
                'action' => 'PENDING',
            ], 201),
            'example.com/hooks/core' => Http::response(['ok' => true], 200),
        ]);

        $this->postJson('/v1/webhook-endpoints', [
            'url' => 'https://example.com/hooks/core',
            'events' => ['payment.status_changed'],
        ], ['Authorization' => "Bearer {$token}"])->assertStatus(201);

        // ① The integrator starts a payment.
        $start = $this->postJson('/v1/payments/payin', [
            'channel' => 'mtn_momo',
            'amount' => 2_500,
            'currency' => 'XAF',
            'reason' => 'Order #J1',
            'customer_phone_number' => '+237670000001',
        ], [
            'Authorization' => "Bearer {$token}",
            'Idempotency-Key' => (string) Str::uuid(),
            'Correlation-Id' => 'journey-start-1',
        ]);
        $start->assertStatus(201);
        $paymentId = $start->json('id');
        self::assertIsString($paymentId);

        // ② MyCoolPay calls back (from its trusted IP, correctly signed).
        $callback = [
            'application' => 'pub-journey',
            'transaction_ref' => 'tx-journey-1',
            'app_transaction_ref' => 'irrelevant',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 2500,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'MTN',
            'transaction_status' => 'SUCCESS',
        ];
        $callback['signature'] = md5('tx-journey-1PAYIN2500XAFMTN'.self::PRIVATE_KEY);

        $this->call('POST', '/webhooks/my-coolpay', server: [
            'REMOTE_ADDR' => '15.236.140.89',
            'CONTENT_TYPE' => 'application/json',
            'HTTP_CORRELATION_ID' => 'journey-callback-1',
        ], content: (string) json_encode($callback))->assertOk();

        // ③ The relay publishes; the webhook is delivered (sync queue in tests).
        app(RelayOutboxEvents::class)->relay(100);

        $timeline = app(GetPaymentTimeline::class)->forPayment($paymentId);
        $types = array_map(static fn (PaymentTimelineEntryView $e): string => $e->type, $timeline);

        foreach ([
            'api_request_received',
            'provider_exchange',
            'initiated',
            'api_response_sent',
            'provider_callback_received',
            'status_changed',
            'event_published',
        ] as $expected) {
            self::assertContains($expected, $types, "Missing journey step {$expected}; got ".implode(', ', $types));
        }

        $byType = [];
        foreach ($timeline as $entry) {
            $byType[$entry->type] ??= $entry;
        }

        // The request body is there, the provider exchange too — with secrets masked.
        $encoded = (string) json_encode($timeline, JSON_UNESCAPED_SLASHES);
        self::assertStringContainsString('+237670000001', $encoded);
        self::assertStringNotContainsString(self::PRIVATE_KEY, $encoded);

        // Initiation steps share the API request's correlation id; the
        // callback steps share the provider callback's.
        self::assertSame('journey-start-1', $byType['api_request_received']->correlationId);
        self::assertSame('journey-start-1', $byType['provider_exchange']->correlationId);
        self::assertSame('journey-callback-1', $byType['status_changed']->correlationId);

        // ④ The webhook delivery of that event, with its attempt — request and response.
        $eventId = $byType['event_published']->details['event_id'] ?? null;
        self::assertIsString($eventId);

        $deliveries = app(GetWebhookDeliveryDetails::class)->forEvents([$eventId]);
        self::assertCount(1, $deliveries);
        self::assertSame('delivered', $deliveries[0]->status);
        self::assertCount(1, $deliveries[0]->attempts);

        $attempt = $deliveries[0]->attempts[0];
        self::assertSame(200, $attempt->responseStatus);
        self::assertSame('delivered', $attempt->outcome);
        self::assertSame('***REDACTED***', $attempt->requestHeaders['X-Signature']);
        self::assertStringContainsString('"type":"payment.status_changed"', $attempt->requestBody);
        // The delivery job ran under the callback request's thread (Context → outbox → relay → job).
        self::assertSame('journey-callback-1', $attempt->correlationId);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function authenticatedProject(): array
    {
        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Journey Org'), actor: 'service:test', correlationId: null);
        $project = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'Journey Project'), actor: 'service:test', correlationId: null)->project;
        $issued = app(IssueApiKey::class)->issue(
            new IssueApiKeyCommand($project->id, ['payments:write', 'payments:read', 'webhooks:write'], replaceExisting: true),
            actor: 'service:test',
            correlationId: null,
        );

        return [$issued->plaintextToken, $project->id];
    }
}
