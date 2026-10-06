@extends('layouts.admin')

@section('title', 'Applications provider')
@section('subtitle', $project->name)

{{-- ADR-0060 — une application par (provider, environnement). La clé privée
     n'est jamais réaffichée ; la laisser vide lors d'une modification garde
     celle déjà enregistrée. --}}
@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Applications enregistrées</h2>
        <p class="text-sm text-slate-600 mb-4">
            Une application = les identifiants marchands de ce projet chez un provider, pour un environnement.
            Sans application <span class="font-semibold">sandbox</span>, les paiements sandbox passent par le simulateur
            intégré (aucun appel au provider, aucun argent réel).
        </p>

        @if (count($applications) === 0)
            <p class="text-base text-slate-500">Aucune application enregistrée pour ce projet.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="py-2 pr-4">Provider</th>
                            <th class="py-2 pr-4">Environnement</th>
                            <th class="py-2 pr-4">Libellé</th>
                            <th class="py-2 pr-4">Clé publique</th>
                            <th class="py-2 pr-4">Mise à jour</th>
                            <th class="py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($applications as $application)
                            <tr x-data="{ confirmDelete: false }">
                                <td class="py-2.5 pr-4 font-mono">{{ $application->providerCode }}</td>
                                <td class="py-2.5 pr-4">
                                    <span @class([
                                        'inline-flex rounded-md px-2 py-0.5 text-xs font-semibold',
                                        'bg-rose-100 text-rose-700' => $application->environment === 'live',
                                        'bg-amber-100 text-amber-800' => $application->environment === 'sandbox',
                                    ])>{{ strtoupper($application->environment) }}</span>
                                </td>
                                <td class="py-2.5 pr-4 text-slate-700">{{ $application->label ?? '—' }}</td>
                                <td class="py-2.5 pr-4 font-mono text-slate-700" title="{{ $application->publicKey }}">{{ $application->maskedPublicKey }}</td>
                                <td class="py-2.5 pr-4 text-slate-500">{{ $application->updatedAt !== null ? \Illuminate\Support\Carbon::parse($application->updatedAt)->format('d/m/Y H:i') : '—' }}</td>
                                <td class="py-2.5 text-right whitespace-nowrap">
                                    <a href="{{ route('admin.projects.provider-applications.edit', ['projectId' => $project->id, 'applicationId' => $application->id]) }}#form"
                                       class="text-indigo-600 hover:text-indigo-800 font-medium">Modifier</a>
                                    <button type="button" @click="confirmDelete = true" class="ml-3 text-rose-600 hover:text-rose-800 font-medium">Supprimer</button>
                                    <form x-show="confirmDelete" x-cloak method="POST"
                                          action="{{ route('admin.projects.provider-applications.delete', ['projectId' => $project->id, 'applicationId' => $application->id]) }}"
                                          class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="confirmDelete = false">
                                        @csrf
                                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4 text-left whitespace-normal" @click.stop>
                                            <p class="font-semibold text-slate-900 mb-2">Supprimer l'application {{ $application->providerCode }} ({{ $application->environment }}) ?</p>
                                            <p class="text-sm text-slate-600 mb-4">Les paiements {{ $application->environment }} de ce projet ne pourront plus passer par ce provider tant qu'une autre application n'est pas enregistrée.</p>
                                            <div class="flex justify-end gap-2">
                                                <button type="button" @click="confirmDelete = false" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                                <button type="submit" class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Supprimer</button>
                                            </div>
                                        </div>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    <div id="form" class="bg-white border border-slate-200 rounded-lg p-6 max-w-xl">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">
            {{ $editing !== null ? "Modifier l'application {$editing->providerCode} (".strtoupper($editing->environment).')' : 'Nouvelle application' }}
        </h2>

        @if (count($availableProviders) === 0)
            <p class="text-base text-rose-600">Aucun adaptateur de provider de paiement n'est enregistré dans ce Core.</p>
        @else
            <form method="POST" action="{{ route('admin.projects.provider-applications.store', ['projectId' => $project->id]) }}" class="space-y-4">
                @csrf

                @if ($editing !== null)
                    <input type="hidden" name="provider" value="{{ $editing->providerCode }}">
                    <input type="hidden" name="environment" value="{{ $editing->environment }}">
                @else
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="provider" class="block text-sm font-medium text-slate-700 mb-1">Provider</label>
                            <select name="provider" id="provider" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base bg-white font-mono">
                                @foreach ($availableProviders as $code)
                                    <option value="{{ $code }}" @selected(old('provider') === $code)>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="environment" class="block text-sm font-medium text-slate-700 mb-1">Environnement</label>
                            <select name="environment" id="environment" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base bg-white">
                                <option value="live" @selected(old('environment', 'live') === 'live')>Live</option>
                                <option value="sandbox" @selected(old('environment') === 'sandbox')>Sandbox</option>
                            </select>
                        </div>
                    </div>
                    <p class="text-xs text-slate-500">Si l'application existe déjà pour ce provider et cet environnement, ses clés sont remplacées.</p>
                @endif

                <div>
                    <label for="label" class="block text-sm font-medium text-slate-700 mb-1">Libellé <span class="text-slate-400">(facultatif)</span></label>
                    <input type="text" name="label" id="label" value="{{ old('label', $editing?->label) }}" maxlength="120"
                           class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" placeholder="ex. Boutique principale">
                </div>

                <div>
                    <label for="public_key" class="block text-sm font-medium text-slate-700 mb-1">Clé publique</label>
                    <input type="text" name="public_key" id="public_key" value="{{ old('public_key', $editing?->publicKey) }}" required autocomplete="off"
                           class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base font-mono">
                    @error('public_key')
                        <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="private_key" class="block text-sm font-medium text-slate-700 mb-1">Clé privée</label>
                    <input type="password" name="private_key" id="private_key" autocomplete="new-password" @if ($editing === null) required @endif
                           class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base font-mono">
                    <p class="mt-1 text-xs text-slate-500">
                        @if ($editing !== null)
                            Laissez vide pour conserver la clé privée actuelle (elle n'est jamais réaffichée).
                        @else
                            Chiffrée au repos, jamais réaffichée ni journalisée.
                        @endif
                    </p>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex justify-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                        {{ $editing !== null ? 'Enregistrer les modifications' : "Enregistrer l'application" }}
                    </button>
                    @if ($editing !== null)
                        <a href="{{ route('admin.projects.provider-applications.create', ['projectId' => $project->id]) }}" class="text-sm text-slate-500 hover:text-slate-700">Annuler</a>
                    @endif
                </div>
            </form>
        @endif
    </div>

    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">Retour au projet</a>
</div>
@endsection
