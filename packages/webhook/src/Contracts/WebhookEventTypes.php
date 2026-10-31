<?php

declare(strict_types=1);

namespace Core\Webhook\Contracts;

/**
 * The closed set of event types the Core actually emits through the outbox
 * — the single list used to validate subscriptions and to document them
 * (ADR-0063: the API used to accept `notification.status_changed`, never
 * emitted, and to refuse `notification.delivered`/`notification.read`,
 * which are). `webhook.test` is not subscribable: it is only sent on
 * request, by `POST /v1/webhook-endpoints/{id}/test`.
 */
final class WebhookEventTypes
{
    public const ALL = [
        'payment.status_changed',
        'notification.delivered',
        'notification.read',
        'notification.failed',
        'wallet.credited',
        'wallet.debited',
        // ADR-0084 §3 — the commerce of a project: what a SaaS needs to
        // open, restrict or close access to its tenants. Each payload
        // carries the `version` of the resource (out-of-order delivery).
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
        'customer.suspended',
        'customer.reactivated',
        // ADR-0107 §3 — the Core emitted these three without ever listing
        // them, so a project could only receive them through the `*`
        // wildcard. `customer.created` is the signal that a tenant now
        // exists: it is what a client application waits for.
        'customer.created',
        'customer.updated',
        'customer.erased',
        // ADR-0107 §3 — the same gap on the invoice side: the three events
        // the billing module emits and the catalogue did not name.
        'invoice.created',
        'invoice.receipt_recorded',
        'invoice.uncollectible',
        // ADR-0107 §3 — the catalogue of plans, emitted by `billing` and
        // unnamed until now.
        'plan.archived',
        'plan.version_published',
        // ADR-0074 §2 — emitted in the transaction that crosses the mark.
        'usage.threshold_reached',
        'usage.limit_reached',
    ];
}
