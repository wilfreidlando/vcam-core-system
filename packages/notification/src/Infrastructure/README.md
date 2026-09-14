# Infrastructure

Adapters that implement Domain ports: Eloquent models and repositories, HTTP clients,
provider adapters, queue and cache concerns. Framework-coupled by design.

Nothing outside this module may reference anything in here — other modules depend on
`Contracts/` only.
