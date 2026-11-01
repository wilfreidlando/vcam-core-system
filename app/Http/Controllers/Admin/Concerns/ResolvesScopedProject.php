<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\ProjectView;
use Core\Support\Access\AccessScope;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * ADR-0069 §2 / S-04 — the console's two authorization questions, asked
 * in one place:
 *
 * 1. **May the account do this at all?** `permittedScope()` narrows the
 *    account's scope to the organizations where it holds the permission.
 *    Empty ⇒ 403: the account holds the permission nowhere, which reveals
 *    nothing about any resource. Otherwise the page reads through that
 *    narrowed scope — exactly what the permission opens, never an
 *    organization the account merely sees through another role.
 * 2. **May it do it on this project?** `resolveScopedProject()` resolves the
 *    project in the account's scope: a project it may not see is "not
 *    found" (404), never "forbidden" — the answer must not confirm that the
 *    resource exists. A project it sees but where its role lacks the
 *    permission is 403: its existence is no secret to the account.
 *
 * The mutation paths (issue a key, register an endpoint, activate one…)
 * used to check the permission only — a permission is not a scope: an
 * account holding `api-keys:write` in its own organization had the same
 * right on every other organization's project.
 */
trait ResolvesScopedProject
{
    /**
     * The account's scope narrowed to `$permission`, or 403 when it holds
     * the permission nowhere.
     */
    private function permittedScope(OperatorContext $operator, string $permission): AccessScope
    {
        $scope = $operator->scopeFor($permission);

        if ($scope->isEmpty()) {
            abort(403, sprintf('La permission %s est requise.', $permission));
        }

        return $scope;
    }

    /**
     * Resolves the project in the operator's scope, or answers 404; with a
     * permission, answers 403 when the account's role in the project's
     * organization does not carry it.
     *
     * @return string the project id, once it is known to be in scope
     */
    private function resolveScopedProject(GetProject $getProject, OperatorContext $operator, string $projectId, ?string $permission = null): string
    {
        $this->scopedProject($getProject, $operator, $projectId, $permission);

        return $projectId;
    }

    /**
     * Same as `resolveScopedProject()`, returning the project itself.
     */
    private function scopedProject(GetProject $getProject, OperatorContext $operator, string $projectId, ?string $permission = null): ProjectView
    {
        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $getProject->get(new GetProjectCommand($projectId), $operator->scope);
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        if ($permission !== null && ! $operator->scopeFor($permission)->allowsProject($project->id)) {
            abort(403, sprintf('La permission %s est requise sur ce projet.', $permission));
        }

        return $project;
    }
}
