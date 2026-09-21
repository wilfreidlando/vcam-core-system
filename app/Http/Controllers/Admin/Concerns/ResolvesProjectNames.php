<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use RuntimeException;

/**
 * Several admin list pages (payments, webhook DLQ, reconciliation preview)
 * carry a `projectId` on every row but nothing more — a raw UUID means
 * nothing to an operator scanning a table (review finding: "les IDs sur
 * les listings au lieu des noms des projets"). `GetProject` has no
 * batch-by-ids variant, so this resolves each *unique* id once (a page's
 * row count is always small — 20-100 — so this stays a handful of calls,
 * never one per row) and caches misses too, so a project that's since
 * been deleted doesn't trigger a repeated lookup.
 */
trait ResolvesProjectNames
{
    /**
     * @param list<string> $projectIds
     * @return array<string, string> projectId => name (falls back to the
     *                               id itself if the project can't be
     *                               resolved, so the UI never shows a
     *                               blank cell)
     */
    private function resolveProjectNames(GetProject $getProject, array $projectIds): array
    {
        $names = [];
        foreach (array_unique($projectIds) as $projectId) {
            try {
                $names[$projectId] = $getProject->get(new GetProjectCommand($projectId))->name;
            } catch (RuntimeException) {
                $names[$projectId] = $projectId;
            }
        }

        return $names;
    }
}
