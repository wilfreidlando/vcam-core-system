@extends('layouts.admin')

@section('title', "Modifier l'organisation")

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Modifier l'organisation</h2>
        <p class="text-base text-slate-600 mb-4">
            Nom et statut. Une organisation suspendue reste visible ici,
            mais ses projets et portefeuilles ne sont pas eux-mêmes suspendus
            automatiquement.
        </p>

        <form method="POST" action="{{ route('admin.organizations.update', ['organizationId' => $organization->id]) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <x-admin.field name="name" label="Nom" :value="$organization->name" required autofocus autocomplete="organization" />

            <x-admin.field
                name="status"
                label="Statut"
                type="select"
                :value="$organization->status"
                :options="collect($statuses)->mapWithKeys(fn ($status) => [$status => $status === 'active' ? 'Active' : 'Suspendue'])->all()"
                required />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer les modifications
            </button>

            <a href="{{ route('admin.organizations.index') }}" class="block text-center text-base text-slate-600 hover:text-slate-800 py-1.5 px-2.5">
                Annuler
            </a>
        </form>
    </div>
</div>
@endsection
