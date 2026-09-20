<?php

declare(strict_types=1);

namespace Core\Payment\Contracts;

use RuntimeException;

/**
 * Registers (or rotates, if one already exists — the underlying
 * `ProviderApplicationRepository::save()` is an upsert) a Project's own
 * application/merchant-account with a payment provider. Reachable from the
 * CLI (`payment:register-provider-application`) and, on the trusted
 * operator-authenticated plane, an admin-only HTTP endpoint — never from
 * the Project/API-key plane: a Project cannot grant itself another
 * Project's payment credentials.
 */
interface RegisterProviderApplication
{
    /**
     * No audit trail here: `payment` is not permitted to depend on `audit`
     * (`tests/Architecture/ModuleGraph.php`), unlike `identity`
     * (ADR-0029). A caller on the operator-authenticated admin plane
     * (which IS permitted to depend on `audit`) is responsible for
     * recording its own audit entry after invoking this port — mirroring
     * how `Core\Admin\Presentation\Http\Controllers\*` already audits
     * actions in other modules it orchestrates.
     *
     * @throws RuntimeException if `$command->projectId` does not identify
     *                          an existing Project. The concrete exception
     *                          type lives in the Identity module; Contracts
     *                          must not reference it (G3c).
     */
    public function register(RegisterProviderApplicationCommand $command): void;
}
