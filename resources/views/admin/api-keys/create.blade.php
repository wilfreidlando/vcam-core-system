@extends('layouts.admin')

@section('title', 'Clés API')
@section('subtitle', $project->name)

@section('content')
<div class="max-w-md mx-auto space-y-6">

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
            <p class="text-base font-semibold text-emerald-800">Clé créée : {{ $created['prefix'] }}</p>
            <p class="mt-1 text-base text-emerald-700">
                Affichée uniquement maintenant, jamais stockée ni journalisée en clair — copiez-la avant de quitter cette page :
            </p>
            <code class="mt-1 block rounded bg-emerald-100 px-2 py-1.5 font-mono text-sm text-emerald-900 break-all">{{ $created['plaintextToken'] }}</code>
        </div>
    @endif

    {{-- Existing keys — never shows the secret (there is no read path for
         it; only its hash is persisted), just enough to identify and
         revoke one. --}}
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Clés existantes</h2>
        @if (count($apiKeys) === 0)
            <p class="text-base text-slate-500">Aucune clé API pour ce projet pour le moment.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($apiKeys as $key)
                    <li class="py-2.5" x-data="{ confirmRevoke: false }">
                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                <span class="font-mono font-medium text-slate-900 truncate">{{ $key->prefix }}</span>
                                <span class="ml-2 inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-mono text-slate-700">{{ $key->environment->value }}</span>
                            </div>
                            @if ($key->revoked)
                                <span class="shrink-0 inline-flex rounded-full bg-rose-100 px-2 py-0.5 text-xs font-mono text-rose-700">révoquée</span>
                            @else
                                <button
                                    @click="confirmRevoke = true"
                                    type="button"
                                    class="shrink-0 text-sm font-medium text-rose-600 hover:text-rose-800">
                                    Révoquer
                                </button>
                                <form
                                    x-show="confirmRevoke"
                                    x-cloak
                                    @submit="confirmRevoke = false"
                                    method="POST"
                                    action="{{ route('admin.projects.api-keys.revoke', ['projectId' => $project->id, 'apiKeyId' => $key->id]) }}"
                                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                                    @click.self="confirmRevoke = false">
                                    @csrf
                                    <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                                        <p class="font-semibold text-slate-900 mb-2">Révoquer {{ $key->prefix }} ?</p>
                                        <p class="text-sm text-slate-600 mb-4">
                                            Toute application l'utilisant sera immédiatement rejetée. Cette action est irréversible.
                                        </p>
                                        <div class="flex justify-end gap-2">
                                            <button type="button" @click="confirmRevoke = false"
                                                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                            <button type="submit"
                                                    class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Confirmer</button>
                                        </div>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Remplacer une clé API</h2>
        <p class="text-base text-slate-600 mb-3">
            Une clé sandbox et une clé live ont déjà été générées automatiquement à la création du projet.
            Utilisez ce formulaire uniquement pour en émettre une nouvelle — en cas de perte ou de compromission
            d'une clé existante, révoquée séparément ci-dessus. Elle n'est affichée qu'une seule fois, juste
            après sa création, et donne accès à l'ensemble de l'API, comme les clés déjà en place.
        </p>

        <form method="POST" action="{{ route('admin.projects.api-keys.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="environment" class="block text-base font-medium text-slate-700 mb-1">Environnement</label>
                <select
                    name="environment"
                    id="environment"
                    required
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm bg-white">
                    <option value="sandbox" @selected(old('environment', 'sandbox') === 'sandbox')>Sandbox</option>
                    <option value="live" @selected(old('environment') === 'live')>Live</option>
                </select>
                <p class="mt-1 text-sm text-slate-500">Sandbox et live sont totalement isolés — aucun mouvement d'argent réel en sandbox.</p>
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Émettre la clé
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
                Retour au projet
            </a>
        </form>
    </div>
</div>
@endsection
