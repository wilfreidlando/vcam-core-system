<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Contracts\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * The smoke test the delivery plan asks Lot 0/1 to demonstrate: a request
 * authenticated as a Project, correlated end to end, and logged. Genuinely
 * useful past that too — it is what an integrating product calls first to
 * confirm its credentials and see which environment and scopes they carry.
 */
final class WhoAmIController
{
    /**
     * Identify the calling Project.
     *
     * Resolves the bearer API key and returns which Project it belongs to,
     * in which environment (sandbox/live), and which scopes it carries. The
     * first call an integrating product should make to confirm its
     * credentials.
     */
    public function __invoke(ProjectContext $project): JsonResponse
    {
        Log::info('identity.whoami', [
            'project_id' => $project->projectId,
            'organization_id' => $project->organizationId,
            'environment' => $project->environment->value,
        ]);

        return new JsonResponse([
            'project_id' => $project->projectId,
            'organization_id' => $project->organizationId,
            'environment' => $project->environment->value,
            'scopes' => $project->scopes,
        ]);
    }
}
