<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * G10 — the contract of the **first consumer** of the Core.
 *
 * The transitional platform (`v_cam-services-center`) is today the only
 * consumer of the Core that is not a product built on it — and it is the
 * one the cutover (lot 17) retires. A regression in what it consumes
 * would break it *before* the cutover, and nobody would notice: G9 only
 * diffs the OpenAPI document against itself.
 *
 * This fixture is that contract, pinned. It is deliberately small: it
 * holds what the consumer actually uses, not the whole surface — a
 * fixture that pinned everything would break on every addition, and a
 * guardrail that breaks on additions stops being read.
 *
 * @see docs/specs/inventaire-verifie-fusion.md §3 — the reference application
 *      is already connected to the Core for WhatsApp.
 */
final class ConsumerContractFixture
{
    /**
     * The endpoints the consumer calls, as the document publishes them
     * (the `/v1` prefix is carried by the document's server).
     *
     * @var list<array{method: string, path: string}>
     */
    public const ENDPOINTS = [
        // The WhatsApp branch of the reference application: it already
        // sends notifications through the Core, with the tenant code as
        // the channel session.
        ['method' => 'post', 'path' => '/notifications/text'],
        // The customer portal: a SaaS opens the hosted space of one of
        // its customers from its own backend.
        ['method' => 'post', 'path' => '/portal/sessions'],
    ];

    /**
     * The events the consumer subscribes to. They are the ones a SaaS
     * needs to keep its own state in step with the commerce of the Core
     * (ADR-0084 §3), plus the payment status that moves the money.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'payment.status_changed',
        'subscription.created',
        'subscription.activated',
        'subscription.renewed',
        'subscription.trial_will_end',
        'subscription.renewal_due',
        'subscription.plan_changed',
        'subscription.past_due',
        'subscription.paused',
        'subscription.resumed',
        'subscription.canceled',
        'subscription.expired',
        'invoice.issued',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.voided',
        // ADR-0107 §3 — the lifecycle of the tenant itself. `customer.created`
        // is the signal that a tenant now exists: it is what a client
        // application waits for before it opens a portal for it.
        'customer.created',
        'customer.updated',
        'customer.suspended',
        'customer.reactivated',
        'customer.erased',
        'usage.threshold_reached',
        'usage.limit_reached',
    ];
}
