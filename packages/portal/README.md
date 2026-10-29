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
| `notification` | the code of the direct login leaves through `SendTextNotification` (ADR-0100) |

The portal owns **no business table**: only its sessions and its per-project
look. Everything else goes through `billing/Contracts`.

Entry: `POST /v1/portal/sessions` (server of the project) returns a one-time
link; the browser exchanges it for a cookie (`GET /portal/enter/{token}`).

Secondary entry, off by default (ADR-0075 §2, ADR-0100): `GET|POST /portal/{projectId}/login` — the
customer types the phone the project knows them by and receives a 6-digit code on WhatsApp
(`direct_login_enabled` + `direct_login_session` in the settings). The code is stored as a keyed hash
only, valid 10 minutes, 5 attempts; the answer is the same whether the phone is known or not.
