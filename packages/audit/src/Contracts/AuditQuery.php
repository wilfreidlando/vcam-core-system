<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

/**
 * The read counterpart of `AuditLogger` (ADR-0044). `audit` has no `Domain/`
 * layer (ADR-0029 — no business rule of its own), so this port sits
 * directly in `Contracts/`, backed by `EloquentAuditQuery` in
 * `Infrastructure/`. `admin` is this port's only intended consumer today,
 * but it is a published Contracts port like any other module's — G3c makes
 * no exception for the operator console (ADR-0027: "Admin compose, il
 * n'interroge pas directement").
 */
interface AuditQuery
{
    public function search(AuditSearchCriteria $criteria): AuditSearchResult;
}
