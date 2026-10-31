<?php

declare(strict_types=1);

namespace Tests\Contract;

/**
 * ADR-0109 §3 — the contract between the Core and the client package
 * (`wilfreidlando/saas-commerce-dep`).
 *
 * The package is a separate repository: it proves itself against a **deployed**
 * Core, in its own CI. This fixture is the Core's half of the same
 * declaration — it says what the package needs, and the Core's CI checks it on
 * **every commit**, against the routing table, the event catalogue, the
 * published document and the scope templates.
 *
 * If the Core breaks one of these, the package's CI says so within the hour
 * (its next run against staging); this fixture says so **immediately**, the day
 * the change is made. Two guards, one declaration.
 *
 * The surface is deliberately **read-only apart from two counters**: the package
 * does no commerce (ADR-0107 §1). That is the point of the fixture — it pins
 * the *limit* of what a client application may do.
 */
final class ClientApplicationContractFixture
{
    /**
     * The endpoints the package calls, always — the minimum of a working
     * client application. As the document publishes them: the `/v1` prefix is
     * carried by the document's server.
     *
     * @var list<array{method: string, path: string, scope: string|null}>
     */
    public const REQUIRED = [
        ['method' => 'get', 'path' => '/whoami', 'scope' => null],
        // ADR-0110 §4 — the identity of the tenant, read (never written).
        ['method' => 'get', 'path' => '/customers/{externalRef}', 'scope' => 'customers:read'],
        // ADR-0107 §5 — the rights of the tenant.
        ['method' => 'get', 'path' => '/customers/{externalRef}/entitlements', 'scope' => 'usage:read'],
        // §5 — the usage counter. The idempotency key travels in the BODY.
        ['method' => 'post', 'path' => '/usage', 'scope' => 'usage:write'],
        // §5 — the hosted space of the owner.
        ['method' => 'post', 'path' => '/portal/sessions', 'scope' => 'portal:write'],
        // §5 — the commercial state, read only.
        ['method' => 'get', 'path' => '/subscriptions', 'scope' => 'subscriptions:read'],
        ['method' => 'get', 'path' => '/subscriptions/{subscriptionId}', 'scope' => 'subscriptions:read'],
        ['method' => 'get', 'path' => '/invoices', 'scope' => 'invoices:read'],
        ['method' => 'get', 'path' => '/invoices/{invoiceId}', 'scope' => 'invoices:read'],
    ];

    /**
     * The endpoints the package calls only when the application uses the
     * matching service. Each group is one optional service of the package.
     *
     * @var array<string, list<array{method: string, path: string, scope: string|null}>>
     */
    public const OPTIONAL = [
        'notifications' => [
            ['method' => 'post', 'path' => '/notifications/text', 'scope' => 'notifications:write'],
            ['method' => 'post', 'path' => '/notifications/image', 'scope' => 'notifications:write'],
            ['method' => 'post', 'path' => '/notifications/file', 'scope' => 'notifications:write'],
            ['method' => 'get', 'path' => '/notifications/{notificationId}', 'scope' => 'notifications:read'],
        ],
        'payments' => [
            ['method' => 'post', 'path' => '/payments/paylink', 'scope' => 'payments:write'],
            ['method' => 'post', 'path' => '/payments/payin', 'scope' => 'payments:write'],
            ['method' => 'get', 'path' => '/payments/{paymentId}', 'scope' => 'payments:read'],
        ],
        'wallets' => [
            ['method' => 'get', 'path' => '/parties/{externalRef}/wallets', 'scope' => 'wallets:read'],
            ['method' => 'get', 'path' => '/wallets/{walletId}/balance', 'scope' => 'wallets:read'],
            ['method' => 'get', 'path' => '/wallets/{walletId}/transactions', 'scope' => 'wallets:read'],
            ['method' => 'post', 'path' => '/wallets/transfers', 'scope' => 'wallets:write'],
            ['method' => 'post', 'path' => '/wallets/withdrawals', 'scope' => 'wallets:write'],
        ],
    ];

    /**
     * The events the client application subscribes to: the commerce of its
     * tenant, plus the events of the optional services.
     *
     * @var list<string>
     */
    public const EVENTS = [
        // ADR-0110 — the tenant itself: created, changed, suspended, erased.
        'customer.created',
        'customer.updated',
        'customer.suspended',
        'customer.reactivated',
        'customer.erased',
        // ADR-0084 §3 — the subscription of the tenant.
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
        // ADR-0084 §3 — the invoices of the tenant.
        'invoice.issued',
        'invoice.paid',
        'invoice.payment_failed',
        'invoice.voided',
        // ADR-0074 §2 — the quotas.
        'usage.threshold_reached',
        'usage.limit_reached',
        // The optional services.
        'payment.status_changed',
        'notification.delivered',
        'notification.read',
        'notification.failed',
        'wallet.credited',
        'wallet.debited',
    ];

    /**
     * The scopes a client application must hold — exactly what the
     * « Application cliente » template gives it (ADR-0107 §5).
     *
     * @var list<string>
     */
    public const SCOPES = [
        'customers:read',
        'usage:read',
        'usage:write',
        'portal:write',
        'subscriptions:read',
        'invoices:read',
    ];
}
