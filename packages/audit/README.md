# core/audit

**Bounded context:** `audit`
**Responsibility:** Append-only audit journal of sensitive operations, correlated end to end.
**Delivery lot:** Lot 1 — see [docs/specs/plan-delivery-mvp.md](../../docs/specs/plan-delivery-mvp.md)

## Internal structure — intentionally thin (ADR-0029)

This module has no business rule of its own: it records what a caller tells it
happened, it does not decide what deserves recording. So it skips the usual
`Domain`/`Application` ceremony:

| Directory | Contains |
| --------- | -------- |
| `src/Contracts` | `AuditEntry` (the DTO — this module's entire model) and `AuditLogger` (the one method: `record()`) |
| `src/Infrastructure/Persistence/Eloquent` | `AuditLogRecord` + `EloquentAuditLogger` |

A `Domain/` layer is worth adding the day a real rule appears — a retention
policy, a redaction rule for `context`/`before`/`after` — not before.

## Module dependencies

**None.** Sits at the bottom of the stack, same as `ledger` and `identity`.

## Who may depend on this module

Any module with a genuine need to record a sensitive action, added to
[`tests/Architecture/ModuleGraph.php`](../../tests/Architecture/ModuleGraph.php)
one at a time as that need becomes real — never speculatively. `identity` is
first (ADR-0029): issuing or revoking an API key is exactly the kind of
operation this journal exists for.

## Ground rules

- `audit_log` is append-only by convention: no code path updates or deletes a
  row. Revisit with a restricted PostgreSQL role if that needs to become
  structural rather than conventional.
- This module never queries its own table back through `AuditLogger` — it is
  a write-only port by design. Consultation belongs to the Core Admin operator
  console (Lot 6), which reads `audit_log` directly because it is the one
  place allowed to.
- Never put a secret or an unhashed credential in `context`, `before` or
  `after` — the table is not encrypted at the field level.
