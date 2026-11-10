# core/billing

**Bounded context:** `billing`
**Responsibility:** Commerce — the customers of a project, the versioned plan
catalogue, the subscription contracts with their periods, and the invoices
that carry the receivables.
**Delivery lot:** Lot 10 — Commerce I (ADR-0070, ADR-0071, ADR-0072) and
Lot 11 — the invoice and its collection (ADR-0073, ADR-0094, ADR-0095) — see
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

## The invoice (ADR-0073, ADR-0094)

An invoice is a **document**: a receivable that carries a number, a customer
frozen on it, and lines that are **immutable once issued** — the database
refuses the change, not a convention.

- it is created **draft** and issued later: the number is attributed at the
  issuance, under the lock of the (project, year) sequence — **no gap**, ever
  (`FACT-2026-000042`);
- the tax is a **rate per line**, rounded half-up on that line, and it is
  **stored** on the line: the tax of an invoice is the sum of its lines'
  taxes, never a rate applied to the subtotal;
- the issuance **books the receivable** in the ledger — Débit
  `customer_receivable:<client>` / Crédit `revenue` — and the cancellation
  **counter-passes** it; a write-off moves it to `bad_debt`;
- the customer frozen on it is **encrypted at rest**: the printed document
  decrypts the name at the moment of printing, and nothing else ever does.

### What this lot does NOT deliver yet (ADR-0094)

Only the **rendering of the receipt** is missing: ADR-0073 §5 wants a
receipt for every collected amount (the amount, the date, the reference of
the transfer or of the offline receipt, the invoice it settles). Nothing is
stored for it — a receipt is a **projection** of the invoice and of its
collections (ADR-0094 §11), and the collection history the console already
shows carries everything it needs.

The PDF is the other thing: no rendering library is in `composer.json`, and
ADR-0094 §14 chooses it in a dedicated ADR. The printable HTML is delivered.

## The collection (ADR-0073 §2, §4; ADR-0094 §§8-13; ADR-0095)

An invoice becomes **paid** when money is collected on it — and the Core
never lets a sum be counted twice.

**Online.** `POST /v1/invoices/{id}/pay` starts a payment on the rail the
caller chose, with the intention `invoice`: the `payment` module books Débit
`clearing:<prestataire>` / Crédit `collections`, and `billing` allocates it
onto the receivable — Débit `collections` / Crédit `customer_receivable:<client>`.
The attempt is a **row**, not a column: a failed attempt, a canceled one and
a second one that succeeds all stay visible.

- the allocation is **conditional** (`WHERE status = 'pending'`): a replayed
  status change moves nothing;
- a settlement whose **amount or currency differs** is **never** imputed
  (ADR-0094 §10): the money goes to `payment_exceptions`;
- a payment that lands on an invoice that is **already settled** is not
  lost: it moves to `payment_exceptions` and opens an **overpayment**.

**Offline.** The console records a **manual receipt** — a transfer, a cheque,
cash — with its method, its reference and its amount. It is booked Débit
`manual_receipts:<méthode>` / Crédit `customer_receivable:<client>`.

- below the project's threshold, the receipt is accounted **at the
  recording**;
- above it, the receipt waits for an explicit **validation**, audited.
  ADR-0095 withdrew the four eyes of ADR-0073 §4: **one** console account is
  enough to validate a receipt, the author included. What remains is the
  deliberate act — recorded, dated and audited.

The public path to `active` is now the collection itself: the payment
declaration (`ConfirmSubscriptionPeriodPayment`) **refuses** a period whose
invoice is not settled (ADR-0094 §8).

### The invoice of a period (ADR-0073 §1, ADR-0094 §6)

A **billed** period carries its invoice, and it is born with it:

- the first period of a contract gets its invoice in the transaction that
  signs the contract;
- the renewal period gets its invoice in the transaction that prepares it
  (the scheduler, `billing:advance-subscriptions`).

The invoice is created **draft** and issued in that very transaction: the
observer sees an **open** invoice, and the number is attributed at the
issuance (ADR-0094 §6). A trial is free and carries none (ADR-0072 §1).

## The supplement (ADR-0117)

A **supplement** is a quantity bought **on top of** the limit of a
metered feature — the pack of fifty extra WhatsApp messages a customer
buys when his plan of a hundred is exhausted.

- it is **not** a subscription and **not** a second counter: the customer
  keeps his plan, his period and his counter; what is bought is an
  allowance, on the very meter of one of his features;
- it carries **its own invoice** — created and issued by the invoice
  module, like every other sum of the module;
