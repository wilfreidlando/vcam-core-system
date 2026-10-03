<?php

declare(strict_types=1);

use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Model\PaymentCallbackSendOutcome;
use Core\Payment\Domain\Port\PaymentCallbackSender;
use Core\Payment\PaymentStatus;
use Core\Payment\Presentation\Jobs\DeliverPaymentCallbackJob;
use Core\Payment\Tests\Unit\Fakes\InMemoryPaymentRepository;

/**
 * ADR-0055 — the permanent-vs-transient fork on the payment callback
 * plane. A 4xx other than 408/429 must short-circuit through the queue's
 * `fail()` path instead of `throw`, so the 8-attempt backoff over ~2h
 * isn't burned on a defect no retry can fix (the documented 405 case
 * from production).
 *
 * The job is `final` and uses Laravel's `InteractsWithQueue` trait, so
 * the test asserts the contract through the only observation the
 * production code cares about: did `handle()` throw (queue will retry)
 * or did it return cleanly (queue will mark failed and call `failed()`)?
 */
final class DeliverPaymentCallbackJobTest extends PHPUnit\Framework\TestCase
{
    private InMemoryPaymentRepository $payments;

    private RecordingCallbackSender $sender;

    public function test_no_op_when_payment_has_no_callback_url(): void
    {
        $payment = $this->makePayment(callbackUrl: null);
        $this->payments->save($payment);

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');
        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());

        self::assertSame(0, $this->sender->callCount, 'sender must not be called when callbackUrl is null');
    }

    /**
     * ADR-0055: a 405 (or any permanent 4xx) on the very first attempt
     * must NOT throw — if it threw, Laravel's queue would retry in 30s,
     * then 60s, then 120s, ..., burning ~2h of worker capacity on a
     * defect no amount of retry will fix. The production test in
     * commit 6e37ff2's logs (`Callback endpoint responded with HTTP 405.
     *`) is the case this locks down.
     */
    public function test_permanent_failure_does_not_throw_so_the_queue_skips_retries(): void
    {
        $payment = $this->makePayment(callbackUrl: 'https://merchant.example.com/hook');
        $this->payments->save($payment);

        $this->sender->nextOutcome = PaymentCallbackSendOutcome::failure(405, 'wrong method');

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');

        // No expectException — the contract is "returns cleanly".
        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());

        self::assertSame(1, $this->sender->callCount, 'sender called exactly once');
    }

    public function test_transient_failure_throws_so_the_queue_retries(): void
    {
        $payment = $this->makePayment(callbackUrl: 'https://merchant.example.com/hook');
        $this->payments->save($payment);

        $this->sender->nextOutcome = PaymentCallbackSendOutcome::failure(503, 'service unavailable');

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('service unavailable');

        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());
    }

    public function test_network_failure_status_null_is_treated_as_transient(): void
    {
        $payment = $this->makePayment(callbackUrl: 'https://merchant.example.com/hook');
        $this->payments->save($payment);

        $this->sender->nextOutcome = PaymentCallbackSendOutcome::failure(null, 'connection refused');

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');

        $this->expectException(RuntimeException::class);
        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());
    }

    public function test_429_is_transient_even_though_it_is_4xx(): void
    {
        $payment = $this->makePayment(callbackUrl: 'https://merchant.example.com/hook');
        $this->payments->save($payment);

        $this->sender->nextOutcome = PaymentCallbackSendOutcome::failure(429, 'slow down');

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');

        $this->expectException(RuntimeException::class);
        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());
    }

    public function test_408_is_transient(): void
    {
        $payment = $this->makePayment(callbackUrl: 'https://merchant.example.com/hook');
        $this->payments->save($payment);

        $this->sender->nextOutcome = PaymentCallbackSendOutcome::failure(408, 'request timeout');

        $job = new DeliverPaymentCallbackJob('mycoolpay', 'tx-1');

        $this->expectException(RuntimeException::class);
        $job->handle($this->payments, $this->sender, Core\Payment\Tests\Unit\Fakes\InMemoryPaymentTimeline::journey());
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->payments = new InMemoryPaymentRepository;
        $this->sender = new RecordingCallbackSender;
    }

    private function makePayment(?string $callbackUrl): Payment
    {
        return new Payment(
            id: '01a00000-0000-0000-0000-000000000001',
            projectId: '01a00000-0000-0000-0000-000000000001',
            channel: 'mtn_momo',
            providerCode: 'mycoolpay',
            idempotencyKey: 'idem-1',
            providerTransactionRef: 'tx-1',
            amount: 1000,
            currency: 'XAF',
            status: PaymentStatus::Succeeded,
            requiresAction: false,
            actionHint: null,
            paymentUrl: null,
            errorMessage: null,
            returnUrl: null,
            walletRecipientExternalRef: null,
            callbackUrl: $callbackUrl,
            callbackSecret: $callbackUrl === null ? null : 'shh',
        );
    }
}

/**
 * Sender fake that returns whatever outcome `nextOutcome` is set to,
 * counting invocations so a test can assert "called once and failed"
 * without mocking frameworks.
 */
final class RecordingCallbackSender implements PaymentCallbackSender
{
    public ?PaymentCallbackSendOutcome $nextOutcome = null;

    public int $callCount = 0;

    public function send(Payment $payment): PaymentCallbackSendOutcome
    {
        $this->callCount++;

        return $this->nextOutcome ?? throw new RuntimeException('RecordingCallbackSender: nextOutcome not set');
    }
}
