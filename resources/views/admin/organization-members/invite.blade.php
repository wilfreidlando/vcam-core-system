@extends('layouts.admin')

@section('title', 'Inviter un membre — '.$organization->name)

@section('content')
@php
    $roleLabels = [
        'org_owner' => 'Propriétaire',
        'org_admin' => 'Administrateur',
        'org_finance' => 'Financier',
        'org_support' => 'Support',
        'org_viewer' => 'Lecture seule',
    ];
@endphp
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">
            Inviter un membre dans {{ $organization->name }}
        </h2>
        <p class="text-base text-slate-600 mb-4">
            L'invité reçoit un jeton d'activation à usage unique, valable 72 h. Il choisit lui-même
            son mot de passe et enrôle son MFA à l'activation — aucun mot de passe n'est choisi par
            le système ni transmis.
        </p>

        <form method="POST" action="{{ route('admin.organizations.members.store', ['organizationId' => $organization->id]) }}" class="space-y-4">
            @csrf

            <x-admin.field name="email" label="Email" type="email" required autofocus autocomplete="email" />

            <x-admin.field
                name="role"
                label="Rôle"
                type="select"
                required
                hint="Seul un propriétaire peut attribuer ce rôle ; aucun rôle de plateforme ne s'attribue ici."
                :options="collect($roles)->mapWithKeys(fn ($role) => [$role => $roleLabels[$role] ?? $role])->all()" />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Envoyer l'invitation
            </button>
        </form>
    </div>
</div>
@endsection
