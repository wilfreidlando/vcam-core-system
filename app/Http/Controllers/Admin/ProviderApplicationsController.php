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
 *
 * Two clarity gaps the form used to have (review finding): `provider` was
 * a free-text field an operator could mistype or fill with a provider
 * that has no adapter at all — it's now a `<select>` sourced from
 * `PaymentGatewayRegistry::availableProviders()`, the actual registered
 * gateways, never a made-up code. And nothing explained that one Project
 * has at most ONE application per provider (`save()` is upsert-keyed on
 * `(project_id, provider_code)`, per ADR-0051) — a MyCoolPay merchant
 * account can hold several distinct applications on MyCoolPay's own
 * side, each with its own key pair, but this Core pins exactly one of
 * them per (Project, provider) at a time; registering again for a
 * provider that already has one *rotates* it, it does not add a second.
 * The view now says this explicitly and highlights which of the
 * available providers already have an application registered.
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

    public function create(string $projectId, Request $request, OperatorContext $operator): Response
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            abort(403, 'La permission provider-applications:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        try {
            $project = $this->getProject->get(new GetProjectCommand($projectId));
        } catch (RuntimeException) {
            abort(404, 'Projet introuvable.');
        }

        $applications = $this->listProviderApplications->list(new ListProviderApplicationsCommand($projectId));
        $registeredProviderCodes = array_map(
            static fn ($application): string => $application->providerCode,
            $applications,
        );

        // `availableProviders()` — the Core's real, adapter-backed
        // provider codes (today: just `my-coolpay`; grows automatically
        // when MTN/Orange adapters are added) — never a free-text field
        // an operator could fill with a code that has no adapter at all.
        $availableProviders = array_map(
            static fn ($code): string => $code->value,
            $this->gateways->availableProviders(),
        );

        // Review finding, user-directed: an operator looking at "Applications
        // enregistrées" had no visible way to act on one — no button, no
        // link, nothing next to it saying how to rotate or revoke it. The
        // only mechanism (re-submitting the form below for the same
        // provider replaces it — see the class docblock) existed but was
        // undiscoverable without already knowing to scroll down and
        // re-fill it manually. Each listed application now links back
        // here with `?provider=`, which preselects that provider in the
        // dropdown below.
        $selectedProvider = (string) $request->query('provider', '');

        return response()->view('admin.provider-applications.create', [
            'title' => 'Applications provider · '.$project->name,
            'project' => $project,
            'applications' => $applications,
            'availableProviders' => $availableProviders,
            'registeredProviderCodes' => $registeredProviderCodes,
            'selectedProvider' => $selectedProvider,
        ]);
    }

    public function store(string $projectId, Request $request, OperatorContext $operator): RedirectResponse
    {
        if (! $operator->hasPermission('provider-applications:write')) {
            abort(403, 'La permission provider-applications:write est requise.');
        }

        Validator::make(['project_id' => $projectId], ['project_id' => 'uuid'])->validate();

        // The dropdown already constrains this, but a raw POST could
        // still submit anything — `in:` re-validates against the same
        // real, adapter-backed provider list server-side, so a made-up
        // provider code can never be persisted regardless of how the
        // request was built.
        $availableProviders = array_map(
            static fn ($code): string => $code->value,
            $this->gateways->availableProviders(),
        );

        /** @var array{provider: string, public_key: string, private_key: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'provider' => ['required', 'string', 'in:'.implode(',', $availableProviders)],
            'public_key' => ['required', 'string', 'max:1024'],
            'private_key' => ['required', 'string', 'max:1024'],
        ]);

        // Whether this call rotates an existing key pair or registers a
        // genuinely new one — checked before `register()` runs (which is
        // upsert-only and wouldn't tell us afterwards which one it was).
        // Only cosmetic (the audit action name and the success message),
        // but an operator rotating a key benefits from being told that's
        // what just happened, not just "registered" again.
        $existingProviderCodes = array_map(
            static fn ($application): string => $application->providerCode,
            $this->listProviderApplications->list(new ListProviderApplicationsCommand($projectId)),
        );
        $isRotation = in_array($data['provider'], $existingProviderCodes, true);

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
            action: $isRotation ? 'provider_application.rotated' : 'provider_application.registered',
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
            ->with('status', $isRotation
                ? "Clés remplacées pour « {$data['provider']} »."
                : "Application provider enregistrée pour « {$data['provider']} ».");
    }
}
