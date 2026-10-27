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

            <div>
                <label for="email" class="block text-base font-medium text-slate-700 mb-1">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email') }}"
                    required
                    autofocus
                    autocomplete="off"
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                @error('email')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="role" class="block text-base font-medium text-slate-700 mb-1">Rôle</label>
                <select
                    name="role"
                    id="role"
                    required
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm">
                    {{-- Le rôle est obligatoire : une option vide et sélectionnée
                         par défaut empêche le navigateur de soumettre d'office
                         le rôle le plus privilégié (`platform_admin`, premier
                         de `OperatorRole::cases()`). --}}
                    <option value="" @selected(old('role') === null)>Choisir un rôle…</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->value }}" @selected(old('role') === $role->value)>
                            {{ $role->value }}
                        </option>
                    @endforeach
                </select>
                @error('role')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Envoyer l'invitation
            </button>
        </form>
    </div>
</div>
@endsection
