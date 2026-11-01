<?php

declare(strict_types=1);

namespace Tests\Architecture\Idor;

use Core\Identity\Contracts\OrganizationView;
use Core\Identity\Contracts\ProjectView;

/**
 * One organization of the scenario, its project, and a random value
 * planted in its names and data (`$secret`) so a leak is unmistakable.
 */
final readonly class Tenant
{
    public function __construct(
        public OrganizationView $organization,
        public ProjectView $project,
        public string $secret,
    ) {}

    /**
     * Values of this tenant that must never reach another tenant.
     *
     * @return list<string>
     */
    public function markers(): array
    {
        return [$this->secret, $this->organization->id, $this->project->id];
    }
}
