@extends('layouts.admin')

@section('title', $customer === null ? 'Nouveau client' : 'Modifier le client')

@section('content')
<div class="max-w-2xl space-y-6">
    <form method="POST"
          action="{{ $customer === null
              ? route('admin.projects.customers.store', $projectId)
              : route('admin.projects.customers.update.web', [$projectId, $customer->externalRef]) }}"
          class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        @if ($customer !== null)
            <input type="hidden" name="version" value="{{ $customer->version }}">
        @endif

        {{-- old() passe AVANT le modèle (défaut #22) : la saisie refusée par le serveur survit. --}}
        <x-admin.field name="external_ref" label="Référence chez le projet" required maxlength="128"
                       pattern="[A-Za-z0-9._:\-]+" :value="$customer?->externalRef"
                       :readonly="$customer !== null"
                       :class="$customer !== null ? 'bg-slate-100' : ''"
                       hint="L'identifiant opaque que le projet connaît : lettres, chiffres et . _ : -" />

        <x-admin.field name="display_name" label="Nom affiché" required maxlength="200" :value="$customer?->displayName" />

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- ADR-0083 §3 — the console never shows personal data in
                 clear: the value is masked, and the field starts empty.
                 Leaving it empty keeps the stored value; filling it
                 replaces it. Unmasking is an audited action, and the Core
                 does not offer one in this lot. --}}
            <x-admin.field name="email" label="Contact (courriel)" type="email" maxlength="255" optional autocomplete="off"
                           :hint="'Chiffré au repos, jamais affiché en clair en console.'.($customer !== null && $customer->email !== null ? ' Valeur actuelle : '.\Core\Support\Privacy\PersonalDataMask::email($customer->email).' — laissez le champ vide pour la conserver.' : '')" />
            <x-admin.field name="phone" label="Téléphone" maxlength="32" optional autocomplete="off"
                           :hint="$customer !== null && $customer->phone !== null ? 'Valeur actuelle : '.\Core\Support\Privacy\PersonalDataMask::phone($customer->phone).' — laissez le champ vide pour la conserver.' : null" />
        </div>

        <x-admin.field name="locale" label="Langue" maxlength="16" optional :value="$customer?->locale ?? 'fr'" />

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                {{ $customer === null ? 'Déclarer le client' : 'Enregistrer' }}
            </button>
            <a href="{{ route('admin.projects.customers.index', $projectId) }}"
               class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
        </div>
    </form>
</div>
@endsection
