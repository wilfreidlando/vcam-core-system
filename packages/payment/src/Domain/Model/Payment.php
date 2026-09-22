<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

use Core\Payment\PaymentStatus;
use DateTimeImmutable;
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
        /**
         * The reliable, server-to-server notification target for THIS
         * payment — distinct from `returnUrl` (browser redirect,
         * best-effort UX, may never fire). `null` means no per-payment
         * callback was requested at initiation.
         */
        public ?string $callbackUrl = null,
        /**
         * Signs every delivery to `callbackUrl` (HMAC-SHA256). Generated
         * once at initiation, shown once in the initiation response,
         * never re-exposed after — same one-shot-secret pattern as an
         * API key or a webhook endpoint's secret.
         */
        public ?string $callbackSecret = null,
        /**
         * When this row last changed — persistence metadata, not
         * domain state, so it's nullable and defaults to null for every
         * caller that doesn't need it (a freshly-created Payment that
         * hasn't been persisted yet has no meaningful value here). Only
         * `EloquentPaymentRepository::toDomain()` populates it, from the
         * `updated_at` column, for read paths that need to show how long
         * a payment has sat untouched (the reconciliation preview:
         * review finding, "toutes les infos" — an operator deciding
         * whether to trust a reconciliation run needs to see how stale
         * each candidate actually is, not just that it's stuck).
         */
        public ?DateTimeImmutable $updatedAt = null,
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
     *
     * `$reason` only becomes `errorMessage` for a non-success outcome.
     * Bug found via a real MyCoolPay callback: their `transaction_reason`
     * field is populated on a SUCCESSFUL payment too (it echoes back the
     * transaction's own description, not a failure cause), so passing it
     * straight through unconditionally displayed "Erreur : <the payment's
     * own reason text>" on the admin console's detail page for a payment
     * that had settled with no error at all.
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
            errorMessage: $status === PaymentStatus::Succeeded ? null : $reason,
            returnUrl: $this->returnUrl,
            walletRecipientExternalRef: $this->walletRecipientExternalRef,
            callbackUrl: $this->callbackUrl,
            callbackSecret: $this->callbackSecret,
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
