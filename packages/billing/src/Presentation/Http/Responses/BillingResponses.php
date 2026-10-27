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
}
