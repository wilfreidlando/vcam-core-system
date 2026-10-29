@extends('layouts.admin')

@section('title', 'Modifier le projet')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Modifier le projet</h2>
        <p class="text-base text-slate-600 mb-4">
            Nom et statut. L'organisation propriétaire ne change pas ici —
            un projet ne change jamais d'organisation.
        </p>

        <form method="POST" action="{{ route('admin.projects.update', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <x-admin.field name="name" label="Nom" :value="$project->name" required autofocus />

            <x-admin.field
                name="status"
                label="Statut"
                type="select"
                :value="$project->status"
                required
                :options="collect($statuses)->mapWithKeys(fn ($status) => [$status => match ($status) {
                    'active' => 'Actif',
                    'suspended' => 'Suspendu',
                    'archived' => 'Archivé (définitif — révoque les clés)',
                    default => $status,
                }])->all()" />

            {{-- ADR-0069 §3 — a status change is audited with its reason. --}}
            <x-admin.field
                name="reason"
                label="Motif du changement de statut"
                type="textarea"
                rows="2"
                maxlength="500"
                hint="Obligatoire si le statut change." />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer les modifications
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-600 hover:text-slate-800 py-1.5 px-2.5">
                Annuler
            </a>
        </form>
    </div>
</div>
@endsection
