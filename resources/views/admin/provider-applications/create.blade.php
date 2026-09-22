@extends('layouts.admin')

@section('title', 'Applications provider')
@section('subtitle', $project->name)

@section('content')
<div class="max-w-md mx-auto space-y-6">

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    {{-- Existing applications — never shows the private key (there is no
         read path for it; ProviderApplicationRepository::save() is
         upsert-only), just confirmation of what's registered. --}}
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Applications enregistrées</h2>
        @if (count($applications) === 0)
            <p class="text-base text-slate-500">Aucune application provider enregistrée pour ce projet pour le moment.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($applications as $application)
                    <li class="py-2 flex items-center justify-between gap-2 text-base">
                        <div class="min-w-0">
                            <span class="font-mono font-medium text-slate-900">{{ $application->providerCode }}</span>
                            <span class="block font-mono text-sm text-slate-500 truncate" title="{{ $application->publicKey }}">
                                {{ $application->publicKey }}
                            </span>
                        </div>
                        {{-- Review finding, user-directed: rotating this
                             application's keys already worked (re-submitting
                             the form below for the same provider replaces
                             it — see the class docblock), but nothing next
                             to a listed application said so or made it
                             reachable — an operator had to already know
                             that and scroll down to re-fill the form
                             themselves. This preselects the provider below. --}}
                        <a href="{{ route('admin.projects.provider-applications.create', ['projectId' => $project->id, 'provider' => $application->providerCode]) }}#provider-application-form"
                           class="shrink-0 text-sm font-medium text-indigo-600 hover:text-indigo-800">
                            Remplacer →
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div id="provider-application-form" class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">
            {{ $selectedProvider !== '' ? "Remplacer l'application « {$selectedProvider} »" : 'Enregistrer une application provider' }}
        </h2>
        <p class="text-base text-slate-600 mb-3">
            Une « application » correspond aux identifiants marchands
            propres à ce projet chez un provider de paiement — par exemple
            une paire de clés publique/privée MyCoolPay, obtenue depuis le
            tableau de bord marchand de ce provider.
        </p>

        {{-- The two conceptual points that used to be missing from this
             form entirely (review finding): the provider list is real,
             and a project has AT MOST ONE application per provider. --}}
        <div class="mb-5 rounded-md border border-indigo-100 bg-indigo-50 px-3 py-2.5 text-sm text-indigo-900 leading-relaxed">
            <p>
                Le champ <span class="font-semibold">Provider</span> ci-dessous
                ne liste que les providers pour lesquels ce Core a
                effectivement un adaptateur — pas un code en texte libre à
                saisir soi-même correctement.
            </p>
            <p class="mt-1.5">
                <span class="font-semibold">Une seule application par provider.</span>
                Un même compte provider (par exemple MyCoolPay) peut avoir
                plusieurs applications distinctes sur son propre tableau de
                bord, chacune avec ses propres clés — mais ce projet n'en
                retient <em>qu'une seule</em> à la fois par provider.
                Enregistrer à nouveau pour un provider qui en a déjà une
                <span class="font-semibold">la remplace</span>
                (écrase la paire de clés) ; cela n'en crée pas une seconde
                à côté.
            </p>
        </div>

        <form method="POST" action="{{ route('admin.projects.provider-applications.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="provider" class="block text-base font-medium text-slate-700 mb-1">Provider</label>
                @if (count($availableProviders) === 0)
                    <p class="text-base text-rose-600">
                        Aucun adaptateur de provider de paiement n'est encore enregistré dans ce Core — il n'y a rien à sélectionner.
                    </p>
                @else
                    <select
                        name="provider"
                        id="provider"
                        required
                        autofocus
                        class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm font-mono bg-white">
                        @foreach ($availableProviders as $code)
                            <option value="{{ $code }}" @selected(old('provider', $selectedProvider) === $code)>
                                {{ $code }}@if (in_array($code, $registeredProviderCodes, true)) — déjà enregistrée, l'enregistrement la remplacera @endif
                            </option>
                        @endforeach
                    </select>
                @endif
                @error('provider')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="public_key" class="block text-base font-medium text-slate-700 mb-1">Clé publique</label>
                <input
                    type="text"
                    name="public_key"
                    id="public_key"
                    value="{{ old('public_key') }}"
                    required
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm font-mono" />
                @error('public_key')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="private_key" class="block text-base font-medium text-slate-700 mb-1">Clé privée</label>
                <input
                    type="password"
                    name="private_key"
                    id="private_key"
                    required
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm font-mono" />
                <p class="mt-1 text-sm text-slate-500">Chiffrée au repos, plus jamais affichée après l'enregistrement.</p>
                @error('private_key')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                @disabled(count($availableProviders) === 0)
                @class([
                    'w-full inline-flex justify-center items-center rounded-md px-4 py-2.5 text-base font-medium shadow-sm',
                    'bg-indigo-600 hover:bg-indigo-700 text-white' => count($availableProviders) > 0,
                    'bg-slate-200 text-slate-500 cursor-not-allowed' => count($availableProviders) === 0,
                ])>
                Enregistrer l'application
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
                Annuler
            </a>
        </form>
    </div>
</div>
@endsection
