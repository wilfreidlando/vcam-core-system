# Contracts

**This module's published API to the rest of the Core.** Interfaces and read-model DTOs
that other bounded contexts are allowed to depend on.

Everything else in this package is internal. Deptrac fails the build if another module
reaches past this directory into `Domain/`, `Application/` or `Infrastructure/`.

Adding to this directory is a contract decision: it is the surface you must keep stable.
