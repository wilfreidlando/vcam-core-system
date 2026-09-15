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
| `src/Contracts` | Write side: `AuditEntry` (the DTO) and `AuditLogger` (`record()`). Read side (ADR-0044): `AuditSearchCriteria`/`AuditEntryView`/`AuditSearchResult` and `AuditQuery` (`search()`). |
| `src/Infrastructure/Persistence/Eloquent` | `AuditLogRecord`, `EloquentAuditLogger`, `EloquentAuditQuery` |

A `Domain/` layer is worth adding the day a real rule appears — a retention
policy, a redaction rule for `context`/`before`/`after` — not before.

## Module dependencies

**None.** Sits at the bottom of the stack, same as `ledger` and `identity`.

## Who may depend on this module

Any module with a genuine need to record or read a sensitive action, added to
[`tests/Architecture/ModuleGraph.php`](../../tests/Architecture/ModuleGraph.php)
one at a time as that need becomes real — never speculatively. `identity` is
first (ADR-0029): issuing or revoking an API key is exactly the kind of
operation this journal exists for. `admin` follows (ADR-0044), for the read
side only (`AuditQuery`) — it never writes an entry itself.

## Ground rules

- `audit_log` is append-only by convention: no code path updates or deletes a
  row. Revisit with a restricted PostgreSQL role if that needs to become
  structural rather than conventional.
- `AuditLogger` never queries its own table back — it is write-only by design.
  Consultation belongs to the Core Admin operator console (Lot 6), via the
  separate `AuditQuery` port (ADR-0044). Like any other module, `admin`
  reaches `audit` only through `Contracts/` — never raw Eloquent (G3c,
  ADR-0027).
- Never put a secret or an unhashed credential in `context`, `before` or
  `after` — the table is not encrypted at the field level.
