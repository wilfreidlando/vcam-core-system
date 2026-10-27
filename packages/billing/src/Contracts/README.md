# Contracts

**This module's published API to the rest of the Core.** The `billing` module owns the
customers of a project, its plan catalogue, its subscription contracts and its
invoices (ADR-0070, ADR-0071, ADR-0072, ADR-0073); the ports and read models below are the only
surface another module or the operator console may depend on. Everything else in this
package is internal — Deptrac fails the build if another module reaches past this
directory into `Domain/`, `Application/` or `Infrastructure/`. Adding to this directory
is a contract decision: it is the surface you must keep stable.

## Capabilities

- **Customers** (ADR-0070) — `UpsertCustomer`, `GetCustomer`, `ListCustomers`,
  `SuspendCustomer`, `ReactivateCustomer`, `EraseCustomer`.
- **Plan catalogue** (ADR-0071) — `CreatePlan`, `ListPlans`, `CreatePlanVersion`,
  `PublishPlanVersion`, `ArchivePlan`.
- **Subscription contracts** (ADR-0072) — `CreateSubscription`, `GetSubscription`,
  `ListSubscriptions`, `ListSubscriptionPeriods`.
- **Operator gestures on a contract** (ADR-0072 §6) — `CancelSubscription`,
  `ChangeSubscriptionPlan`, `PauseSubscription`, `ResumeSubscription`,
  `GrantComplimentaryPeriod`, `ConfirmSubscriptionPeriodPayment`.
- **Invoices** (ADR-0073, ADR-0094) — `CreateInvoice`, `IssueInvoice`,
  `VoidInvoice`, `MarkInvoiceUncollectible`, `GetInvoice`, `ListInvoices`.

## Rules the surface follows

- A status is a plain string, never an enum: `CustomerStatus`, `PlanStatus`,
  `PlanVisibility`, `PlanVersionStatus`, `SubscriptionStatus`, `PeriodKind`,
  `PeriodState`, `InvoiceStatus` and `InvoiceLineKind` live in `Domain/Model/`
  and must not escape the module (G3c).
- Money is an `int` of minor units plus a `string` currency — never a float (G5),
  never a `Money` object (ADR-0021).
- Timestamps are ISO-8601 strings: the wire format is the API's business.
- Every read port takes an `AccessScope` as a **required** parameter (ADR-0069 §2 /
  G16) — the public API passes `AccessScope::forProject($projectId)`, the console
  passes the operator's scope.
- The mutating commands carry the audit pair `$actor` (`operator:<uuid>`,
  `project:<uuid>` or `service:<name>`) and `$correlationId`.
