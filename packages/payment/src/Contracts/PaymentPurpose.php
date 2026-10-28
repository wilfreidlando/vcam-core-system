<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

/**
 * ADR-0076 §3 (amended by ADR-0094 §9) — the INTENTION of a collection.
 *
 * The intention decides the ledger entry written when the payment
 * settles, so it is part of the **published** vocabulary: a caller
 * (`billing`, ADR-0073) that settles an invoice says so, and the
 * money lands on `collections` — an account the collection is later
 * allocated from — instead of `revenue`, where it would be counted a
 * second time.
 *
 * It lives in `Contracts/` (and not in `Domain/`) for the same reason
 * `PaymentStatus` lives outside it: another module must be able to
 * *name* the intention without ever reaching into this module's
 * internals (G3c). The Domain model carries the plain string it was
 * given, exactly like `channel`.
 */
final class PaymentPurpose
{
    /** A plain collection for the project — `D clearing:<p> / C revenue`. */
    public const DIRECT = 'direct';

    /** The caller opted into a wallet credit (ADR-0037). */
    public const WALLET_CREDIT = 'wallet_credit';

    /** The payment settles an invoice — `billing` allocates it (ADR-0073). */
    public const INVOICE = 'invoice';

    /**
     * The complete vocabulary, in a stable order.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::DIRECT, self::WALLET_CREDIT, self::INVOICE];
    }

    /**
     * Whether a purpose belongs to the vocabulary.
     */
    public static function isKnown(string $purpose): bool
    {
        return in_array($purpose, self::all(), true);
    }
}
