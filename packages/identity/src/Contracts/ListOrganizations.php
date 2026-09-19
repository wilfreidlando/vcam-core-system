<?php

declare(strict_types=1);

namespace Core\Identity\Contracts;

/**
 * The admin console's own organization listing (review finding, Lot 6
 * follow-up — replaces a workaround that de-duplicated organizations
 * out of a single page of `ListProjects` results, which under-counted
 * and mis-paginated as soon as the true organization count didn't fit
 * in one project page).
 */
interface ListOrganizations
{
    public function list(ListOrganizationsCommand $command): OrganizationPage;
}
