@extends('layouts.admin')

@section('title', 'Nouvelle facture')

@section('content')
<div class="max-w-2xl space-y-6">
    <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
       class="text-sm font-medium text-slate-600 hover:text-slate-900">← Toutes les factures</a>

    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">La facture naît en brouillon</p>
        <p>
            Elle reçoit son numéro à l'émission, et sa créance n'est inscrite au
            grand livre qu'à ce moment-là. Une facture en brouillon peut encore être
            corrigée — une facture émise, non.
        </p>
    </div>

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    <form method="POST" action="{{ route('admin.projects.invoices.store', $projectId) }}"
          class="space-y-6 rounded-lg border border-slate-200 bg-white p-5">
        @csrf

        <div class="space-y-4">
            <div>
                <label for="customer_ref" class="block text-sm font-medium text-slate-700">Référence du client</label>
                <input id="customer_ref" name="customer_ref" required value="{{ old('customer_ref') }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                <p class="mt-1 text-xs text-slate-500">La référence que le projet connaît pour ce client.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label for="currency" class="block text-sm font-medium text-slate-700">Devise</label>
                    <input id="currency" name="currency" maxlength="3" value="{{ old('currency') }}"
                           placeholder="XAF"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label for="tax_rate_bps" class="block text-sm font-medium text-slate-700">Taxe (‰)</label>
                    <input id="tax_rate_bps" name="tax_rate_bps" type="number" min="0" max="10000"
                           value="{{ old('tax_rate_bps') }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <p class="mt-1 text-xs text-slate-500">Le taux par défaut du projet s'applique si le champ reste vide.</p>
                </div>
                <div>
                    <label for="due_at" class="block text-sm font-medium text-slate-700">Échéance</label>
                    <input id="due_at" name="due_at" type="date" value="{{ old('due_at') }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
        </div>

        <div class="space-y-3">
            <p class="text-sm font-semibold text-slate-900">Lignes</p>

            @for ($index = 0; $index < 3; $index++)
                <div class="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-12">
                    <div class="sm:col-span-5">
                        <label for="lines_{{ $index }}_description" class="block text-xs font-medium text-slate-600">Désignation</label>
                        <input id="lines_{{ $index }}_description" name="lines[{{ $index }}][description]" required
                               value="{{ old('lines.'.$index.'.description') }}"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="lines_{{ $index }}_quantity" class="block text-xs font-medium text-slate-600">Qté</label>
                        <input id="lines_{{ $index }}_quantity" name="lines[{{ $index }}][quantity]" type="number" min="1"
                               value="{{ old('lines.'.$index.'.quantity', 1) }}"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-3">
                        <label for="lines_{{ $index }}_unit_amount_minor" class="block text-xs font-medium text-slate-600">Prix unitaire</label>
                        <input id="lines_{{ $index }}_unit_amount_minor" name="lines[{{ $index }}][unit_amount_minor]"
                               type="number" min="0" required
                               value="{{ old('lines.'.$index.'.unit_amount_minor') }}"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div class="sm:col-span-2">
                        <label for="lines_{{ $index }}_tax_rate_bps" class="block text-xs font-medium text-slate-600">Taxe (‰)</label>
                        <input id="lines_{{ $index }}_tax_rate_bps" name="lines[{{ $index }}][tax_rate_bps]"
                               type="number" min="0" max="10000"
                               value="{{ old('lines.'.$index.'.tax_rate_bps') }}"
                               class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
            @endfor

            <p class="text-xs text-slate-500">
                Les montants sont exprimés en unités mineures (G5) : 50000 XAF se saisit
                « 50000 ». Les lignes vides sont ignorées par l'API.
            </p>
        </div>

        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
               class="text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Créer le brouillon
            </button>
        </div>
    </form>
</div>
@endsection
