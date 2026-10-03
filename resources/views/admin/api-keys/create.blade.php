@extends('layouts.admin')

@section('title', 'Clés API')
@section('subtitle', $project->name)

{{-- ADR-0060 — exactement une clé live et une clé sandbox par projet.
     Générer (s'il n'y en a pas), Régénérer (remplace atomiquement : l'ancienne
     est rejetée dès que la nouvelle existe), Révoquer (supprime). --}}
@section('content')
<div class="max-w-3xl mx-auto space-y-6">

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if (! empty($created))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-base font-semibold text-emerald-800">Nouvelle clé {{ $created['environment'] ?? '' }} : {{ $created['prefix'] }}</p>
            <p class="mt-1 text-base text-emerald-700">
                Affichée uniquement maintenant, jamais stockée ni journalisée en clair — copiez-la avant de quitter cette page :
            </p>
            <code class="mt-1 block rounded bg-emerald-100 px-2 py-1.5 font-mono text-sm text-emerald-900 break-all">{{ $created['plaintextToken'] }}</code>
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        @foreach (['live' => 'Live', 'sandbox' => 'Sandbox'] as $env => $label)
            @php $key = $keysByEnvironment[$env] ?? null; @endphp
            <div class="bg-white border border-slate-200 rounded-lg p-6 flex flex-col" x-data="{ confirm: null }">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold text-slate-900">Clé {{ $label }}</h2>
                    <span @class([
                        'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                        'bg-rose-100 text-rose-700' => $env === 'live',
                        'bg-amber-100 text-amber-800' => $env === 'sandbox',
                    ])>{{ strtoupper($env) }}</span>
                </div>

                <p class="text-sm text-slate-600 mb-4">
                    @if ($env === 'live')
                        Paiements réels, argent réel. À ne jamais partager hors de vos serveurs.
                    @else
                        Tests d'intégration : paiements simulés, aucun mouvement d'argent réel.
                    @endif
                </p>

                @if ($key === null)
                    <p class="text-base text-slate-500 mb-4">Aucune clé {{ $label }} pour ce projet.</p>
                    <form method="POST" action="{{ route('admin.projects.api-keys.store', ['projectId' => $project->id]) }}" class="mt-auto">
                        @csrf
                        <input type="hidden" name="environment" value="{{ $env }}">
                        <input type="hidden" name="mode" value="generate">
                        <button type="submit" class="w-full rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                            Générer la clé {{ $label }}
                        </button>
                    </form>
                @else
                    <dl class="space-y-1.5 text-sm mb-4">
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Préfixe</dt><dd class="font-mono text-slate-900">{{ $key->prefix }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Créée le</dt><dd class="text-slate-900">{{ $key->createdAt->format('d/m/Y H:i') }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Dernière utilisation</dt><dd class="text-slate-900">{{ $key->lastUsedAt?->format('d/m/Y H:i') ?? 'jamais' }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Scopes</dt><dd class="font-mono text-xs text-slate-700 text-right">{{ implode(', ', $key->scopes) }}</dd></div>
                    </dl>

                    <div class="mt-auto grid grid-cols-2 gap-2">
                        <button type="button" @click="confirm = 'regenerate'" class="rounded-md border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-3 py-2 text-sm font-medium">Régénérer</button>
                        <button type="button" @click="confirm = 'revoke'" class="rounded-md border border-rose-300 text-rose-700 hover:bg-rose-50 px-3 py-2 text-sm font-medium">Révoquer</button>
                    </div>

                    <div x-show="confirm !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="confirm = null">
                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4">
                            <template x-if="confirm === 'regenerate'">
                                <form method="POST" action="{{ route('admin.projects.api-keys.store', ['projectId' => $project->id]) }}">
                                    @csrf
                                    <input type="hidden" name="environment" value="{{ $env }}">
                                    <input type="hidden" name="mode" value="regenerate">
                                    <p class="font-semibold text-slate-900 mb-2">Régénérer la clé {{ $label }} ?</p>
                                    <p class="text-sm text-slate-600 mb-4">{{ $key->prefix }} sera remplacée immédiatement : toute application qui l'utilise encore sera rejetée. La nouvelle clé s'affichera une seule fois.</p>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click="confirm = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                        <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Régénérer</button>
                                    </div>
                                </form>
                            </template>
                            <template x-if="confirm === 'revoke'">
                                <form method="POST" action="{{ route('admin.projects.api-keys.revoke', ['projectId' => $project->id, 'apiKeyId' => $key->id]) }}">
                                    @csrf
                                    <p class="font-semibold text-slate-900 mb-2">Révoquer la clé {{ $label }} ?</p>
                                    <p class="text-sm text-slate-600 mb-4">{{ $key->prefix }} sera supprimée : toute application qui l'utilise sera rejetée. Vous pourrez en générer une nouvelle ensuite.</p>
                                    <div class="flex justify-end gap-2">
                                        <button type="button" @click="confirm = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                        <button type="submit" class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Révoquer</button>
                                    </div>
                                </form>
                            </template>
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
        Retour au projet
    </a>
</div>
@endsection
