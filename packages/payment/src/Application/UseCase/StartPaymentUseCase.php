<?php

declare(strict_types=1);

namespace Core\Payment\Application\UseCase;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Contracts\StartPayment;
use Core\Payment\Contracts\StartPaymentCommand;
use Core\Payment\Domain\Exception\PaymentAlreadyExists;
use Core\Payment\Domain\Model\Payment;
use Core\Payment\Domain\Port\PaymentCallbackSecretGenerator;
use Core\Payment\Domain\Port\PaymentRepository;
use Core\Support\Identifier;
use LogicException;

/**
 * Starts a payment: resolves which provider serves the requested
 * `channel` for this Project (ADR-0038 §2), calls it, and persists the
 * result as the anchor future status checks and webhooks resolve
 * directly against (ADR-0038 §3).
 *
 * Idempotent on `(projectId, idempotencyKey)`: a replayed request with
 * the same key never calls the provider a second time — checked BEFORE
 * the provider call, not after, since the risk being guarded against is
 * charging the customer twice, not just writing a duplicate row.
 *
 * Deliberately not wrapped in `UnitOfWork::transactional()`: the only
 * write here is `PaymentRepository::create()`, which is already
 * transactionally safe on its own (see its SAVEPOINT handling) — there
 * is nothing else to keep atomic with it, unlike
 * `HandlePaymentStatusChangedUseCase`, which writes to both `Payment`
 * and, conditionally, `Wallet`.
 *
 * Known trade-off: a genuinely simultaneous replay (two identical
 * requests racing within the same instant) can both pass the
 * idempotency check before either insert lands, meaning both reach the
 * provider. `PaymentRepository::create()` still guarantees only one
 * `Payment` row is ever persisted (the loser re-reads the winner) — but
 * the customer may see two provider-side attempts. A stricter
 * reserve-then-call design would close this window; not built here
 * because a genuine sub-millisecond duplicate request is a client bug,
 * not the normal retry-after-timeout case this idempotency key already
 * protects against.
 */
final readonly class StartPaymentUseCase implements StartPayment
{
    public function __construct(
        private PaymentRepository $payments,
        private PaymentProviderRouter $router,
        private PaymentGatewayRegistry $gateways,
        private Identifier $identifier,
        private PaymentCallbackSecretGenerator $callbackSecrets,
    ) {}

    public function start(StartPaymentCommand $command): PaymentView
    {
        $existing = $this->payments->findByIdempotencyKey($command->projectId, $command->idempotencyKey);
        if ($existing !== null) {
            return $this->toView($existing);
        }

        $providerCode = $this->router->resolveProvider($command->channel, $command->projectId);
        $gateway = $this->gateways->gatewayFor($providerCode);

        $receipt = $gateway->initiate(new InitiatePaymentCommand(
            projectId: $command->projectId,
            amount: $command->amount,
            currency: $command->currency,
            reason: $command->reason,
            appTransactionRef: $command->idempotencyKey,
            customerPhoneNumber: $command->customerPhoneNumber,
            customerName: $command->customerName,
            customerEmail: $command->customerEmail,
            customerLang: $command->customerLang,
            flow: $command->flow,
            channel: $command->channel,
            returnUrl: $command->returnUrl,
        ));

        $payment = new Payment(
            id: $this->identifier->generate(),
            projectId: $command->projectId,
            channel: $command->channel->value,
            providerCode: $providerCode->toString(),
            idempotencyKey: $command->idempotencyKey,
            providerTransactionRef: $receipt->transactionRef,
            amount: $command->amount,
            currency: $command->currency,
            status: $receipt->status,
            requiresAction: $receipt->requiresAction,
            actionHint: $receipt->actionHint,
            paymentUrl: $receipt->paymentUrl,
            errorMessage: $receipt->errorMessage,
            returnUrl: $command->returnUrl,
            walletRecipientExternalRef: $command->walletRecipientExternalRef,
            callbackUrl: $command->callbackUrl,
            callbackSecret: $command->callbackUrl === null ? null : $this->callbackSecrets->generate(),
        );

        try {
            $this->payments->create($payment);
        } catch (PaymentAlreadyExists) {
            // Lost a race: another concurrent call with the same
            // idempotency key created it first (see the class docblock).
            $winner = $this->payments->findByIdempotencyKey($command->projectId, $command->idempotencyKey)
                ?? throw new LogicException('Payment creation conflicted but no payment was found afterwards.');

            return $this->toView($winner);
        }

        // `includeCallbackSecret: true` only here — the one moment this
        // payment is actually created. Every other path through
        // toView() (idempotent replay above, or GetPaymentStatusUseCase/
        // ListPaymentsUseCase entirely) must never re-expose it.
        return $this->toView($payment, includeCallbackSecret: true);
    }

    private function toView(Payment $payment, bool $includeCallbackSecret = false): PaymentView
    {
        return new PaymentView(
            id: $payment->id,
            projectId: $payment->projectId,
            channel: $payment->channel,
            status: $payment->status->value,
            amount: $payment->amount,
            currency: $payment->currency,
            requiresAction: $payment->requiresAction,
            actionHint: $payment->actionHint,
            paymentUrl: $payment->paymentUrl,
            errorMessage: $payment->errorMessage,
            callbackSecret: $includeCallbackSecret ? $payment->callbackSecret : null,
        );
    }
}
