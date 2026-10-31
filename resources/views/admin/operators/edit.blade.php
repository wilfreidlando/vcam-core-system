@extends('layouts.admin')

@section('title', "Modifier l'opérateur")

@section('content')
@php
    $roleLabels = [
        'platform_admin' => 'Administrateur de plateforme',
        'finance_operator' => 'Opérateur financier',
        'support_operator' => 'Opérateur support',
        'provider_manager' => 'Responsable des prestataires',
        'read_only_auditor' => 'Auditeur (lecture seule)',
    ];
@endphp
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Modifier l'opérateur</h2>
        <p class="text-base text-slate-600 mb-4">
            Profil uniquement — email, nom et rôle. Les identifiants et la
            configuration MFA ne sont pas touchés ici.
        </p>

        @if (! $operatorRow)
            <x-admin.alert variant="warning">
                Les valeurs actuelles n'ont pas pu être chargées pour cet
                opérateur
                (id <code class="font-mono">{{ substr($operatorId, 0, 8) }}…</code>)
                — seule la recherche par liste est disponible, et cet id
                n'a pas été trouvé parmi les 200 premiers opérateurs.
                Remplissez les champs ci-dessous depuis zéro ;
                la soumission mettra bien à jour le bon opérateur.
            </x-admin.alert>
        @endif

        <form method="POST" action="{{ route('admin.operators.update.web', ['operatorId' => $operatorId]) }}" class="space-y-4">
            @csrf
            @method('PUT')

            {{-- B30 — `autocomplete="email"` : ce champ est un identifiant. --}}
            <x-admin.field name="email" label="Email" type="email" :value="$operatorRow->email ?? ''" required autofocus autocomplete="email" />

            <x-admin.field name="name" label="Nom" :value="$operatorRow->name ?? ''" required autocomplete="name" />

            {{-- ADR-0069 §1 : le rôle de plateforme est facultatif. Sans l'option vide,
                 un compte sans rôle de plateforme n'a rien de sélectionné et le navigateur
                 soumet la première option — `platform_admin`. --}}
            <x-admin.field
                name="role"
                label="Rôle"
                type="select"
                :value="$operatorRow->role ?? ''"
                placeholder="Aucun rôle de plateforme"
                :options="collect($roles)->mapWithKeys(fn ($role) => [$role->value => $roleLabels[$role->value] ?? $role->value])->all()" />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer les modifications
            </button>
        </form>
    </div>
</div>
@endsection
