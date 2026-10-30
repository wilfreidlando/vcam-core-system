# core/webhook

**Bounded context:** `webhook`
**Responsibility:** Consumer webhook endpoints, HMAC-signed delivery, retry/backoff, DLQ, controlled replay.
**Delivery lot:** Lot 4 — see [the delivery plan](../../docs/specs/plan-delivery-mvp.md)

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

Any other cross-module dependency fails CI.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.

## Ceiling per project — audit 2026-10-29 #17 / G-12

- **A project holds a bounded number of _active_ endpoints** (10 by default,
  `webhook.max_active_endpoints_per_project`), on the model of ADR-0082 §1's ten
  API keys per project. Every active endpoint receives every event of the
  project, so the fan-out is bounded per project — the console's
  `console-sensitive` throttle bounds the *rate* of the registrations, not
  their total. Past the ceiling, the registration is refused with
  **422 `too-many-webhook-endpoints`** (`Contracts\TooManyWebhookEndpoints`).
- **A deactivated endpoint does not count** (it receives nothing), and
  re-activating one is refused at the ceiling exactly like a registration:
  otherwise the ceiling would be a door any operator could walk around by
  deactivating first.
- **The count is read under the project's lock**, inside the registering
  transaction, so two concurrent registrations cannot both pass the ceiling.
  The lock is a row of this module's own table `webhook_project_locks` — one
  anchor row per project, created on the fly, so the very first registration
  of a project is serialised too (no cross-module table is locked, ADR-0033 §5).
