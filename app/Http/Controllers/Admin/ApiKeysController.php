<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Contracts\ListApiKeys;
use Core\Identity\Contracts\ListApiKeysCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Identity\Contracts\RevokeApiKey;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Identity\Contracts\RotateApiKey;
use Core\Identity\Contracts\RotateApiKeyCommand;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console web self-service for Project API keys (ADR-0082): several named
 * keys per project, scopes chosen from a template, optional network
 * restriction and expiry, guided rotation, emergency revocation with a
 * reason. The CLI (`identity:*-api-key`) calls the same `Contracts` ports.
 */
final class ApiKeysController
{
    public function __construct(
        private readonly ListApiKeys $listApiKeys,
        private readonly IssueApiKey $issueApiKey,
        private readonly RotateApiKey $rotateApiKey,
        private readonly RevokeApiKey $revokeApiKey,
        private readonly GetProject $getProject,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        $this->authorize($operator, $projectId);

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        $keys = $this->listApiKeys->list(new ListApiKeysCommand($projectId));

        return response()->view('admin.api-keys.create', [
            'title' => 'Clés API · '.$project->name,
            'project' => $project,
            'keys' => $keys,
            'templates' => ApiKeyScopes::TEMPLATES,
            'templateScopes' => array_map(ApiKeyScopes::template(...), array_combine(array_keys(ApiKeyScopes::TEMPLATES), array_keys(ApiKeyScopes::TEMPLATES))),
            'allScopes' => ApiKeyScopes::ALL,
            'graceDays' => ['min' => RotateApiKeyCommand::MIN_GRACE_DAYS, 'max' => RotateApiKeyCommand::MAX_GRACE_DAYS, 'default' => RotateApiKeyCommand::DEFAULT_GRACE_DAYS],
            'created' => session('admin.api-keys.created'),
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $this->authorize($operator, $projectId);

        /** @var array{name: string, template: string, scopes?: list<string>|null, allowed_cidrs?: string|null, expires_at?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'template' => ['required', 'string', 'in:'.implode(',', [...array_keys(ApiKeyScopes::TEMPLATES), 'custom'])],
            'scopes' => ['required_if:template,custom', 'nullable', 'array'],
            'scopes.*' => ['string', 'in:'.implode(',', ApiKeyScopes::ALL)],
            'allowed_cidrs' => ['nullable', 'string', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);

        $scopes = $data['template'] === 'custom'
            ? ($data['scopes'] ?? [])
            : ApiKeyScopes::template($data['template']);

        try {
            $issued = $this->issueApiKey->issue(
                new IssueApiKeyCommand(
                    projectId: $projectId,
                    scopes: $scopes,
                    name: $data['name'],
                    allowedCidrs: self::cidrs($data['allowed_cidrs'] ?? null),
                    expiresAt: ($data['expires_at'] ?? null) !== null ? new DateTimeImmutable($data['expires_at'], new DateTimeZone('UTC')) : null,
                ),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['name' => $e->getMessage()]);
        }

        return $this->showOnce($projectId, $issued, 'Clé créée.');
    }

    public function rotate(string $projectId, string $apiKeyId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $this->authorize($operator, $projectId, $apiKeyId);

        /** @var array{grace_days: int|string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'grace_days' => ['required', 'integer', 'between:'.RotateApiKeyCommand::MIN_GRACE_DAYS.','.RotateApiKeyCommand::MAX_GRACE_DAYS],
        ]);

        try {
            $issued = $this->rotateApiKey->rotate(
                new RotateApiKeyCommand($apiKeyId, projectId: $projectId, graceDays: (int) $data['grace_days']),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['apiKeyId' => $e->getMessage()]);
        }

        return $this->showOnce($projectId, $issued, sprintf('Clé remplacée : l\'ancienne reste valide %d jour(s), le temps de déployer la nouvelle.', (int) $data['grace_days']));
    }

    public function revoke(string $projectId, string $apiKeyId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $this->authorize($operator, $projectId, $apiKeyId);

        /** @var array{reason: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->revokeApiKey->revoke(
                new RevokeApiKeyCommand($apiKeyId, reason: $data['reason'], projectId: $projectId),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            );
        } catch (RuntimeException $e) {
            return back()->withErrors(['apiKeyId' => $e->getMessage()]);
        }

        return redirect()->route('admin.projects.api-keys.create', ['projectId' => $projectId])
            ->with('status', 'Clé révoquée : toute application qui l\'utilise est rejetée dès maintenant.');
    }

    private function authorize(OperatorContext $operator, string $projectId, ?string $apiKeyId = null): void
    {
        if (! $operator->hasPermission('api-keys:write')) {
            abort(403, 'La permission api-keys:write est requise.');
        }

        Validator::make(
            ['project_id' => $projectId, 'api_key_id' => $apiKeyId],
            ['project_id' => 'uuid', 'api_key_id' => 'nullable|uuid'],
        )->validate();
    }

    /**
     * The plaintext key is shown exactly once, on this redirect (S-07);
     * the use case already audits the issuance.
     */
    private function showOnce(string $projectId, IssuedApiKeyView $issued, string $status): RedirectResponse
    {
        // @phpstan-ignore-next-line — session()->flash() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->flash('admin.api-keys.created', [
            'prefix' => $issued->prefix,
            'plaintextToken' => $issued->plaintextToken,
        ]);

        return redirect()->route('admin.projects.api-keys.create', ['projectId' => $projectId])->with('status', $status);
    }

    /**
     * One block per line, or separated by commas or spaces.
     *
     * @return list<string>
     */
    private static function cidrs(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $parts = preg_split('/[\s,]+/', $raw);
        if ($parts === false) {
            return [];
        }

        return array_values(array_filter($parts, static fn (string $cidr): bool => $cidr !== ''));
    }
}
