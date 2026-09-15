<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * The operator console's project directory (ADR-0045) — every other
 * project-scoped listing (payments, wallets, notifications) starts from a
 * `projectId` an operator gets here.
 */
interface ListProjects
{
    public function list(ListProjectsCommand $command): ProjectPage;
}
