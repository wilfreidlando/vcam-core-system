# Application

Use cases (one class, one behaviour), the DTOs they take and return, and the
transactional orchestration around them. Depends on `Domain` only — it talks to the
outside exclusively through Domain ports.

A use case is where a business operation becomes atomic: open the transaction, apply
the domain rules, persist, write the event to the outbox, commit.
