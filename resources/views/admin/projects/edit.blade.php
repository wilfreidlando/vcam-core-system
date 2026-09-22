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

            <div>
                <label for="name" class="block text-base font-medium text-slate-700 mb-1">Nom</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name', $project->name) }}"
                    required
                    autofocus
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                @error('name')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="status" class="block text-base font-medium text-slate-700 mb-1">Statut</label>
                <select
                    name="status"
                    id="status"
                    required
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm bg-white">
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}" @selected(old('status', $project->status) === $status)>
                            {{ $status === 'active' ? 'Actif' : 'Suspendu' }}
                        </option>
                    @endforeach
                </select>
                @error('status')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer les modifications
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
                Annuler
            </a>
        </form>
    </div>
</div>
@endsection
