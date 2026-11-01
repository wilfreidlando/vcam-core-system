<?php

declare(strict_types=1);

namespace Core\Payment\Domain\Model;

/**
 * Every step of a payment's journey that the admin console's "Parcours"
 * shows (ADR-0059), in the order they normally happen.
 */
enum PaymentTimelineEntryType: string
{
    /** The integrator's API call, as received (body redacted). */
    case ApiRequestReceived = 'api_request_received';

    /** One HTTP exchange with the provider (request + response, redacted). */
    case ProviderExchange = 'provider_exchange';

    /** The payment was created from the provider's answer. */
    case Initiated = 'initiated';

    /** Same Idempotency-Key replayed: the stored payment was returned, the provider not called. */
    case IdempotentReplay = 'idempotent_replay';

    /** What the Core answered the integrator. */
    case ApiResponseSent = 'api_response_sent';

    /** An authenticated provider callback (webhook) arrived. */
    case ProviderCallbackReceived = 'provider_callback_received';

    /** A provider callback for this payment failed authentication (IP / signature). */
    case ProviderCallbackRejected = 'provider_callback_rejected';

    /** A reconciliation asked the provider for the current status. */
    case ReconciliationChecked = 'reconciliation_checked';

    case StatusChanged = 'status_changed';

    /** A status change arrived after the payment was already final — ignored. */
    case StatusChangeIgnored = 'status_change_ignored';

    case WalletCredited = 'wallet_credited';

    /** ADR-0076 §3 — settlement booked in the ledger without a wallet (direct collection). */
    case SettlementRecorded = 'settlement_recorded';

    /** ADR-0077 — confirmed amount or currency differs: booked to `payment_exceptions`, not allocated. */
    case SettlementException = 'settlement_exception';

    /** `payment.status_changed` written to the outbox (event id → webhook deliveries). */
    case EventPublished = 'event_published';

    /** One attempt to POST the payment's own `callback_url`. */
    case CallbackAttempt = 'callback_attempt';
}
