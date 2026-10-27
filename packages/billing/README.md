# core/billing

**Bounded context:** `billing`
**Responsibility:** Commerce — the customers of a project, the versioned plan
catalogue, and the subscription contracts with their periods.
**Delivery lot:** Lot 10 — Commerce I (ADR-0070, ADR-0071, ADR-0072; ADR-0092
for what this lot may do before the invoice exists) — see
[the delivery plan](../../docs/specs/plan-delivery-mvp.md)

## Internal structure

This module is layered (hexagonal); the dependency direction is inward only:

```
Presentation ─┐
Infrastructure ┼─► Application ─► Domain
              ─┘
```

| Directory         | Contains                                                                 |
| ----------------- | ------------------------------------------------------------------------ |
| `src/Domain`      | Pure PHP: entities, value objects, exceptions, **ports**                   |
| `src/Application` | Use cases and their DTOs; transactional orchestration                     |
| `src/Infrastructure` | Port implementations: Eloquent persistence, read models                 |
| `src/Presentation`| HTTP controllers, console commands                                         |
| `src/Contracts`   | **Published API** — the only part other modules may depend on             |

Both directions are enforced by Deptrac (`deptrac.yaml`): `Domain` may not reference
Laravel, Eloquent or `Infrastructure`, and no other module may reference anything of
this one outside `Contracts/`.

## Module dependencies

Allowed (via their `Contracts/` only):

- `core/events` — the transactional outbox
- `core/identity` — the project context
- `core/ledger` — the supported currencies
- `core/support` — the shared kernel

Any other cross-module dependency fails CI. In particular the module does **not**
depend on `core/audit`: the operator's acts are audited by the console controller
(ADR-0092 §3), and the public trace of every act is an outbox event.

## What this lot delivers

- **Customers** (ADR-0070) — a minimal identity per project, declared by the
  project through `external_ref`. The email and the phone are **encrypted at
  rest** (ciphertext + blind index), never stored in clear. The column list is
  a white-list: a new column needs an ADR (guardrail **G4b**).
- **The catalogue** (ADR-0071) — a plan is stable, its **versions** carry the
  terms (price, currency, interval, trial, grace, features). A published
  version is immutable — in the database, by a trigger, not only in the code.
  The features are **named by the project** and validated by format, never
  against a list: the Core knows no feature name.
- **The subscriptions** (ADR-0072) — one contract per customer and plan, with
  its **periods**. Two periods of the same contract can never overlap (a
  PostgreSQL exclusion constraint), so a payment made in advance settles the
  upcoming period instead of creating a second one. The state machine is one
  table, and every transition is an optimistic-locked `UPDATE`.

## What this lot does NOT deliver (ADR-0092)

**The invoice belongs to Lot 11** (ADR-0073). A paid period exists before its
invoice does: `subscription_periods.invoice_id` stays null. Two consequences:

- a paid subscription cannot become `active` by a payment in this lot — the
  public path to `active` is a **complimentary** period (an operator's act,
  free, with a mandatory reason, no invoice);
- the payment declaration (`ConfirmSubscriptionPeriodPayment`) writes
  `paid_at` and **moves no money**. It is the seam Lot 11 replaces with the
  real collection, which posts to the ledger and asks for a second pair of
  eyes.

## The period arithmetic

The periods are computed in the **project's timezone** (`billing_settings`,
default `Africa/Douala`) and stored in UTC. A month keeps the day of the
**anchor**: an anchor on the 31st gives the 28th (or 29th) of February, then
the 31st of March. PHP's own `DateInterval` overflows instead of clamping
(the 31st of January plus one month gives the 3rd of March), so the month
arithmetic is explicit in `Domain/Model/BillingInterval.php`.

## Public surface

| Route | Scope | Does |
| ----- | ----- | ---- |
| `PUT /v1/customers/{externalRef}` | `customers:write` | Creates (201) or updates (200) — idempotent by reference; `If-Match` carries the version |
| `GET /v1/customers/{externalRef}` | `customers:read` | One customer, with an `ETag` |
| `GET /v1/customers?cursor=&limit=` | `customers:read` | Cursor listing (ADR-0088) |
| `POST /v1/customers/{externalRef}/suspend` · `/reactivate` · `/erase` | `customers:write` | The customer's status |
| `GET /v1/plans` | `plans:read` | The catalogue |
| `POST /v1/plans` | `plans:write` | Creates a draft plan |
| `POST /v1/plans/{code}/versions` | `plans:write` | Adds a draft version |
| `POST /v1/plans/{code}/versions/{n}/publish` | `plans:write` | Publishes it — from then on it is frozen |
| `POST /v1/plans/{code}/archive` | `plans:write` | No new subscription on it |
| `POST /v1/subscriptions` | `subscriptions:write` + `Idempotency-Key` | Creates the contract, its first period |
| `GET /v1/subscriptions/{id}` · `GET /v1/subscriptions?customer_ref=&status=` | `subscriptions:read` | The contract and its current period |
| `POST /v1/subscriptions/{id}/cancel` · `/change-plan` | `subscriptions:write` | The operator's gestures |

The console (`/admin/projects/{projectId}/…`) mirrors them, with the operator's
scope and an audit entry for each act.

### The console

A customer, a plan and a contract belong to a **project**: there is no global
list of them. The console is therefore reached from the project's page — the
sidebar's "Facturation" entry sends the operator to the project list first,
and the project page carries the three links (clients, offers, subscriptions).

| Console route | Permission | Does |
| ------------- | ---------- | ---- |
| `/admin/projects/{id}/customers` (+ `/create`, `/{ref}/edit`) | `customers:read` · `customers:write` | The customers of the project; the contact is masked in the listing |
| `/admin/projects/{id}/plans` (+ `/create`, `/{code}/versions`) | `plans:read` · `plans:write` | The catalogue: a plan, a draft version, its publication, the archive |
| `/admin/projects/{id}/subscriptions` (+ `/{id}`) | `subscriptions:read` · `subscriptions:write` | The contracts, their periods, and the operator's gestures (pause, resume, cancel, plan change, free period, payment declaration) |

A resource that does not exist — or that belongs to another organization — is
answered like a missing one: a **read** answers 404, a **gesture** answers
the console's "introuvable" redirect, and **never** a 403, which would
confirm that the resource exists somewhere (ADR-0081 S-04).

### The scheduler

`billing:advance-subscriptions` moves the contracts whose period is due: it
renews them, suspends the ones that are past due, and expires the ones that
were never completed. It runs on the Core's `Clock`, so a test drives it
without waiting.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.
