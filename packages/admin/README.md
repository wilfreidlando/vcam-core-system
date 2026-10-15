# core/admin

**Bounded context:** `admin`
**Responsibility:** Core Admin: operator console for projects, credentials, transactions, wallets, fee rules, providers, webhooks, audit and reconciliation.
**Delivery lot:** Lot 6, two slices delivered — see
[ADR-0044](../../docs/adr/0044-conception-lot-6-core-admin-operateurs-rbac-audit-dlq-reconciliation.md),
[ADR-0045](../../docs/adr/0045-lot-6-listing-generique-admin.md),
[ADR-0046](../../docs/adr/0046-lot-6-revue-de-code-correctifs.md)
and [the delivery plan](../../docs/specs/plan-delivery-mvp.md)

## What's delivered so far

Eight capabilities, all under `/v1/admin/*`, gated by `operator.auth` (the
platform operator plane — `Core\Identity\OperatorRole` — lives in `identity`,
not here):

| Endpoint | Permission | Composes |
| -------- | ---------- | -------- |
| `GET /v1/admin/audit-log` | `audit:read` | `Core\Audit\Contracts\AuditQuery` |
| `GET /v1/admin/webhook-deliveries/dead` | `webhooks:read` | `Core\Webhook\Contracts\ListDeadWebhookDeliveries` |
| `POST /v1/admin/webhook-deliveries/{id}/replay` | `webhooks:replay` | `Core\Webhook\Contracts\{ReplayWebhookDelivery,QueueWebhookDelivery}` |
| `POST /v1/admin/payment-reconciliation-runs` | `payments:reconcile:write` | `Core\Payment\Contracts\ReconcileStuckPayments` |
| `GET /v1/admin/projects` | `projects:read` | `Core\Identity\Contracts\ListProjects` |
| `GET /v1/admin/projects/{id}/payments` | `payments:read` | `Core\Payment\Contracts\ListPayments` |
| `GET /v1/admin/projects/{id}/wallets` | `wallets:read` | `Core\Wallet\Contracts\ListWallets` |
| `GET /v1/admin/projects/{id}/notifications` | `notifications:read` | `Core\Notification\Contracts\ListNotifications` |

Plus `admin:reconcile-stuck-payments`, scheduled every minute (ADR-0067)
(`bootstrap/app.php`) as the primary reconciliation trigger.

The two mutating endpoints (webhook replay, reconciliation trigger) record
an `AuditLogger` entry (`webhook_delivery.replayed` / `payment_reconciliation.triggered`,
actor `operator:<id>`) since ADR-0046 — before that, admin actions left no
audit trail of their own.

This module has no `Domain/`/`Application/` of its own yet — every
capability above is a controller composing another module's `Contracts/`
port directly (ADR-0027: "Admin compose, il n'interroge pas directement").
Not yet built: fee-rule administration (the `fees` module itself is
paused), billing administration, operational monitoring.

## Internal structure

This module is layered (hexagonal); the dependency direction is inward only:

```
Presentation ─┐
Infrastructure ┼─► Application ─► Domain
              ─┘
```

| Directory         | Contains                                                                 |
| ----------------- | ------------------------------------------------------------------------ |
| `src/Domain`      | Pure PHP: entities, value objects, domain events, exceptions, **ports**   |
| `src/Application` | Use cases and their DTOs; transactional orchestration                     |
| `src/Infrastructure` | Port implementations: Eloquent persistence, HTTP/provider adapters    |
| `src/Presentation`| HTTP controllers, requests, resources, middleware, console commands       |
| `src/Contracts`   | **Published API** — the only part other modules may depend on             |

Both directions are enforced by Deptrac (`deptrac.yaml`): `Domain` may not reference
Laravel, Eloquent or `Infrastructure`, and no other module may reference anything of
this one outside `Contracts/`.

## Module dependencies

Allowed (via their `Contracts/` only):

- `core/audit`
- `core/billing`
- `core/events`
- `core/fees`
- `core/identity`
- `core/ledger`
- `core/notification`
- `core/parties`
- `core/payment`
- `core/wallet`
- `core/webhook`

Any other cross-module dependency fails CI.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.
