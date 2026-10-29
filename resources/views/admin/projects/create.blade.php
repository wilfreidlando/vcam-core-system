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
                <x-admin.field
                    name="organization_id"
                    label="Organisation"
                    type="select"
                    required
                    :value="$selectedOrganizationId"
                    placeholder="Sélectionner une organisation…"
                    :options="collect($organizations)->mapWithKeys(fn ($org) => [$org->id => $org->name])->all()" />
                @if (count($organizations) === 0)
                    <p class="mt-1 text-sm text-amber-800">
                        Aucune organisation n'existe encore — <a href="{{ route('admin.organizations.new') }}" class="underline">créez-en une d'abord</a>.
                    </p>
                @endif
            </div>

            <x-admin.field name="name" label="Nom" required />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Créer le projet
            </button>
        </form>
    </div>
</div>
@endsection
