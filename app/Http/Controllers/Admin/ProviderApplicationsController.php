<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\GetProjectCommand;
use Core\Identity\Contracts\OperatorContext;
use Core\Payment\Contracts\ListProviderApplications;
use Core\Payment\Contracts\ListProviderApplicationsCommand;
use Core\Payment\Contracts\PaymentGatewayRegistry;
use Core\Payment\Contracts\ProviderApplicationView;
use Core\Payment\Contracts\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Support\Clock;
use Core\Support\Environment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0060 — a project's credentials at each payment provider, one
 * application per (provider, environment): list, create, edit (replace the
 * public and/or private key — an empty private key keeps the stored one),
 * delete. Every rejection is an operator-readable message, never a raw SQL
 * error ("duplicate key"). `payment` cannot depend on `audit`, so this
 * controller records the audit entries itself.
 */
final class ProviderApplicationsController
{
    public function __construct(
        private readonly RegisterProviderApplication $registerProviderApplication,
        private readonly ListProviderApplications $listProviderApplications,
        private readonly GetProject $getProject,
        private readonly PaymentGatewayRegistry $gateways,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        $project = $this->authorizedProject($projectId, $operator);

        return response()->view('admin.provider-applications.create', [
            'title' => 'Applications provider · '.$project->name,
            'project' => $project,
            'applications' => $this->applications($projectId),
            'availableProviders' => $this->availableProviders(),
            'editing' => null,
        ]);
    }

    public function edit(string $projectId, string $applicationId, OperatorContext $operator): Response|RedirectResponse
    {
        $project = $this->authorizedProject($projectId, $operator);
        Validator::make(['application_id' => $applicationId], ['application_id' => 'uuid'])->validate();

        $applications = $this->applications($projectId);
        $editing = null;
        foreach ($applications as $application) {
            if ($application->id === $applicationId) {
                $editing = $application;
            }
        }

        if ($editing === null) {
            return redirect()->route('admin.projects.provider-applications.create', ['projectId' => $projectId])
                ->withErrors(['application' => 'Application introuvable pour ce projet.']);
        }

        return response()->view('admin.provider-applications.create', [
            'title' => 'Modifier l\'application · '.$project->name,
            'project' => $project,
            'applications' => $applications,
            'availableProviders' => $this->availableProviders(),
            'editing' => $editing,
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        $this->authorizedProject($projectId, $operator);

        /** @var array{provider: string, environment: string, label?: string|null, public_key: string, private_key?: string|null} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            // Re-validated server-side against the adapter-backed list — a
            // made-up provider code can never be persisted.
            'provider' => ['required', 'string', 'in:'.implode(',', $this->availableProviders())],
            'environment' => ['required', 'string', 'in:live,sandbox'],
            'label' => ['nullable', 'string', 'max:120'],
            'public_key' => ['required', 'string', 'max:1024'],
            'private_key' => ['nullable', 'string', 'max:1024'],
        ]);

        $environment = Environment::from($data['environment']);

        try {
            $rotated = $this->registerProviderApplication->register(new RegisterProviderApplicationCommand(
                $projectId,
                $data['provider'],
                $data['public_key'],
                $data['private_key'] ?? null,
                $environment,
                $data['label'] ?? null,
            ));
        } catch (RuntimeException $e) {
            return back()->withInput($request->except('private_key'))->withErrors(['public_key' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: $rotated ? 'provider_application.rotated' : 'provider_application.registered',
            resource: 'provider_application:'.$projectId.':'.$data['provider'].':'.$environment->value,
            before: null,
            after: [
                'project_id' => $projectId,
                'provider_code' => $data['provider'],
                'environment' => $environment->value,
                'public_key' => $data['public_key'],
                'private_key_changed' => ($data['private_key'] ?? '') !== '',
            ],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.projects.provider-applications.create', ['projectId' => $projectId])
            ->with('status', $rotated
                ? "Application « {$data['provider']} » ({$environment->value}) mise à jour."
                : "Application « {$data['provider']} » ({$environment->value}) enregistrée.");
    }

    public function destroy(string $projectId, string $applicationId, OperatorContext $operator): RedirectResponse
    {
        $this->authorizedProject($projectId, $operator);
        Validator::make(['application_id' => $applicationId], ['application_id' => 'uuid'])->validate();

        try {
            $this->registerProviderApplication->delete($projectId, $applicationId);
        } catch (RuntimeException) {
            return back()->withErrors(['application' => 'Application introuvable pour ce projet.']);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'provider_application.deleted',
            resource: 'provider_application:'.$applicationId,
            before: ['project_id' => $projectId],
            after: null,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.projects.provider-applications.create', ['projectId' => $projectId])
            ->with('status', 'Application supprimée. Les paiements de cet environnement ne passeront plus par ce provider tant qu\'une autre application n\'est pas enregistrée.');
    }

    private function authorizedProject(string $projectId, OperatorContext $operator): \Core\Identity\Contracts\ProjectView
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            abort(403, 'La permission provider-applications:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            return $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }
    }

    /**
     * @return list<ProviderApplicationView>
     */
    private function applications(string $projectId): array
    {
        return $this->listProviderApplications->list(new ListProviderApplicationsCommand($projectId));
    }

    /**
     * The Core's real, adapter-backed provider codes — never free text.
     *
     * @return list<string>
     */
    private function availableProviders(): array
    {
        return array_map(
            static fn ($code): string => $code->value,
            $this->gateways->availableProviders(),
        );
    }
}
