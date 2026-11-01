<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

use Core\Support\Access\AccessScope;

/**
 * The operator console's project directory (ADR-0045) — every other
 * project-scoped listing (payments, wallets, notifications) starts from a
 * `projectId` an operator gets here.
 */
interface ListProjects
{
    /**
     * The scope is required (ADR-0069 §2): the listing never crosses the
     * organizations the account belongs to.
     */
    public function list(ListProjectsCommand $command, AccessScope $scope): ProjectPage;
}
