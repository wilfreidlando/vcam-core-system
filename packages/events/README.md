# core/events

**Bounded context:** `events`
**Responsibility:** Transactional outbox — write side only (ADR-0032).
**Delivery lot:** Write side — Lot 2 (parallel). Relay/dispatch — Lot 4.

## Internal structure — write side only, deliberately (ADR-0032)

| Directory | Contains |
| --------- | -------- |
| `src/Contracts` | `DomainEvent` (the DTO) and `EventOutbox` (`append()` — the one method) |
| `src/Infrastructure/Persistence/Eloquent` | `EventOutboxRecord` + `EloquentEventOutbox` |

No `Domain/`, no `Application/`, no `Presentation/`: this module has no
business rule yet. A caller appends an event inside its own database
transaction; nothing reads `event_outbox` back until the Lot 4 relay exists.

## Module dependencies

**None.** Sits at the bottom of the stack, same as `ledger`, `identity`,
`audit`.

## Ground rules

- `append()` must be called **inside** the same transaction as the state
  change it describes (garde-fou G8c, verified once real callers exist).
- Nothing outside this module reads `event_outbox` — there is no query
  method on `EventOutbox` on purpose. The Lot 4 relay is the sole reader,
  and it will very likely bypass the port entirely (a batch poll needs
  different guarantees than a single append).
