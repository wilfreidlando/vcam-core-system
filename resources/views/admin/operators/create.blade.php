@extends('layouts.admin')

@section('title', 'Nouvel opérateur')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Nouvel opérateur</h2>
        <p class="text-sm text-slate-600 mb-4">
            Le mot de passe généré ne s'affiche qu'une seule fois, sur la
            page suivante, et n'est jamais stocké en clair — la
            configuration MFA se fait à la première connexion de
            l'opérateur, pas ici.
        </p>

        <form method="POST" action="{{ route('admin.operators.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                @error('email')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Nom</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm" />
                @error('name')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-slate-700 mb-1">Rôle</label>
                <select
                    name="role"
                    id="role"
                    required
                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm">
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                            {{ $role->value }}
                        </option>
                    @endforeach
                </select>
                @error('role')
                    <p class="mt-1 text-xs text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
                Créer l'opérateur
            </button>
        </form>
    </div>
</div>
@endsection
