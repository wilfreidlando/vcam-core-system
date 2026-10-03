<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\ListApiKeys;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\RevokeApiKey;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Support\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console web self-service for Project API keys — review finding: this
 * plane used to be genuinely CLI-only, by deliberate original design (see
 * `IssueApiKeyCommand`'s/`RevokeApiKeyCommand`'s own history), unlike
 * organizations/projects/operators, which already had self-service.
 * User-directed reversal of that choice: an operator driving the console
 * from a browser had no way to hand a Project's own API key to the
 * business app that needs one, short of a raw CLI call this controller
 * cannot assume access to.
 *
 * `identity:issue-api-key`/`identity:revoke-api-key` still exist and now
 * share the exact same `Contracts\IssueApiKey`/`Contracts\RevokeApiKey`
 * ports this controller calls — no duplicated business logic between the
 * CLI and the console.
 *
 * Second review finding, user-directed: project creation now
 * auto-provisions a sandbox and a live key already (`CreateProject`), so
 * this form only exists for rotation (a lost or compromised key needs a
 * replacement) — an operator manually reaching for it never needs to type
 * scopes either, same reasoning as the auto-provisioned ones
 * (`ApiKeyScopes::ALL`, see its docblock).
 */
final class ApiKeysController
{
    public function __construct(
        private readonly ListApiKeys $listApiKeys,
        private readonly IssueApiKey $issueApiKey,
        private readonly RevokeApiKey $revokeApiKey,
        private readonly GetProject $getProject,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('api-keys:write')) {
            abort(403, 'La permission api-keys:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        return response()->view('admin.api-keys.create', [
            'title' => 'Clés API · '.$project->name,
            'project' => $project,
            'keysByEnvironment' => $this->keysByEnvironment($projectId),
            'created' => session('admin.api-keys.created'),
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('api-keys:write')) {
            abort(403, 'La permission api-keys:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        /** @var array{environment: string, mode?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'environment' => ['required', 'string', 'in:sandbox,live'],
            'mode' => ['nullable', 'string', 'in:generate,regenerate'],
        ]);

        $regenerate = ($data['mode'] ?? 'generate') === 'regenerate';

        try {
            $issued = $this->issueApiKey->issue(
                new IssueApiKeyCommand($projectId, Environment::from($data['environment']), ApiKeyScopes::ALL, replaceExisting: $regenerate),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['environment' => $e->getMessage()]);
        }

        // The plaintext key is shown exactly once, on this redirect —
        // same one-shot-secret pattern as a webhook endpoint's signing
        // secret or a newly-registered provider application's keys. No
        // separate audit call here: IssueApiKeyUseCase already self-audits
        // (`api_key.issued`) — unlike provider applications/webhook
        // endpoints, `identity` owning `AuditLogger` directly is not a
        // module-graph violation (audit already sits underneath identity
        // for operator/organization/project actions).
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash('admin.api-keys.created', [
            'environment' => $data['environment'],
            'prefix' => $issued->prefix,
            'plaintextToken' => $issued->plaintextToken,
        ]);

        return redirect()->route('admin.projects.api-keys.create', ['projectId' => $projectId]);
    }

    public function revoke(string $projectId, string $apiKeyId, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('api-keys:write')) {
            abort(403, 'La permission api-keys:write est requise.');
        }

        Validator::make(
            ['project_id' => $projectId, 'api_key_id' => $apiKeyId],
            ['project_id' => 'uuid', 'api_key_id' => 'uuid'],
        )->validate();

        try {
            $this->revokeApiKey->revoke(
                new RevokeApiKeyCommand($apiKeyId, projectId: $projectId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['apiKeyId' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.api-keys.create', ['projectId' => $projectId])
            ->with('status', 'Clé API révoquée et supprimée. Vous pouvez en générer une nouvelle.');
    }

    /**
     * ADR-0060 — at most one key per environment.
     *
     * @return array<string, \Core\Identity\Contracts\ApiKeySummaryView>
     */
    private function keysByEnvironment(string $projectId): array
    {
        $byEnvironment = [];
        foreach ($this->listApiKeys->list(new ListApiKeysCommand($projectId)) as $key) {
            if (! $key->revoked) {
                $byEnvironment[$key->environment->value] ??= $key;
            }
        }

        return $byEnvironment;
    }
}
