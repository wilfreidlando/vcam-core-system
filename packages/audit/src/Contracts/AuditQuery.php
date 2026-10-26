<?php

declare(strict_types=1);

namespace Core\Audit\Contracts;

use Core\Support\Access\AccessScope;

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
    /**
     * ADR-0069 §2 — the scope is a REQUIRED parameter.
     *
     * The journal is scoped by organization and by project: an account sees
     * the entries of the organizations and projects it may see. Entries that
     * concern neither — the platform's own events (console authentication,
     * console account management) — are readable by a platform role only,
     * because their actor is not a tenant's.
     */
    public function search(AuditSearchCriteria $criteria, AccessScope $scope): AuditSearchResult;
}
