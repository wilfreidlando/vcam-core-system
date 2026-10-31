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

## The three flows

`flow` says what the session may do — it is persisted (`portal_sessions.flow`)
and travels with the cookie:

| Flow | What it opens | What it may change |
|---|---|---|
| `manage` (default) | the portal, from the home | everything the project allows |
| `pay_invoice` | straight to one invoice (`invoice_id`) | the same, the payment included |
| `read` | the same portal, read-only | nothing, ever |

`read` is the support operator's window: the very same pages, the very same
data, **no gesture**. The refusal lives in `PortalWorkspace` — the code that
performs the mutation — so a hand-crafted POST is refused exactly like the
button that is not drawn (the views hide it too, but a hidden button is not a
guarantee). A read session changes nothing at all: it refuses even the
`plan_code` of the call that opened it.

Secondary entry, off by default (ADR-0075 §2, ADR-0100): `GET|POST /portal/{projectId}/login` — the
customer types the phone the project knows them by and receives a 6-digit code on WhatsApp
(`direct_login_enabled` + `direct_login_session` in the settings). The code is stored as a keyed hash
only, valid 10 minutes, 5 attempts; the answer is the same whether the phone is known or not.
