# core/payment

**Bounded context:** `payment`
**Responsibility:** Payment intents, orchestration state machine, provider adapters (MTN MoMo, Orange Money), refunds.
**Delivery lot:** Lot 3 (orchestration) and Lot 9 (settlements, money exceptions, invariants) — see
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

- `core/events`
- `core/fees`
- `core/identity`
- `core/ledger`
- `core/parties`
- `core/wallet`

Any other cross-module dependency fails CI.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.

## Settlements and the money pool (ADR-0076 §2, ADR-0077)

A settlement is what the provider has paid us. It is written by
`LedgerSettlementLedger`, in the `payment` module because **only this module knows what
a provider is**: the account it debits is `clearing:<prestataire>` — one per provider, per
project.

- **A settlement is recognized by its idempotency key**, which is the payment's
  identifier — not by its transaction type. A settlement of type `wallet_credit` is
  therefore seen by the reconciliation, where a filter on the type would have let it pass.
- **The money pool** (`payment_exceptions`) holds what arrived but cannot be attributed:
  one pool per project and per currency. Opening an exception writes nothing to the
  ledger — it is the settlement that deposits the sum (or nothing, if the currency is
  not supported).
- **A resolution can only move what the pool holds.** The balance is read under the same
  transaction as the movement (advisory lock on the project), and resolving an exception
  whose pool is empty is refused (`409`). Without that check, allocating an exception to a
  wallet would credit a sum never collected.
- **The intention decides the account.** A payment carries a `purpose`
  (ADR-0076 §3, amended by ADR-0094 §9): `direct` (the default — the project
  is paid, the money lands on `revenue`), `wallet_credit` (the caller opted
  into a wallet credit) or `invoice` (the money lands on `collections`, where
  `billing` allocates it onto a receivable). The parameter is **optional** and
  its default is the historical behaviour: no published contract is broken
  (ADR-0086). An intention nobody honours is **refused**, never ignored — a
  payment of an invoice counted as plain revenue would be counted twice.
- **The reclassification is a decision, not a guess.** Moving a legacy balance to its
  provider's account requires an explicit `--reason`, refuses to attribute a project that
  has several providers, and refuses a residue the settlements do not explain.

## The invariants (ADR-0078)

`php artisan invariants:check` evaluates the invariants of the module and opens a
`money_exception` for each violation it finds. Every run leaves a trace in
`reconciliation_runs` — `completed` or `failed`, the number of invariants **actually**
evaluated (not a theoretical count), and the identifiers of the exceptions opened. The
trace holds counters and identifiers, never personal data.

An exception can be re-opened: the uniqueness constraint covers the **open** exceptions
only, so a violation closed without being corrected comes back in the queue at the next
run.
