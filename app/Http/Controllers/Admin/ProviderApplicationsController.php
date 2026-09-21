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
use Core\Payment\Contracts\RegisterProviderApplication;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console web mirror of `Core\Admin\Presentation\Http\Controllers\RegisterProviderApplicationController`
 * (the JSON endpoint) — same port, same permission
 * (`provider-applications:write`), same self-audit requirement: `payment`
 * cannot depend on `audit` (module graph), so this controller records the
 * `provider_application.registered` entry itself after calling the port,
 * exactly like the JSON controller does.
 *
 * The private key is a write-once secret from the operator's point of
 * view too — this form never displays a previously-registered key back
 * (there is no read port for it; `ProviderApplicationRepository::save()`
 * is upsert-only), so rotating an application means re-entering both
 * keys, not editing one field.
 */
final class ProviderApplicationsController
{
    public function __construct(
        private readonly RegisterProviderApplication $registerProviderApplication,
        private readonly ListProviderApplications $listProviderApplications,
        private readonly GetProject $getProject,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function create(string $projectId, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            abort(403, 'The provider-applications:write permission is required.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Project not found.');
        }

        $applications = $this->listProviderApplications->list(new ListProviderApplicationsCommand($projectId));

        return response()->view('admin.provider-applications.create', [
            'title' => 'Provider applications · '.$project->name,
            'project' => $project,
            'applications' => $applications,
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            abort(403, 'The provider-applications:write permission is required.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        /** @var array{provider: string, public_key: string, private_key: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'provider' => ['required', 'string', 'max:255'],
            'public_key' => ['required', 'string', 'max:1024'],
            'private_key' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $this->registerProviderApplication->register(new RegisterProviderApplicationCommand(
                $projectId,
                $data['provider'],
                $data['public_key'],
                $data['private_key'],
            ));
        } catch (RuntimeException $e) {
            return back()->withInput()->withErrors(['provider' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'provider_application.registered',
            resource: 'provider_application:'.$projectId.':'.$data['provider'],
            before: null,
            after: [
                'project_id' => $projectId,
                'provider_code' => $data['provider'],
                'public_key' => $data['public_key'],
            ],
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
        ));

        return redirect()->route('admin.projects.show', ['projectId' => $projectId])
            ->with('status', "Provider application registered for '{$data['provider']}'.");
    }
}
