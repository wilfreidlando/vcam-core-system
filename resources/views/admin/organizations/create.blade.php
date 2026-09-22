@extends('layouts.admin')

@section('title', 'Nouvelle organisation')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Nouvelle organisation</h2>
        <p class="text-base text-slate-600 mb-4">
            Les organisations regroupent des projets sous un même client.
            Les projets se créent séparément, une fois l'organisation créée.
        </p>

        <form method="POST" action="{{ route('admin.organizations.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="name" class="block text-base font-medium text-slate-700 mb-1">Nom</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    autofocus
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                @error('name')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Créer l'organisation
            </button>
        </form>
    </div>
</div>
@endsection
