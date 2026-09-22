@extends('layouts.admin')

@section('title', 'Nouveau projet')

@section('content')
<div class="max-w-md mx-auto">
    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Nouveau projet</h2>
        <p class="text-base text-slate-600 mb-4">
            Un projet appartient à exactement une organisation et sert
            d'unité à tout le reste (clés API, paiements, wallets).
        </p>

        <form method="POST" action="{{ route('admin.projects.store') }}" class="space-y-4">
            @csrf

            <div>
                <label for="organization_id" class="block text-base font-medium text-slate-700 mb-1">Organisation</label>
                <select
                    name="organization_id"
                    id="organization_id"
                    required
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm">
                    <option value="" disabled @selected(old('organization_id', $selectedOrganizationId) === '')>Sélectionner une organisation…</option>
                    @foreach ($organizations as $org)
                        <option value="{{ $org->id }}" @selected(old('organization_id', $selectedOrganizationId) === $org->id)>
                            {{ $org->name }}
                        </option>
                    @endforeach
                </select>
                @error('organization_id')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
                @if (count($organizations) === 0)
                    <p class="mt-1 text-sm text-amber-600">
                        Aucune organisation n'existe encore — <a href="{{ route('admin.organizations.new') }}" class="underline">créez-en une d'abord</a>.
                    </p>
                @endif
            </div>

            <div>
                <label for="name" class="block text-base font-medium text-slate-700 mb-1">Nom</label>
                <input
                    type="text"
                    name="name"
                    id="name"
                    value="{{ old('name') }}"
                    required
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm" />
                @error('name')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Créer le projet
            </button>
        </form>
    </div>
</div>
@endsection
