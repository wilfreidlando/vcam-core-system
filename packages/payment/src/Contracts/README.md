# Contracts

**This module's published API to the rest of the Core.** Interfaces and read-model DTOs
that other bounded contexts are allowed to depend on.

Everything else in this package is internal. Deptrac fails the build if another module
reaches past this directory into `Domain/`, `Application/` or `Infrastructure/`.

Adding to this directory is a contract decision: it is the surface you must keep stable.

## Capabilities

- **Starting a payment** — `StartPayment`, `StartPaymentCommand`,
  `PaymentView`, `PaymentChannel`, `PaymentFlow`, `PaymentPurpose`.
  `PaymentPurpose` (ADR-0076 §3, amended by ADR-0094 §9) is the **intention**
  of the collection: `direct` (the default — the project is paid), the
  `wallet_credit` (the caller opted into a wallet credit) or `invoice` (the
  money lands on `collections`, where `billing` allocates it onto a
  receivable). It is an **optional** parameter whose default is the
  historical behaviour: no published contract is broken (ADR-0086). An
  intention nobody honours is refused, never ignored.
- **Settlements** — `PaymentStatusChangeHandler`, `PaymentStatusChanged`,
  `PaymentStatusNotification`, `RecordMoneyException`, `ResolveMoneyException`.
