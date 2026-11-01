@extends('layouts.admin')

@section('title', 'Nouvelle facture')

@section('content')
<div class="max-w-2xl space-y-6">
    <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
       class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">← Toutes les factures</a>

    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">La facture naît en brouillon</p>
        <p>
            Elle reçoit son numéro à l'émission, et sa créance n'est comptabilisée
            qu'à ce moment-là. Une facture en brouillon peut encore être
            corrigée — une facture émise, non.
        </p>
    </div>

    {{-- Les erreurs de validation sont rendues une seule fois, par le layout (role="alert"). --}}

    <form method="POST" action="{{ route('admin.projects.invoices.store', $projectId) }}"
          class="space-y-6 rounded-lg border border-slate-200 bg-white p-5">
        @csrf

        <div class="space-y-4">
            <x-admin.field name="customer_ref" label="Référence du client" required maxlength="128" class="font-mono"
                           hint="La référence que le projet connaît pour ce client." />

            <div class="grid gap-4 sm:grid-cols-3">
                <x-admin.field name="currency" label="Devise" optional minlength="3" maxlength="3" placeholder="XAF" class="font-mono uppercase" />
                <x-admin.field name="tax_rate_bps" type="number" label="Taxe (points de base)" min="0" max="10000" optional
                               hint="Le taux par défaut du projet s'applique si le champ reste vide." />
                <x-admin.field name="due_at" type="date" label="Échéance" optional />
            </div>
        </div>

        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold text-slate-900">Lignes</legend>

            @for ($index = 0; $index < 3; $index++)
                <div class="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-12">
                    <x-admin.field :name="'lines['.$index.'][description]'" label="Désignation" :required="$index === 0" :optional="$index !== 0"
                                   maxlength="500" wrapper-class="sm:col-span-5" />
                    {{-- Le serveur impose la quantité (défaut #32) : `required`, pré-remplie à 1. --}}
                    <x-admin.field :name="'lines['.$index.'][quantity]'" type="number" label="Qté" required min="1" :value="1"
                                   wrapper-class="sm:col-span-2" />
                    <x-admin.field :name="'lines['.$index.'][unit_amount_minor]'" type="number" label="Prix unitaire" min="0"
                                   :required="$index === 0" :optional="$index !== 0" wrapper-class="sm:col-span-3" />
                    <x-admin.field :name="'lines['.$index.'][tax_rate_bps]'" type="number" label="Taxe (points de base)" min="0" max="10000"
                                   optional wrapper-class="sm:col-span-2" />
                </div>
            @endfor

            <p class="text-sm text-slate-600">
                Les montants sont exprimés en unités mineures : 50000 XAF se saisit
                « 50000 ». Les lignes vides sont ignorées.
            </p>
        </fieldset>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
               class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
            <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                Créer le brouillon
            </button>
        </div>
    </form>
</div>
@endsection
