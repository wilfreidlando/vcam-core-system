<?php

declare(strict_types=1);

namespace Core\Billing\Presentation\Http\Responses;

/**
 * The **documented** shape of every billing response — the public
 * contract of the module, written once.
 *
 * The serializers of `Concerns/SerializesBillingViews` build the JSON
 * with its `snake_case` keys; a DTO whose properties are `camelCase`
 * (the house style of `Contracts/`) would document the wrong contract,
 * so the shape is declared here as a type and referenced by the
 * controllers through Scramble's `#[Response]` attribute.
 *
 * The shapes follow the serializers key for key: the OpenAPI document
 * describes what the API really returns, not what it should return.
 */
final class BillingResponses
{
    /**
     * A customer — ADR-0070 §2. The `email` and the `phone` leave the
     * Core only to a caller holding `customers:read` (ADR-0070
     * §Sécurité): they are optional in the schema for that reason.
     */
    public const CUSTOMER = 'array{'
        .'id: string, '
        .'project_id: string, '
        .'external_ref: string, '
        .'display_name: string, '
        .'email?: string|null, '
        .'phone?: string|null, '
        .'locale: string, '
        .'metadata: array<string, mixed>, '
        .'status: string, '
        .'version: int, '
        .'created_at: string, '
        .'updated_at: string, '
        .'erased_at: string|null'
        .'}';

    /**
     * The terms of a plan version — the price is in **minor units**
     * (G5), never a float.
     */
    public const PLAN_VERSION = 'array{'
        .'id: string, '
        .'plan_id: string, '
        .'project_id: string, '
        .'version: int, '
        .'price_minor: int, '
        .'currency: string, '
        .'interval_unit: string, '
        .'interval_count: int, '
        .'trial_days: int, '
        .'grace_days: int, '
        .'grace_access: bool, '
        .'features: array<string, mixed>, '
        .'status: string, '
        .'published_at: string|null, '
        .'created_at: string'
        .'}';

    /**
     * A plan and its versions — ADR-0071 §1.
     */
    public const PLAN = 'array{'
        .'id: string, '
        .'project_id: string, '
        .'code: string, '
        .'name: string, '
        .'description: string|null, '
        .'status: string, '
        .'visibility: string, '
        .'sort_order: int, '
        .'versions: list<'.self::PLAN_VERSION.'>, '
        .'created_at: string, '
        .'updated_at: string'
        .'}';

    /**
     * A contract, its current period and its next due date —
     * ADR-0072 §7.
     */
    public const SUBSCRIPTION = 'array{'
        .'id: string, '
        .'project_id: string, '
        .'customer_id: string, '
        .'customer_ref: string, '
        .'plan_id: string, '
        .'plan_code: string, '
        .'plan_version_id: string, '
        .'plan_version: '.self::PLAN_VERSION.', '
        .'status: string, '
        .'price_minor: int, '
        .'currency: string, '
        .'interval_unit: string, '
        .'interval_count: int, '
        .'anchor_at: string, '
        .'current_period_id: string|null, '
        .'current_period_start: string|null, '
        .'current_period_end: string|null, '
        .'trial_ends_at: string|null, '
        .'cancel_at_period_end: bool, '
        .'pending_plan_version_id: string|null, '
        .'paused_at: string|null, '
        .'canceled_at: string|null, '
        .'ended_at: string|null, '
        .'has_access: bool, '
        .'version: int, '
        .'created_at: string, '
        .'updated_at: string'
        .'}';

    /**
     * A line of an invoice — the tax is **stored** on the line, rounded
     * half-up on that line (ADR-0094 §1), and the amounts are in minor
     * units (G5).
     */
    public const INVOICE_LINE = 'array{'
        .'id: string, '
        .'invoice_id: string, '
        .'kind: string, '
        .'description: string, '
        .'quantity: int, '
        .'unit_amount_minor: int, '
        .'amount_minor: int, '
        .'tax_rate_bps: int, '
        .'tax_amount_minor: int, '
        .'period_start: string|null, '
        .'period_end: string|null'
        .'}';

    /**
     * An invoice — ADR-0073 §1. The frozen contact of the customer is
     * **not** in the wire: it is the internal material of the printed
     * document, and the API hands out the project's own reference.
     */
    public const INVOICE = 'array{'
        .'id: string, '
        .'project_id: string, '
        .'customer_id: string, '
        .'customer_ref: string, '
        .'subscription_id: string|null, '
        .'period_id: string|null, '
        .'number: string|null, '
        .'status: string, '
        .'currency: string, '
        .'subtotal_minor: int, '
        .'tax_minor: int, '
        .'total_minor: int, '
        .'amount_paid_minor: int, '
        .'lines: list<'.self::INVOICE_LINE.'>, '
        .'due_at: string|null, '
        .'issued_at: string|null, '
        .'paid_at: string|null, '
        .'voided_at: string|null, '
        .'void_reason: string|null, '
        .'version: int, '
        .'created_at: string, '
        .'updated_at: string'
        .'}';

