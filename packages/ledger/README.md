# core/ledger

**Bounded context:** `ledger`
**Responsibility:** Immutable double-entry ledger, accounts, materialized balances. The financial core: depends on no other module.
**Delivery lot:** Lot 2 (the core) and Lot 9 (the complete ledger) — see
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

**None.** This module sits at the bottom of the stack and may not depend on any other bounded context (Deptrac enforces this).

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.

## Reading the ledger (ADR-0076 §4)

Two read models, both in `Contracts/`, both scoped by the project:

- `TrialBalance` — the balance of every account of a project, at a point in time.
- `AccountStatement` — the statement of **one** account over a period: the balance it
  held strictly before the period (opening), the movements it carried (period
  `[from, to)`, the end excluded), and the balance it closes on. The balance carried by a
  line is the balance of the **account**, not of the page: a page reads on its own. The
  pagination is a `(created_at, id)` cursor.

Both are read-only. The ledger writes, it never rewrites: a balance that disagrees with
the entries is a defect to fix with a correction, not a number to patch.

## The operating corrections (ADR-0076 §6)

A correction is not a mutation of an existing entry: it is a NEW, balanced ledger
transaction that names the one it counter-passes (`reverses_transaction_id`).

**Two steps, never one.** `LedgerAdjustments::propose()` creates the correction and
moves no money; the money moves in `bookWithoutApproval()` (below the double-validation
threshold) or in `approve()` (a second pair of eyes). The split is what lets the caller
leave its trace of the proposal *before* the money moves — a correction nobody can
prove is not a correction.

**The invariants belong to the state, not to the proposal.** `LedgerAdjustment::assertBookable()`
is called by every path that ends in a ledger entry, and refuses:

- an account of kind `wallet` (the balance of a party is the `wallet` module's),
- the same account on both sides (nothing would move),
- a correction that names no transaction to counter-pass.

The constructor validates nothing on purpose: a row written before a rule existed must
still be *readable* (the console lists the corrections waiting for a decision) — it is
refused when the money is about to move, not when it is loaded. The table carries no
CHECK constraint on the account kinds: the domain is the only line of defence.

**The console reaches the old entries too.** The proposal screen lists the hundred most
recent transactions of the project, and a free field beside it takes the identifier of
any other one — the server checks that it exists, belongs to the project, and is not
itself a correction.
