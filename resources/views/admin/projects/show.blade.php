@extends('layouts.admin')

@section('title', 'Projet')

@section('content')
<div class="space-y-6">
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-sm">
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->id }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">Organisation</p>
                <p class="font-mono text-slate-700 break-all">{{ $project->organizationId }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider font-semibold text-slate-500">Nom</p>
                <p class="text-slate-900">{{ $project->name }}</p>
            </div>
            <div>
                <p class="text-xs uppercase tracking-wider text-slate-500 font-semibold">Statut</p>
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
        <a href="{{ route('admin.payments.index', ['project_id' => $project->id]) }}"
           class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <span class="font-medium text-slate-900">Paiements de ce projet</span>
            <span class="text-indigo-600">→</span>
        </a>
        <a href="{{ route('admin.projects.wallets.index', ['projectId' => $project->id]) }}"
           class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <span class="font-medium text-slate-900">Wallets de ce projet</span>
            <span class="text-indigo-600">→</span>
        </a>
        {{-- Permission checked here, not just in the controller (same
             pattern as organizations/index.blade.php's "+ New organization"
             button): OperatorContext resolved directly since $has() is
             only defined in the layout's own @php block, out of scope for
             a child @section('content'). --}}
        @if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('provider-applications:write'))
            <a href="{{ route('admin.projects.provider-applications.create', ['projectId' => $project->id]) }}"
               class="flex items-center justify-between rounded-lg border border-slate-200 bg-white p-4 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
                <span class="font-medium text-slate-900">Enregistrer une application provider de paiement</span>
                <span class="text-indigo-600">→</span>
            </a>
        @endif
    </div>

    <div class="text-right">
        <a href="{{ route('admin.projects.index', ['organization_id' => $project->organizationId]) }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
            ← Retour aux projets
        </a>
    </div>
</div>
@endsection
