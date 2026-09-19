# core/identity

**Bounded context:** `identity`
**Responsibility:** Organizations, Projects, API keys (machine identity, Plan 2);
Operators, RBAC, MFA (platform identity, Plan 1, ADR-0044).
**Delivery lot:** Lot 1 (Plan 2) + Lot 6 (Plan 1) — see [the delivery plan](../../docs/Plan_Delivery_MVP_Core_System.docx)

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

- `core/audit` (ADR-0029 — sensitive administrative actions, e.g. `api_key.issued`,
  `operator.created`, are recorded there; `identity` never reads it back)

## Operators (Plan 1) — bootstrap vs. self-service

A real operator never touches a CLI. Two distinct paths create an
`Operator`, and only one of them is CLI:

- **Bootstrap (ops/deployment only, happens once per environment):**
  `php artisan identity:create-operator {email} {name} {role}` — run by
  whoever deploys the stack, before any operator exists. It exists solely
  to solve the chicken-and-egg problem: `POST /v1/admin/operators`
  requires an authenticated `PlatformAdmin` (`operators:write`), so
  *something* has to create the very first one out of band. This is the
  same pattern as `php artisan make:admin`-style commands in most admin
  panels, or a one-time seeded superuser — it is ops tooling, not a
  product feature, and is never exposed over HTTP.
- **Self-service (every operator after the first, ADR-0050):**
  `POST /v1/admin/operators` (JSON) or `/admin/operators/create` (web
  console) — any existing `PlatformAdmin` creates the next operator
  through the same UI/API everything else in the console uses. This is
  the only path a real end user (an operator managing other operators)
  ever exercises.

MFA is TOTP (`pragmarx/google2fa`), not email/SMS-delivered OTP: on first
login the operator is shown a secret/QR code to scan into an authenticator
app (Google Authenticator, Authy, 1Password, …) on their own device;
every subsequent login asks for the 6-digit code that app generates
locally. Nothing is emailed or texted — there is no delivery channel to
secure, and the code is valid offline. This is deliberate for an
operator/admin plane (ADR-0044): email-based OTP would add an SMTP
dependency and a weaker security property (mailbox compromise = account
compromise) for a surface that's internal-only by design.

**Local dev only:** set `IDENTITY_DISABLE_TOTP_IN_LOCAL=true` to skip real
TOTP verification and accept any 6-digit code (`AlwaysValidTotpProvider`,
bound by `IdentityServiceProvider` in place of `Google2faTotpProvider`).
It requires `APP_ENV=local` **in addition to** the flag — that check is in
code, not just config, so it cannot activate anywhere a real operator's
credentials matter. `.env.example` already ships with it on.

## Ground rules

- No application — internal or external — reads this module's tables. Consumers use the
  Core's versioned API and its events.
- Cross-context data is joined by correlation identifiers (`external_ref`,
  `correlation_id`), never by a database join across contexts.
- Every monetary mutation is idempotent and every state change that others care about is
  published through the transactional outbox, inside the same transaction.
