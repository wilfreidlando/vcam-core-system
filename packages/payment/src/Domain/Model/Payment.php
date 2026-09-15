<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use Core\Payment\PaymentStatus;
use InvalidArgumentException;

/**
 * One payment this Core started — the anchor ADR-0038 §3/§4 needs: it
 * pins, at creation, which provider actually handled the call (so a
 * later status check or inbound webhook resolves the right adapter
 * directly, never by guessing across every registered provider).
 *
 * `channel` is stored as a plain, pre-validated string rather than a
 * Domain-level value object — unlike `Core\Ledger\Domain\Model\AccountKind`,
 * its format check is purely syntactic (delegated once, upstream, to
 * `Core\Payment\Contracts\PaymentChannel`), not a domain rule this layer
 * needs to re-enforce.
 *
 * Immutable, like `Core\Wallet\Domain\Model\WalletBalance`: a status
 * transition produces a new instance via `withStatusChange()` rather
 * than mutating in place.
 */
final readonly class Payment
{
    public function __construct(
        public string $id,
        public string $projectId,
        public string $channel,
        public string $providerCode,
        public string $idempotencyKey,
        public string $providerTransactionRef,
        /** Minor units (e.g. cents) — never a float, per G5. */
        public int $amount,
        public string $currency,
        public PaymentStatus $status,
        public bool $requiresAction,
        public ?string $actionHint,
        public ?string $paymentUrl,
        public ?string $errorMessage,
        public ?string $returnUrl,
        public ?string $walletRecipientExternalRef,
    ) {
        if (trim($providerTransactionRef) === '') {
            throw new InvalidArgumentException('A Payment must carry a non-blank provider transaction reference.');
        }
    }

    /**
     * A new instance reflecting a provider-reported status change. The
     * caller (`HandlePaymentStatusChangedUseCase`) is responsible for
     * checking `status->isTerminal()` first — replaying a change onto an
     * already-terminal payment is a no-op it should short-circuit, not
     * something this method needs to guard against itself.
     */
    public function withStatusChange(PaymentStatus $status, ?string $reason): self
    {
        return new self(
            id: $this->id,
            projectId: $this->projectId,
            channel: $this->channel,
            providerCode: $this->providerCode,
            idempotencyKey: $this->idempotencyKey,
            providerTransactionRef: $this->providerTransactionRef,
            amount: $this->amount,
            currency: $this->currency,
            status: $status,
            requiresAction: false,
            actionHint: null,
            paymentUrl: $this->paymentUrl,
            errorMessage: $reason,
            returnUrl: $this->returnUrl,
            walletRecipientExternalRef: $this->walletRecipientExternalRef,
        );
    }

    /**
     * ADR-0037: crediting a wallet is never automatic — only when the
     * caller asked for it at initiation (`walletRecipientExternalRef`
     * set) AND the payment has just reached the one terminal state that
     * means money genuinely settled.
     */
    public function shouldCreditWallet(): bool
    {
        return $this->status === PaymentStatus::Succeeded && $this->walletRecipientExternalRef !== null;
    }
}
