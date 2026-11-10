<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Exception;

use RuntimeException;

/**
 * ADR-0117 §2 — a supplement that the Core refuses.
 *
 * The message states the rule that was broken, because the API layer
 * turns it into an RFC 7807 problem detail
 * (`app/Http/ExceptionProblemMapping.php`).
 */
final class InvalidSubscriptionSupplement extends RuntimeException
{
    /**
     * ADR-0117 §2 — the meter the supplement would extend is not one of
     * the metered features of the contract's plan version: there is no
     * limit to add to.
     */
    public static function becauseMeterNotInPlan(string $meter): self
    {
        return new self("The meter \"{$meter}\" is not a metered feature of the plan of this contract.");
    }

    /**
     * ADR-0117 §2 — the meter of the contract has no ceiling: a
     * supplement would add nothing to an unlimited right.
     */
    public static function becauseUnlimitedMeter(string $meter): self
    {
        return new self("The meter \"{$meter}\" has no limit: a supplement would grant nothing.");
    }

    /**
     * ADR-0117 §2 — a supplement adds units: it cannot add zero, and it
     * cannot take any away.
     */
    public static function becauseQuantityNotPositive(): self
    {
        return new self('A supplement carries a quantity of at least one.');
    }

    /**
     * G5 — the money is in minor units, and it is never negative.
     */
    public static function becausePriceNegative(): self
    {
        return new self('A supplement carries a non-negative unit price.');
    }

    /**
     * ADR-0117 §2 — a contract that grants no right cannot be
     * supplemented: there is nothing to add to.
     */
    public static function becauseSubscriptionHasNoAccess(): self
    {
        return new self('This contract grants no right: it cannot carry a supplement.');
    }

    /**
     * ADR-0117 §2 — the plan version the contract was signed on is gone:
     * the Core cannot tell which meter the contract carries, so the
     * purchase is refused rather than guessed.
     */
    public static function becausePlanVersionMissing(): self
    {
        return new self('The plan version of this contract no longer exists: it cannot carry a supplement.');
    }

    /**
     * ADR-0117 §2 — the life of a supplement is a state machine: a
     * settled one is not settled twice, an annulled one does not come
     * back to life.
     */
    public static function becauseNotPending(string $status): self
    {
        return new self("A supplement in state \"{$status}\" cannot move any further.");
    }

    /**
     * ADR-0119 §2 — the pack is not in the catalogue of the project: it
     * was never declared, or it has been withdrawn.
     */
    public static function becausePackNotFound(string $code): self
    {
        return new self("The pack \"{$code}\" is not in the catalogue of this project.");
    }

    /**
     * ADR-0119 §2 — the packs of a project are priced in its currency.
     * A contract in another currency buys no pack: the amount is never
     * converted behind the customer's back.
     */
    public static function becausePackCurrencyMismatch(string $packCurrency, string $contractCurrency): self
    {
        return new self("The packs of this project are priced in {$packCurrency}; this contract is in {$contractCurrency}.");
    }

    /**
     * ADR-0121 — the key of the gesture already carries **another**
     * purchase: the replay would hand back a result that is not the one
     * that was asked for.
     */
    public static function becauseKeyCarriesAnotherPurchase(): self
    {
        return new self('This idempotency key already carries another purchase: it cannot serve a second gesture.');
    }

    /**
     * ADR-0119 §6 — the gesture won the race but its supplement cannot be
     * read back: the Core says so rather than guessing.
     */
    public static function becausePurchaseUnreadable(): self
    {
        return new self('The supplement of this gesture cannot be read back.');
    }

    /**
     * A pack that costs nothing has no invoice to carry it: the ledger
     * refuses a line of zero, and the purchase would end in a technical
     * error instead of a plain refusal.
     */
    public static function becausePackIsFree(string $code): self
    {
        return new self("The pack \"{$code}\" is free: it cannot be bought.");
    }
}
