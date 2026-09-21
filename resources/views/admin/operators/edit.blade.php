@extends('layouts.admin')

@section('title', "Modifier l'opérateur")

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-base font-semibold text-slate-900 mb-1">Modifier l'opérateur</h2>
        <p class="text-sm text-slate-600 mb-4">
            Profil uniquement — email, nom et rôle. Les identifiants et la
            configuration MFA ne sont pas touchés ici.
        </p>

        @if (! $operatorRow)
            <div class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-4 py-3">
                <p class="text-sm text-amber-800">
                    Les valeurs actuelles n'ont pas pu être chargées pour cet
                    opérateur
                    (id <code class="font-mono">{{ substr($operatorId, 0, 8) }}…</code>)
                    — seule la recherche par liste est disponible, et cet id
                    n'a pas été trouvé parmi les 200 premiers opérateurs.
                    Remplissez les champs ci-dessous depuis zéro ;
                    la soumission mettra bien à jour le bon opérateur.
                </p>
            </div>
        @endif

        <form method="POST" action="{{ route('admin.operators.update.web', ['operatorId' => $operatorId]) }}" class="space-y-4">
            @csrf
            @method('PUT')

            <div>
                <label for="email" class="block text-sm font-medium text-slate-700 mb-1">Email</label>
                <input
                    type="email"
                    name="email"
                    id="email"
                    value="{{ old('email', $operatorRow->email ?? '') }}"
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
                    value="{{ old('name', $operatorRow->name ?? '') }}"
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
                        <option value="{{ $role->value }}" @selected(old('role', $operatorRow->role ?? null) === $role->value)>
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
                Enregistrer les modifications
            </button>
        </form>
    </div>
</div>
@endsection