    /**
     * A collection attempt — ADR-0073 §2. The amount is in **minor
     * units** (G5), never a float.
     */
    public const INVOICE_PAYMENT = 'array{'
        .'id: string, '
        .'invoice_id: string, '
        .'project_id: string, '
        .'payment_id: string, '
        .'status: string, '
        .'amount_minor: int, '
        .'currency: string, '
        .'confirmed_amount_minor: int|null, '
        .'failure_reason: string|null, '
        .'settled_at: string|null, '
        .'created_at: string'
        .'}';

    /**
     * What a started collection answers — ADR-0073 §2: the attempt, and
     * what the payer must do next.
     */
    public const PAY_INVOICE = 'array{'
        .'payment: '.self::INVOICE_PAYMENT.', '
        .'requires_action: bool, '
        .'payment_url: string|null, '
        .'action_hint: string|null'
        .'}';

    /**
     * A page of collection attempts.
     */
    public const INVOICE_PAYMENT_PAGE = 'array{'
        .'data: list<'.self::INVOICE_PAYMENT.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * A page of customers — the cursor is passed back to read the next
     * page (ADR-0088).
     */
    public const CUSTOMER_PAGE = 'array{'
        .'data: list<'.self::CUSTOMER.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * A page of plans.
     */
    public const PLAN_PAGE = 'array{'
        .'data: list<'.self::PLAN.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * A page of contracts.
     */
    public const SUBSCRIPTION_PAGE = 'array{'
        .'data: list<'.self::SUBSCRIPTION.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * A page of invoices.
     */
    public const INVOICE_PAGE = 'array{'
        .'data: list<'.self::INVOICE.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * ADR-0074 §4 — the decision of a metered call.
     */
    public const USAGE_DECISION = 'array{'
        .'status: string, '
        .'meter: string, '
        .'used: int, '
        .'limit: int|null, '
        .'remaining: int|null, '
        .'window_start: string|null, '
        .'window_end: string|null'
        .'}';

    /**
     * ADR-0088 §2 — one line of the usage journal.
     */
    public const USAGE_RECORD = 'array{'
        .'id: string, '
        .'customer_ref: string|null, '
        .'meter: string, '
        .'quantity: int, '
        .'source: string, '
        .'idempotency_key: string, '
        .'occurred_at: string, '
        .'recorded_at: string'
        .'}';

    /**
     * A page of the usage journal.
     */
    public const USAGE_PAGE = 'array{'
        .'data: list<'.self::USAGE_RECORD.'>, '
        .'next_cursor: string|null'
        .'}';

    /**
     * ADR-0117 §2 — a supplement: the units bought on top of the limit
     * of a metered feature, and the invoice that carries them.
     */
    public const SUPPLEMENT = 'array{'
        .'id: string, '
        .'project_id: string, '
        .'subscription_id: string, '
        .'customer_ref: string, '
        .'meter: string, '
        .'feature_key: string, '
        .'quantity: int, '
        .'unit_price_minor: int, '
        .'total_minor: int, '
        .'currency: string, '
        .'status: string, '
        .'invoice_id: string|null, '
        .'invoice_number: string|null, '
        .'period_id: string|null, '
        .'activated_at: string|null, '
        .'canceled_at: string|null, '
        .'created_at: string, '
        .'updated_at: string'
        .'}';

    /**
     * ADR-0117 §4 — what a purchase answers: the supplement, and the
     * invoice that carries it.
     */
    public const SUPPLEMENT_PURCHASE = 'array{'
        .'supplement: '.self::SUPPLEMENT.', '
        .'invoice: '.self::INVOICE
        .'}';

    /**
     * The supplements of one contract.
     */
    public const SUPPLEMENT_PAGE = 'array{'
        .'data: list<'.self::SUPPLEMENT.'>'
        .'}';

    /**
     * ADR-0074 §4 — what a customer may do, and how much is left.
     */
    public const ENTITLEMENTS = 'array{'
        .'customer_ref: string, '
        .'active: bool, '
        .'plan_code: string|null, '
        .'subscription_id: string|null, '
        .'entitlements: list<array{key: string, type: string, enabled: bool|null, meter: string|null, limit: int|null, used: int|null, remaining: int|null, overage: string|null, threshold_bps: int|null, window_start: string|null, window_end: string|null}>'
        .'}';
}
