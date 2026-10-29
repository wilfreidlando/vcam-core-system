@extends('layouts.admin')

@section('title', 'Nouvel opérateur')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Inviter un opérateur</h2>
        <p class="text-base text-slate-600 mb-4">
            L'invitation porte un jeton d'activation à usage unique, affiché
            une seule fois sur la page suivante et jamais stocké en clair.
            C'est <strong>l'invité</strong> qui choisit son mot de passe et
            enrôle son MFA : aucun mot de passe n'est choisi ni transmis
            par la plateforme. L'invitation est valable 72 heures.
        </p>

        <form method="POST" action="{{ route('admin.operators.store') }}" class="space-y-4">
            @csrf

            {{-- B30 — l'email de l'invité : `autocomplete="email"`, jamais `off` sur un identifiant. --}}
            <x-admin.field name="email" label="Email" type="email" required autofocus autocomplete="email" />

            {{-- Le rôle est obligatoire : une option vide et sélectionnée par défaut
                 empêche le navigateur de soumettre d'office le rôle le plus privilégié
                 (`platform_admin`, premier de `OperatorRole::cases()`). --}}
            <x-admin.field
                name="role"
                label="Rôle"
                type="select"
                required
                placeholder="Choisir un rôle…"
                :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $role->value])->all()" />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Envoyer l'invitation
            </button>
        </form>
    </div>
</div>
@endsection
