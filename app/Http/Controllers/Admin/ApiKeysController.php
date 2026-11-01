<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
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
    use ResolvesScopedProject;

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

        return $this->render($projectId, $operator, null);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): Response|RedirectResponse
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

        return $this->showOnce($projectId, $operator, $issued, 'Clé créée.');
    }

    public function rotate(string $projectId, string $apiKeyId, Request $request, OperatorContext $operator): Response|RedirectResponse
    {
        $this->authorize($operator, $projectId, $apiKeyId);

        /** @var array{grace_days: int|string|string} $data */
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

        return $this->showOnce($projectId, $operator, $issued, sprintf('Clé remplacée : l\'ancienne reste valide %d jour(s), le temps de déployer la nouvelle.', (int) $data['grace_days']));
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

    /**
     * @param array{prefix: string, plaintextToken: string}|null $created rendu dans CETTE réponse, jamais flashé
     */
    private function render(string $projectId, OperatorContext $operator, ?array $created): Response
    {
        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId), $operator->scope);
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        $keys = $this->listApiKeys->list(new ListApiKeysCommand($projectId), $operator->scope);

        return response()->view('admin.api-keys.create', [
            'title' => 'Clés API · '.$project->name,
            'project' => $project,
            'keys' => $keys,
            'templates' => ApiKeyScopes::TEMPLATES,
            'templateScopes' => array_map(ApiKeyScopes::template(...), array_combine(array_keys(ApiKeyScopes::TEMPLATES), array_keys(ApiKeyScopes::TEMPLATES))),
            'allScopes' => ApiKeyScopes::ALL,
            'graceDays' => ['min' => RotateApiKeyCommand::MIN_GRACE_DAYS, 'max' => RotateApiKeyCommand::MAX_GRACE_DAYS, 'default' => RotateApiKeyCommand::DEFAULT_GRACE_DAYS],
            'created' => $created,
        ]);
    }

    private function authorize(OperatorContext $operator, string $projectId, ?string $apiKeyId = null): void
    {
        $this->permittedScope($operator, 'api-keys:write');

        Validator::make(
            ['project_id' => $projectId, 'api_key_id' => $apiKeyId],
            ['project_id' => 'uuid', 'api_key_id' => 'nullable|uuid'],
        )->validate();

        // ADR-0069 §2 — the permission is not the scope: the project must
        // also be one the account may see (404 otherwise), and the account
        // must hold the permission in the project's organization (403).
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'api-keys:write');
    }

    /**
     * The plaintext key is shown exactly once, in THIS response (S-07,
     * ADR-0081) and nowhere else: it is rendered directly rather than
     * flashed into the session for a redirect, because a flash would write
     * the clear key to the session store, where it would outlive the
     * response that carries it. The status line uses `now()` for the same
     * reason: nothing of this response reaches the store.
     */
    private function showOnce(string $projectId, OperatorContext $operator, IssuedApiKeyView $issued, string $status): Response
    {
        // @phpstan-ignore-next-line — session()->now() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->now('status', $status);

        return $this->render($projectId, $operator, [
            'prefix' => $issued->prefix,
            'plaintextToken' => $issued->plaintextToken,
        ]);
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
