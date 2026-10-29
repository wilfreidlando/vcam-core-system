# portal

The hosted customer portal of a project (ADR-0075, lot 13): the customer of
a SaaS project sees its subscription, pays its invoices and follows its usage —
without the project rebuilding the same space.

| Depends on | Why |
|---|---|
| `support` | clock, identifiers, unit of work |
| `identity` | the project API key that opens a session (`portal:write`) |
| `billing` | customers, subscriptions, invoices, entitlements, the payment of an invoice |
| `payment` | the payment channel a customer pays with |
| `audit` | every session opened is audited |

The portal owns **no business table**: only its sessions and its per-project
look. Everything else goes through `billing/Contracts`.

Entry: `POST /v1/portal/sessions` (server of the project) returns a one-time
link; the browser exchanges it for a cookie (`GET /portal/enter/{token}`).
