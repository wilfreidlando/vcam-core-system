<?php

declare(strict_types=1);

namespace Core\Payment\Tests\Integration\Presentation\Http\Controllers;

use Core\Payment\Contracts\Events\PaymentStatusChanged;
use Core\Payment\Contracts\PaymentStatusChangeHandler;
use Core\Payment\Contracts\PaymentStatusMapper;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Infrastructure\Provider\MyCoolPay\MyCoolPayCallbackAuthenticator;
use Core\Payment\PaymentStatus;
use Core\Payment\Presentation\Http\Controllers\MyCoolPayWebhookController;
use Core\Payment\Tests\Unit\Fakes\FixedClock;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline;
use Core\Payment\Tests\Unit\Fakes\InMemoryProviderApplicationRepository;
use Core\Support\Integration\Redaction\PayloadRedactor;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Tests\TestCase;

/**
 * Audit 2026-10-29 #3 — the MAC of a MyCoolPay callback (md5 of the signed
 * fields + the application's private key) replays a forged callback. It was
 * persisted in clear in `payment_timeline_entries.details` and shown in the
 * console: neither an authentic nor a rejected callback may leave it there.
 */
final class MyCoolPayWebhookSignatureRedactionTest extends TestCase
{
    private const PRIVATE_KEY = 'sk';

    #[Test]
    public function an_authentic_callback_never_persists_the_signature_in_clear(): void
    {
        [$webhook, $timeline, $signature] = $this->wire(self::PRIVATE_KEY);

        $response = $webhook($this->request($this->body($signature)));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(1, count($timeline->entries));
        $this->assertNoClearSignature($timeline, $signature);
        self::assertTrue($timeline->entries[0]->details['signature_present']);
    }

    #[Test]
    public function a_rejected_callback_never_persists_the_forged_signature_in_clear(): void
    {
        [$webhook, $timeline] = $this->wire('another-private-key');
        $forged = md5('forged');

        $response = $webhook($this->request($this->body($forged)));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(1, count($timeline->entries));
        $this->assertNoClearSignature($timeline, $forged);
    }

    private function assertNoClearSignature(InMemoryPaymentTimeline $timeline, string $signature): void
    {
        $persisted = json_encode($timeline->entries[0]->details, JSON_THROW_ON_ERROR);

        self::assertStringNotContainsString($signature, $persisted);
        self::assertIsArray($timeline->entries[0]->details['body']);
        self::assertArrayNotHasKey('signature', $timeline->entries[0]->details['body']);
    }

    /**
     * @return array{0: MyCoolPayWebhookController, 1: InMemoryPaymentTimeline, 2: string}
     */
    private function wire(string $privateKey): array
    {
        $payments = new InMemoryPaymentRepository;
        $payments->create(new Payment(
            id: 'payment-1',
            projectId: 'project-1',
            channel: 'mtn_momo',
            providerCode: 'my-coolpay',
            idempotencyKey: 'order-42',
            providerTransactionRef: 'tx-abc',
            amount: 1_000,
            currency: 'XAF',
            status: PaymentStatus::Pending,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
            callbackUrl: null,
        ));
        $timeline = new InMemoryPaymentTimeline;

        $webhook = new MyCoolPayWebhookController(
            statusChangeHandler: new class implements PaymentStatusChangeHandler
            {
                public function handle(PaymentStatusChanged $event, string $source = 'provider_callback'): void {}
            },
            statusMapper: new class implements PaymentStatusMapper
            {
                public function map(string $raw): PaymentStatus
                {
                    return PaymentStatus::Succeeded;
                }
            },
            clock: new FixedClock,
            applications: new InMemoryProviderApplicationRepository([
                new ProviderApplication(projectId: 'project-1', providerCode: 'my-coolpay', publicKey: 'pub-test-1234', privateKey: $privateKey),
            ]),
            authenticator: new MyCoolPayCallbackAuthenticator([]),
            logger: new NullLogger,
            payments: $payments,
            journey: InMemoryPaymentTimeline::journey($timeline),
            redactor: new PayloadRedactor,
        );

        // The MAC the provider computes with the REAL private key.
        $mac = md5('tx-abc'.'PAYIN'.'1000'.'XAF'.'CM_OM'.self::PRIVATE_KEY);

        return [$webhook, $timeline, $mac];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(string $signature): array
    {
        return [
            'application' => 'pub-test-1234',
            'app_transaction_ref' => 'order-42',
            'transaction_ref' => 'tx-abc',
            'transaction_type' => 'PAYIN',
            'transaction_amount' => 1000,
            'transaction_currency' => 'XAF',
            'transaction_operator' => 'CM_OM',
            'transaction_status' => 'SUCCESS',
            'signature' => $signature,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        $request = Request::create('/webhooks/my-coolpay', 'POST', [], [], [], [], json_encode($body, JSON_THROW_ON_ERROR));
        $request->headers->set('Content-Type', 'application/json');

        return $request;
    }
}
