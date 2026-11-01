@extends('layouts.admin')

@section('title', "Nouvelle correction d'exploitation")

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Quatre yeux, toujours</p>
        <p>
            La correction que vous proposez ne comptabilise rien tant qu'une seconde
            personne ne l'a pas validée. Elle ne modifie aucune écriture existante : elle
            produit une nouvelle transaction équilibrée, à partir du seul montant et des
            seuls comptes que vous choisissez.
        </p>
        <p class="mt-2">
            Une correction est toujours une contre-passation : elle nomme l'opération
            qu'elle corrige. Choisissez d'abord le projet, puis l'opération à contrer-passer.
        </p>
    </div>

    {{-- Les erreurs sont aussi rendues par le bloc global du layout ; le formulaire a son résumé focalisable. --}}

    @if (count($projects) === 0)
        <div class="rounded-md bg-amber-50 border border-amber-200 px-4 py-3 text-sm text-amber-800">
            Aucun projet n'est visible dans votre périmètre : une correction d'exploitation
            porte toujours sur un projet.
        </div>
    @elseif ($selectedProjectId === null)
        <div class="bg-white border border-slate-200 rounded-lg p-6 space-y-3">
            <h2 class="font-semibold text-slate-900">Sur quel projet ?</h2>
            <p class="text-sm text-slate-600">
                Une correction ne porte que sur les écritures de son propre projet : commencez
                par le choisir.
            </p>
            <ul class="divide-y divide-slate-100">
                @foreach ($projects as $project)
                    <li class="py-2">
                        <a href="{{ route('admin.ledger-adjustments.new', ['project_id' => $project->id]) }}"
                           class="text-indigo-700 hover:text-indigo-900 font-medium">{{ $project->name }}</a>
                        <span class="text-xs font-mono text-slate-600 ml-2">{{ $project->id }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <form method="POST" action="{{ route('admin.ledger-adjustments.store') }}" class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
            @csrf

            <input type="hidden" name="project_id" value="{{ $selectedProjectId }}">

            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-600 mb-1">Projet</p>
                @php $selectedProject = collect($projects)->firstWhere('id', $selectedProjectId); @endphp
                <p class="text-base text-slate-900">
                    {{ $selectedProject !== null ? $selectedProject->name : $selectedProjectId }}
                </p>
                <a href="{{ route('admin.ledger-adjustments.new') }}" class="text-sm text-indigo-700 hover:text-indigo-900">Changer de projet</a>
            </div>

            <x-admin.errors />

            <div class="space-y-4">
                @if (count($transactions) === 0)
                    <p class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-800">
                        Ce projet n'a aucune écriture récente à contrer-passer : une correction se
                        rattache toujours à une transaction existante. Saisissez son identifiant
                        ci-dessous.
                    </p>
                @else
                    @php
                        $transactionOptions = ['' => '— Choisir la transaction corrigée —'];
                        foreach ($transactions as $transaction) {
                            $transactionOptions[$transaction->id] = \Illuminate\Support\Carbon::parse($transaction->createdAt)->format('d/m/Y H:i')
                                .' — '.$transaction->type
                                .(count($transaction->lines) > 0
                                    ? ' — '.\Core\Support\Money\Money::ofMinor($transaction->lines[0]->amountMinor, $transaction->lines[0]->currency)->groupedAmount().' '.$transaction->lines[0]->currency
                                    : '')
                                .' — '.substr($transaction->id, 0, 8).'…';
                        }
                    @endphp
                    <x-admin.field name="reverses_transaction_id" type="select" label="Transaction à contrer-passer"
                                   :options="$transactionOptions"
                                   :hint="'Les '.count($transactions).' écritures les plus récentes du projet. Une correction ne contrer-passe jamais une autre correction.'" />
                @endif

                {{-- Une écriture plus ancienne que la liste : le champ libre qui la nomme. Il l'emporte sur
                     la liste quand il porte quelque chose (le serveur le dit ainsi). --}}
                <x-admin.field name="reverses_transaction_id_manual" label="…ou une écriture plus ancienne (identifiant)" maxlength="36"
                               placeholder="00000000-0000-0000-0000-000000000000" class="font-mono"
                               hint="L'identifiant saisi ici l'emporte sur la liste. La transaction doit exister, appartenir à ce projet, et ne pas être elle-même une correction." />
            </div>

            @php
                $kindOptions = [];
                foreach ($accountKinds as $kind => $label) {
                    $kindOptions[$kind] = $kind.' — '.$label;
                }
            @endphp
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <fieldset class="space-y-2">
                    <legend class="text-sm uppercase tracking-wider font-semibold text-slate-600 mb-1">Compte débité (source)</legend>
                    <x-admin.field name="source_kind" type="select" label="Type de compte débité" required :options="$kindOptions" />
                    <x-admin.field name="source_reference" label="Référence du compte débité" optional maxlength="200"
                                   hint="Prestataire, partie… ; vide pour un compte de projet." />
                </fieldset>

                <fieldset class="space-y-2">
                    <legend class="text-sm uppercase tracking-wider font-semibold text-slate-600 mb-1">Compte crédité (destination)</legend>
                    <x-admin.field name="destination_kind" type="select" label="Type de compte crédité" required :options="$kindOptions" />
                    <x-admin.field name="destination_reference" label="Référence du compte crédité" optional maxlength="200"
                                   hint="Prestataire, partie… ; vide pour un compte de projet." />
                </fieldset>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <x-admin.field name="amount_minor" type="number" label="Montant (unités mineures)" required min="1" step="1" class="tabular-nums"
                               hint="Le montant est toujours saisi en unités mineures : 100 XAF = 100 F CFA, 100 USD = 1,00 $." />
                <x-admin.field name="currency" type="select" label="Devise" required :options="array_combine($currencies, $currencies)" />
            </div>

            <x-admin.field name="reason" type="textarea" label="Motif" required :rows="3" minlength="5" maxlength="1000"
                           hint="Le motif est obligatoire : il est ce qui reste de la décision quand on la relit dans six mois." />

            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Proposer la correction
            </button>
        </form>
    @endif

    <p><a href="{{ route('admin.ledger-adjustments.index') }}" class="text-sm text-indigo-700 hover:text-indigo-900">← Toutes les corrections</a></p>
</div>
@endsection
