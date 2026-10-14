# core/wallet

**Bounded context:** `wallet`
**Responsibility:** Wallets: deposits, withdrawals, transfers, holds — backed by the Ledger.
**Delivery lot:** Lot 2 — see [the delivery plan](../../docs/specs/plan-delivery-mvp.md)

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
- `core/identity`
- `core/ledger`
- `core/parties`

Any other cross-module dependency fails CI.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.

## Public surface (ADR-0065)

| Route | Scope | Does |
| ----- | ----- | ---- |
| `GET /v1/wallets/{walletId}/balance` | `wallets:read` | Balance of one wallet |
| `GET /v1/wallets/{walletId}/transactions` | `wallets:read` | Its movements, newest first, from the ledger |
| `GET /v1/parties/{externalRef}/wallets` | `wallets:read` | A party's wallets, by the product's own reference |
| `POST /v1/wallets/transfers` | `wallets:write` + `Idempotency-Key` | 1 to 20 legs in one zero-sum ledger transaction |
| `POST /v1/wallets/withdrawals` | `wallets:write` + `Idempotency-Key` | Records value leaving custody (debit against the `withdrawal` account) |

- **No free credit.** Value enters a wallet only through a confirmed payment carrying
  `wallet_recipient_external_ref` (ADR-0037). Transfers move value, withdrawals record
  that it left; neither creates any.
- **Environment isolation.** Parties and wallets carry `environment` (`live` or
  `sandbox`); every read and mutation checks it. The `clearing` and `withdrawal`
  accounts are split by reference (`''` live, `sandbox`), see `SystemAccount`.
- **Locking.** A transfer locks every balance it touches in ascending wallet-id order
  (no deadlock between crossing transfers), then re-checks idempotency before posting —
  proven by `tests/Integration/TransferConcurrencyTest.php`.
- **History.** The ledger is the source of truth; `wallet_operations` keeps only what it
  does not carry (the caller's key, description, metadata, legs), joined by
  `ledger_transactions.external_ref`.
