@extends('layouts.admin')

@section('title', 'Projet')

@section('content')
@php
    // Permission checked here, not just in the controller (same pattern as
    // organizations/index.blade.php's "+ New organization" button):
    // OperatorContext resolved directly since $has() is only defined in the
    // layout's own @php block, out of scope for a child @section('content').
    $operator = app(\Core\Identity\Contracts\OperatorContext::class);
    $allows = fn (string $permission): bool => $operator->scopeFor($permission)->allowsProject($project->id);

    // ADR-0081 S-07 — la clé n'est lue que dans la réponse qui la porte.
    // Elle ne vient jamais du store de session : un flash y survivrait à
    // la réponse qui le contient (écart #4 de la recette du 2026-10-29).
    $apiKey = $createdApiKey ?? null;

    // [libellé, URL, permission requise (null = toujours affiché)]
    // ADR-0092 §4 — le commerce du projet : clients (ADR-0070), catalogue (ADR-0071)
    // et abonnements (ADR-0072) ; chaque entrée ne s'ouvre qu'avec la permission qui la gouverne,
    // vérifiée dans l'organisation propre du projet.
    $links = [
        ['Paiements de ce projet', route('admin.payments.index', ['project_id' => $project->id]), null],
        ['Portefeuilles de ce projet', route('admin.projects.wallets.index', ['projectId' => $project->id]), null],
        ['Notifications de ce projet', route('admin.projects.notifications.index', ['projectId' => $project->id]), 'notifications:read'],
        ['Enregistrer une application prestataire de paiement', route('admin.projects.provider-applications.create', ['projectId' => $project->id]), 'provider-applications:write'],
        ['Enregistrer un point d\'entrée pour les événements', route('admin.projects.webhook-endpoints.index', ['projectId' => $project->id]), 'webhook-endpoints:write'],
        ['Sessions WhatsApp', route('admin.projects.channel-sessions.index', ['projectId' => $project->id]), 'channel-sessions:write'],
        ['Clés API', route('admin.projects.api-keys.create', ['projectId' => $project->id]), 'api-keys:write'],
        ['Clients de ce projet', route('admin.projects.customers.index', ['projectId' => $project->id]), 'customers:read'],
        ['Offres de ce projet', route('admin.projects.plans.index', ['projectId' => $project->id]), 'plans:read'],
        ['Abonnements de ce projet', route('admin.projects.subscriptions.index', ['projectId' => $project->id]), 'subscriptions:read'],
        ['Factures de ce projet', route('admin.projects.invoices.index', ['projectId' => $project->id]), 'invoices:read'],
        ['Comptabilité et balance', route('admin.projects.ledger', ['projectId' => $project->id]), 'wallets:read'],
        ['Indicateurs', route('admin.projects.indicators', ['projectId' => $project->id]), 'invoices:write'],
        ['Créances', route('admin.projects.receivables.index', ['projectId' => $project->id]), 'invoices:read'],
        ['Usage et limites', route('admin.projects.usage', ['projectId' => $project->id]), 'usage:read'],
        ['Portail client', route('admin.projects.portal', ['projectId' => $project->id]), 'portal:write'],
        ['Export des données', route('admin.projects.exports', ['projectId' => $project->id]), 'exports:write'],
        ['Réglages du commerce', route('admin.projects.commerce-settings', ['projectId' => $project->id]), 'plans:write'],
    ];
@endphp
<div class="space-y-6">
    @if (! empty($apiKey))
        <x-admin.alert variant="success" title="Projet créé — sa clé API a été générée.">
            <p class="text-base">
                Affichée uniquement maintenant, jamais stockée ni journalisée en clair — copiez-la avant de quitter cette page :
            </p>
            <code class="mt-2 block rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-sm text-emerald-900 break-all">{{ $apiKey }}</code>
        </x-admin.alert>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        @if($allows('projects:write'))
            <div class="flex justify-end mb-2">
                <a href="{{ route('admin.projects.edit', ['projectId' => $project->id]) }}"
                   class="inline-block py-1.5 px-2.5 text-base font-medium text-indigo-700 hover:text-indigo-900">
                    Modifier
                </a>
            </div>
        @endif
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-base">
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->id }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">Organisation</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->organizationId }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500">Nom</p>
                <p class="text-slate-900">{{ $project->name }}</p>
            </div>
            <div>
                <p class="text-sm uppercase tracking-wider text-slate-500 font-semibold">Statut</p>
                <p>
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                        'bg-emerald-100 text-emerald-700' => $project->status === 'active',
                        'bg-slate-100 text-slate-700' => $project->status !== 'active',
                    ])>
                        {{ $project->status }}
                    </span>
                </p>
            </div>
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        @foreach ($links as [$linkLabel, $linkUrl, $linkPermission])
            @if ($linkPermission === null || $allows($linkPermission))
                <a href="{{ $linkUrl }}"
                   class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
                    <span class="font-medium text-slate-900">{{ $linkLabel }}</span>
                    <span class="text-indigo-700" aria-hidden="true">→</span>
                </a>
            @endif
        @endforeach
    </div>

    <div class="text-right">
        <a href="{{ route('admin.projects.index', ['organization_id' => $project->organizationId]) }}" class="inline-block py-1.5 px-2.5 text-sm text-indigo-700 hover:text-indigo-900 font-medium">
            ← Retour aux projets
        </a>
    </div>
</div>
@endsection
