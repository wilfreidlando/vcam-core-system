@extends('layouts.admin')

@section('title', "Nouvelle correction d'exploitation")

@section('content')
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">Quatre yeux, toujours</p>
        <p>
            La correction que vous proposez n'écrit rien au grand livre tant qu'une seconde
            personne ne l'a pas validée. Elle ne modifie aucune écriture existante : elle
            produit une nouvelle transaction équilibrée, à partir du seul montant et des
            seuls comptes que vous choisissez.
        </p>
        <p class="mt-2">
            ADR-0076 : une correction est une contre-passation — elle nomme la transaction
            qu'elle corrige. Choisissez d'abord le projet, puis la transaction à contrer-passer.
        </p>
    </div>

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

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
                           class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $project->name }}</a>
                        <span class="text-xs font-mono text-slate-400 ml-2">{{ $project->id }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @else
        <form method="POST" action="{{ route('admin.ledger-adjustments.store') }}" class="bg-white border border-slate-200 rounded-lg p-6 space-y-4">
            @csrf

            <input type="hidden" name="project_id" value="{{ $selectedProjectId }}">

            <div>
                <p class="text-sm uppercase tracking-wider font-semibold text-slate-500 mb-1">Projet</p>
                @php $selectedProject = collect($projects)->firstWhere('id', $selectedProjectId); @endphp
                <p class="text-base text-slate-900">
                    {{ $selectedProject !== null ? $selectedProject->name : $selectedProjectId }}
                </p>
                <a href="{{ route('admin.ledger-adjustments.new') }}" class="text-sm text-indigo-600 hover:text-indigo-800">Changer de projet</a>
            </div>

            <div>
                <label for="reverses_transaction_id" class="text-sm uppercase tracking-wider font-semibold text-slate-500 block mb-1">Transaction à contrer-passer</label>
                @if (count($transactions) === 0)
                    <p class="rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-800">
                        Ce projet n'a aucune écriture récente à contrer-passer : une correction se
                        rattache toujours à une transaction existante. Saisissez son identifiant
                        ci-dessous.
                    </p>
                @else
                    <select id="reverses_transaction_id" name="reverses_transaction_id"
                            class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                        <option value="">— Choisir la transaction corrigée —</option>
                        @foreach ($transactions as $transaction)
                            <option value="{{ $transaction->id }}" @selected(old('reverses_transaction_id') === $transaction->id)>
                                {{ \Illuminate\Support\Carbon::parse($transaction->createdAt)->format('d/m/Y H:i') }}
                                — {{ $transaction->type }}
                                @if (count($transaction->lines) > 0)
                                    — {{ \Core\Support\Money\Money::ofMinor($transaction->lines[0]->amountMinor, $transaction->lines[0]->currency)->groupedAmount() }} {{ $transaction->lines[0]->currency }}
                                @endif
                                — {{ substr($transaction->id, 0, 8) }}…
                            </option>
                        @endforeach
                    </select>
                    <p class="text-xs text-slate-500 mt-1">
                        Les {{ count($transactions) }} écritures les plus récentes du projet. Une
                        correction ne contrer-passe jamais une autre correction.
                    </p>
                @endif

                {{-- Une écriture plus ancienne que la liste : le champ
                     libre qui la nomme. Il l'emporte sur la liste
                     quand il porte quelque chose (le serveur le dit
                     ainsi), et c'est la seule façon d'atteindre une
                     écriture que les {{ count($transactions) }} plus
                     récentes ne proposent pas. --}}
                <label for="reverses_transaction_id_manual" class="text-sm uppercase tracking-wider font-semibold text-slate-500 block mt-4 mb-1">
                    …ou une écriture plus ancienne (identifiant)
                </label>
                <input type="text" id="reverses_transaction_id_manual" name="reverses_transaction_id_manual"
                       value="{{ old('reverses_transaction_id_manual') }}" maxlength="36"
                       placeholder="00000000-0000-0000-0000-000000000000"
                       class="w-full rounded-md border border-slate-300 px-3 py-2 text-base font-mono">
                <p class="text-xs text-slate-500 mt-1">
                    L'identifiant saisi ici l'emporte sur la liste. La transaction doit exister,
                    appartenir à ce projet, et ne pas être elle-même une correction.
                </p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <fieldset class="space-y-2">
                    <legend class="text-sm uppercase tracking-wider font-semibold text-slate-500">Compte débité (source)</legend>
                    <select name="source_kind" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                        @foreach ($accountKinds as $kind => $label)
                            <option value="{{ $kind }}" @selected(old('source_kind') === $kind)>{{ $kind }} — {{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="source_reference" value="{{ old('source_reference') }}" maxlength="200"
                           placeholder="Référence (prestataire, partie… ; vide pour un compte de projet)"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                </fieldset>

                <fieldset class="space-y-2">
                    <legend class="text-sm uppercase tracking-wider font-semibold text-slate-500">Compte crédité (destination)</legend>
                    <select name="destination_kind" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                        @foreach ($accountKinds as $kind => $label)
                            <option value="{{ $kind }}" @selected(old('destination_kind') === $kind)>{{ $kind }} — {{ $label }}</option>
                        @endforeach
                    </select>
                    <input type="text" name="destination_reference" value="{{ old('destination_reference') }}" maxlength="200"
                           placeholder="Référence (prestataire, partie… ; vide pour un compte de projet)"
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                </fieldset>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label for="amount_minor" class="text-sm uppercase tracking-wider font-semibold text-slate-500 block mb-1">Montant (unités mineures)</label>
                    <input type="number" id="amount_minor" name="amount_minor" value="{{ old('amount_minor') }}" min="1" step="1" required
                           class="w-full rounded-md border border-slate-300 px-3 py-2 text-base tabular-nums">
                    <p class="text-xs text-slate-500 mt-1">Le montant est toujours saisi en unités mineures : 100 XAF = 100 F CFA, 100 USD = 1,00 $.</p>
                </div>
                <div>
                    <label for="currency" class="text-sm uppercase tracking-wider font-semibold text-slate-500 block mb-1">Devise</label>
                    <select id="currency" name="currency" required class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">
                        @foreach ($currencies as $currency)
                            <option value="{{ $currency }}" @selected(old('currency') === $currency)>{{ $currency }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label for="reason" class="text-sm uppercase tracking-wider font-semibold text-slate-500 block mb-1">Motif</label>
                <textarea id="reason" name="reason" rows="3" minlength="5" maxlength="1000" required
                          class="w-full rounded-md border border-slate-300 px-3 py-2 text-base">{{ old('reason') }}</textarea>
                <p class="text-xs text-slate-500 mt-1">Le motif est obligatoire : il est ce qui reste de la décision quand on la relit dans six mois.</p>
            </div>

            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Proposer la correction
            </button>
        </form>
    @endif

    <p><a href="{{ route('admin.ledger-adjustments.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">← Toutes les corrections</a></p>
</div>
@endsection