- the allowance is granted **when that invoice is paid** — in the very
  transaction that settles it, online or offline. An order that is not
  paid for yet grants nothing;
- an annulled invoice cancels the supplement it carried, and so does an
  invoice written off as a bad debt: the money will never arrive, and the
  meter must not stay locked behind a purchase that grants nothing.

The limit that is spent — and the limit the entitlements announce — is the
**effective** one: the limit of the plan version **plus** the sum of the
supplements **activated in the period in progress** (ADR-0121). A pack
covers the period it was paid for; it does not carry over to the next
one. That sum is computed in one place
only (`EffectiveLimitResolver`), so the number a client is told and the
number the quota is enforced against never drift apart.

## The catalogue of packs (ADR-0119)

A **pack** is an offer the project declares for its customers: the meter
it extends, the quantity it adds, the price of one unit in minor units
(G5) and the label the customer reads. It lives in the project settings
(`billing_settings.supplement_packs`), like the capability → meter
declarations — not in a table of its own, not in a plan.

- the rules are checked **in the code**, never by a database constraint:
  the meter must be one the project counts, the quantity at least one, the
  price strictly positive, the label non-empty, the code unique;
- the packs are carried over untouched when a settings save does not carry
  them, so saving the settings from the console never empties the
  catalogue.

The customer of a portal buys a pack through
`Contracts\PurchaseSubscriptionSupplementPack`: he cites the **code** of
an offer, never a price of his own. The purchase produces the same thing
as the operator's route — a `pending` supplement and its invoice — and
the replay of the same gesture buys only once (the key of the gesture is
durable, unique per project). The operator's route
(`POST /v1/subscriptions/{id}/supplements`) is unchanged: it declares a
quantity and a price, like the lines of a manual invoice.

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
| `POST /v1/subscriptions/{id}/supplements` | `subscriptions:write` + `Idempotency-Key` | Buys units added **on top of** the limit of a metered feature — with its own invoice |
| `GET /v1/subscriptions/{id}/supplements` | `subscriptions:read` | The purchases of the contract, and the ones still waiting for their payment |
| `GET /v1/invoices/{id}` · `GET /v1/invoices?customer_ref=&status=` | `invoices:read` | The document, its lines and what has been paid of it |
| `GET /v1/invoices/{id}/document` | `invoices:read` | The printable invoice, as HTML |
| `POST /v1/invoices` | `invoices:write` + `Idempotency-Key` | Creates a **draft** manual invoice |
| `POST /v1/invoices/{id}/issue` | `invoices:write` + `Idempotency-Key` | Issues it: the number, the freeze, the receivable in the ledger |
| `POST /v1/invoices/{id}/void` | `invoices:write` + `Idempotency-Key` | Cancels it before any payment — the counter-passation |
| `GET /v1/invoices/{id}/payments` | `invoices:read` | The collection attempts of the invoice — every one of them |
| `POST /v1/invoices/{id}/pay` | `invoices:write` + `Idempotency-Key` | Starts the collection: the payment, and the attempt that will carry the money onto the receivable |

The console (`/admin/projects/{projectId}/…`) mirrors them, with the operator's
scope and an audit entry for each act.

### The console

A customer, a plan, a contract and an invoice belong to a **project**: there is
no global list of them. The console is therefore reached from the project's
page — the sidebar's "Facturation" entry sends the operator to the project
list first, and the project page carries the four links (clients, offers,
subscriptions, invoices).

| Console route | Permission | Does |
| ------------- | ---------- | ---- |
| `/admin/projects/{id}/customers` (+ `/create`, `/{ref}/edit`) | `customers:read` · `customers:write` | The customers of the project; the contact is masked in the listing |
| `/admin/projects/{id}/plans` (+ `/create`, `/{code}/versions`) | `plans:read` · `plans:write` | The catalogue: a plan, a draft version, its publication, the archive |
| `/admin/projects/{id}/subscriptions` (+ `/{id}`) | `subscriptions:read` · `subscriptions:write` | The contracts, their periods, and the operator's gestures (pause, resume, cancel, plan change, free period, payment declaration) |
| `/admin/projects/{id}/invoices` (+ `/create`, `/{id}`) | `invoices:read` · `invoices:write` | The invoices, and the operator's gestures (issue, cancel, write-off). No personal value is ever shown in clear |
| `/admin/projects/{id}/receipts` (+ `/invoices/{id}/receipts`) | `invoices:read` · `invoices:write` | The offline collection: the receipts waiting for a validation, the recording of a new one, and its validation |

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
