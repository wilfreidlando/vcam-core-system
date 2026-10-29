@extends('layouts.admin')

@section('title', 'Applications provider')
@section('subtitle', $project->name)

{{-- ADR-0066 — une application par provider. La clé privée
     n'est jamais réaffichée ; la laisser vide lors d'une modification garde
     celle déjà enregistrée. --}}
@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    {{-- Statut et erreurs : rendus par le layout (role="status" / role="alert"). --}}

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-1">Applications enregistrées</h2>
        <p class="text-sm text-slate-600 mb-4">
            Une application = les identifiants marchands de ce projet chez un provider.
            Sur un déploiement où le simulateur de paiement est activé (staging), un projet sans application
            passe par le simulateur intégré (aucun appel au provider, aucun argent réel).
        </p>

        @if (count($applications) === 0)
            <p class="text-base text-slate-600">Aucune application enregistrée pour ce projet.</p>
        @else
            {{-- D2 : x-admin.table (légende, en-têtes, défilement horizontal) au lieu d'un <table> écrit à la main. --}}
            <x-admin.table caption="Applications provider enregistrées" :headers="['Provider', 'Libellé', 'Clé publique', 'Mise à jour', '']">
                <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($applications as $application)
                        <tr>
                            <td class="px-4 py-2.5 text-sm font-mono">{{ $application->providerCode }}</td>
                            <td class="px-4 py-2.5 text-sm text-slate-700">{{ $application->label ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-sm font-mono text-slate-700" title="{{ $application->publicKey }}">{{ $application->maskedPublicKey }}</td>
                            <td class="px-4 py-2.5 text-sm text-slate-600 whitespace-nowrap">{{ $application->updatedAt !== null ? \Illuminate\Support\Carbon::parse($application->updatedAt)->format('d/m/Y H:i') : '—' }}</td>
                            <td class="px-4 py-2.5 text-sm text-right whitespace-nowrap">
                                <div class="flex items-center justify-end gap-1">
                                    <a href="{{ route('admin.projects.provider-applications.edit', ['projectId' => $project->id, 'applicationId' => $application->id]) }}#form"
                                       class="rounded-md px-2.5 py-1.5 text-indigo-700 hover:bg-indigo-50 font-medium">Modifier<span class="sr-only"> l'application {{ $application->providerCode }}</span></a>
                                    <x-admin.confirm-dialog
                                        title="Supprimer l'application {{ $application->providerCode }} ?"
                                        message="Les paiements de ce projet ne pourront plus passer par ce provider tant qu'une autre application n'est pas enregistrée."
                                        :action="route('admin.projects.provider-applications.delete', ['projectId' => $project->id, 'applicationId' => $application->id])"
                                        confirm="Supprimer">
                                        <x-slot:trigger>
                                            <button type="button" class="rounded-md px-2.5 py-1.5 text-rose-700 hover:bg-rose-50 font-medium">Supprimer<span class="sr-only"> l'application {{ $application->providerCode }}</span></button>
                                        </x-slot:trigger>
                                    </x-admin.confirm-dialog>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </x-admin.table>
        @endif
    </div>

    <div id="form" class="bg-white border border-slate-200 rounded-lg p-6 max-w-xl">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">
            {{ $editing !== null ? "Modifier l'application {$editing->providerCode}" : 'Nouvelle application' }}
        </h2>

        @if (count($availableProviders) === 0)
            <p class="text-base text-rose-700">Aucun adaptateur de provider de paiement n'est enregistré dans ce Core.</p>
        @else
            <form method="POST" action="{{ route('admin.projects.provider-applications.store', ['projectId' => $project->id]) }}" class="space-y-4">
                @csrf

                <x-admin.errors />

                @if ($editing !== null)
                    <input type="hidden" name="provider" value="{{ $editing->providerCode }}">
                @else
                    <x-admin.field name="provider" type="select" label="Provider" required class="font-mono"
                                   :options="array_combine($availableProviders, $availableProviders)"
                                   hint="Si l'application existe déjà pour ce provider, ses clés sont remplacées." />
                @endif

                <x-admin.field name="label" label="Libellé" optional maxlength="120" :value="$editing?->label" placeholder="ex. Boutique principale" />
                <x-admin.field name="public_key" label="Clé publique" required autocomplete="off" :value="$editing?->publicKey" class="font-mono" />
                <x-admin.field name="private_key" type="password" label="Clé privée" :required="$editing === null" autocomplete="new-password" class="font-mono"
                               :hint="$editing !== null
                                   ? 'Laissez vide pour conserver la clé privée actuelle (elle n\'est jamais réaffichée).'
                                   : 'Chiffrée au repos, jamais réaffichée ni journalisée.'" />

                <div class="flex items-center gap-3">
                    <button type="submit" class="inline-flex justify-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                        {{ $editing !== null ? 'Enregistrer les modifications' : "Enregistrer l'application" }}
                    </button>
                    @if ($editing !== null)
                        <a href="{{ route('admin.projects.provider-applications.create', ['projectId' => $project->id]) }}" class="text-sm text-slate-600 hover:text-slate-800">Annuler</a>
                    @endif
                </div>
            </form>
        @endif
    </div>

    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-600 hover:text-slate-800">Retour au projet</a>
</div>
@endsection
