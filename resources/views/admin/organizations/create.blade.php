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

        {{-- Les erreurs de validation sont résumées par le layout (role="alert") ;
             chaque champ relie son message par aria-describedby / aria-invalid. --}}
        <form method="POST" action="{{ route('admin.organizations.store') }}" class="space-y-4">
            @csrf

            <x-admin.field name="name" label="Nom" required autofocus autocomplete="organization" />

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Créer l'organisation
            </button>
        </form>
    </div>
</div>
@endsection
