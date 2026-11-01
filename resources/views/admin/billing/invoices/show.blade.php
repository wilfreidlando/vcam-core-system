@extends('layouts.admin')

@section('title', 'Facture')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
           class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">← Toutes les factures</a>
        <span class="text-sm text-slate-500">Client : <span class="font-mono">{{ $invoice->customerRef }}</span></span>
    </div>

    {{-- Les erreurs de validation et de refus sont rendues une seule fois, par le layout (role="alert"). --}}

    <div class="rounded-lg border border-slate-200 bg-white p-5">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-sm text-slate-500">Numéro</p>
                <p class="text-xl font-semibold font-mono text-slate-900">{{ $invoice->number ?? 'brouillon' }}</p>
            </div>
            @php
                $labels = [
                    'draft' => 'Brouillon',
                    'open' => 'Ouverte',
                    'paid' => 'Payée',
                    'void' => 'Annulée',
                    'uncollectible' => 'Irrécouvrable',
                ];
                $classes = [
                    'draft' => 'bg-slate-100 text-slate-700',
                    'open' => 'bg-indigo-100 text-indigo-700',
                    'paid' => 'bg-emerald-100 text-emerald-700',
                    'void' => 'bg-slate-200 text-slate-700',
                    'uncollectible' => 'bg-rose-100 text-rose-700',
                ];
            @endphp
            <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$invoice->status] ?? 'bg-slate-100 text-slate-700' }}">
                {{ $labels[$invoice->status] ?? $invoice->status }}
            </span>
        </div>

        <dl class="mt-5 grid grid-cols-2 gap-x-6 gap-y-3 text-sm sm:grid-cols-4">
            <div>
                <dt class="text-slate-500">Émise le</dt>
                <dd class="text-slate-900 tabular-nums">{{ $invoice->issuedAt !== null ? \Illuminate\Support\Carbon::parse($invoice->issuedAt)->format('d/m/Y') : '—' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Échéance</dt>
                <dd class="text-slate-900 tabular-nums">{{ $invoice->dueAt !== null ? \Illuminate\Support\Carbon::parse($invoice->dueAt)->format('d/m/Y') : 'à réception' }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Sous-total</dt>
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->subtotalMinor, $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Taxe</dt>
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->taxMinor, $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Total</dt>
                <dd class="text-base font-semibold text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->totalMinor, $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Déjà réglé</dt>
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->amountPaidMinor, $invoice->currency)->groupedAmount() }} {{ $invoice->currency }}</dd>
            </div>
        </dl>

        @if ($invoice->voidReason !== null)
            <p class="mt-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Annulée — {{ $invoice->voidReason }}
            </p>
        @endif
    </div>

    <x-admin.table caption="Lignes de la facture" :headers="['Désignation', 'Qté', 'Prix unitaire', 'Montant', 'Taxe']" empty="Cette facture n'a aucune ligne.">
        @if (count($invoice->lines) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-700">{{ $line->description }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">{{ $line->quantity }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->unitAmountMinor, $invoice->currency)->groupedAmount() }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->amountMinor, $invoice->currency)->groupedAmount() }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->taxAmountMinor, $invoice->currency)->groupedAmount() }}
                        <span class="text-slate-500">({{ number_format($line->taxRateBps / 100, 2) }} %)</span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    {{-- L'historique d'encaissement — ADR-0073 §2, §4 --}}
    <x-admin.table caption="Encaissements de la facture" :headers="['Origine', 'Montant', 'Référence', 'Date', 'État']" empty="Cette facture n'a encore aucun encaissement.">
        @if (count($payments) > 0 || count($receipts) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($payments as $payment)
                @php
                    $labels = [
                        'pending' => 'En cours',
                        'succeeded' => 'Comptabilisé',
                        'failed' => 'Échec',
                        'canceled' => 'Annulé',
                    ];
                    $classes = [
                        'pending' => 'bg-amber-100 text-amber-700',
                        'succeeded' => 'bg-emerald-100 text-emerald-700',
                        'failed' => 'bg-rose-100 text-rose-700',
                        'canceled' => 'bg-slate-200 text-slate-700',
                    ];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-700">Paiement en ligne</td>
                    <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($payment->amountMinor, $payment->currency)->groupedAmount() }}
                        {{ $payment->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $payment->paymentId }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ $payment->settledAt !== null ? \Illuminate\Support\Carbon::parse($payment->settledAt)->format('d/m/Y') : '—' }}
                    </td>
                    <td class="px-4 py-2 text-sm whitespace-nowrap">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$payment->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$payment->status] ?? $payment->status }}
                        </span>
                    </td>
                </tr>
            @endforeach
            @foreach ($receipts as $receipt)
                @php
                    $labels = [
                        'pending' => 'En attente',
                        'approved' => 'Comptabilisé',
                        'rejected' => 'Refusé',
                    ];
                    $classes = [
                        'pending' => 'bg-amber-100 text-amber-700',
                        'approved' => 'bg-emerald-100 text-emerald-700',
                        'rejected' => 'bg-rose-100 text-rose-700',
                    ];
                    $methods = [
                        'bank_transfer' => 'Virement',
                        'cash' => 'Espèces',
                        'cheque' => 'Chèque',
                        'other' => 'Autre',
                    ];
                @endphp
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-700">Hors ligne — {{ $methods[$receipt->method] ?? $receipt->method }}</td>
                    <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($receipt->amountMinor, $receipt->currency)->groupedAmount() }}
                        {{ $receipt->currency }}
                    </td>
                    <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $receipt->reference }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Illuminate\Support\Carbon::parse($receipt->receivedAt)->format('d/m/Y') }}
                    </td>
                    <td class="px-4 py-2 text-sm whitespace-nowrap">
                        <span class="inline-flex rounded-md px-2 py-0.5 text-xs font-medium {{ $classes[$receipt->status] ?? 'bg-slate-100 text-slate-700' }}">
                            {{ $labels[$receipt->status] ?? $receipt->status }}
                        </span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @if ($invoice->status === 'open')
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <div class="flex items-center justify-between">
                <p class="font-semibold text-slate-900">Encaissement hors ligne</p>
                <a href="{{ route('admin.projects.receipts.index', $projectId) }}"
                   class="inline-block px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900">Tous les encaissements du projet</a>
            </div>
            <p class="mt-1 text-sm text-slate-600">
                L'encaissement hors ligne porte une méthode, une référence et un montant : il
                est inscrit dans les comptes du client. Au-dessus du seuil du projet, il
                attend une validation explicite avant de compter.
            </p>
            <form method="POST" action="{{ route('admin.projects.invoices.receipts.store', [$projectId, $invoice->id]) }}"
                  class="mt-3 grid gap-3 sm:grid-cols-2">
                @csrf
                <x-admin.field name="method" type="select" label="Méthode" required value="bank_transfer"
                               :options="['bank_transfer' => 'Virement', 'cash' => 'Espèces', 'cheque' => 'Chèque', 'other' => 'Autre']" />
                <x-admin.field name="reference" label="Référence" required maxlength="200" placeholder="Référence du virement" />
                <x-admin.field name="amount_minor" type="number" label="Montant (unités mineures)" required min="1" step="1" placeholder="50000" />
                <x-admin.field name="currency" label="Devise" required minlength="3" maxlength="3" :value="$invoice->currency" class="font-mono uppercase" />
                <x-admin.field name="received_at" type="datetime-local" label="Reçu le" required />
                <x-admin.field name="note" label="Note" maxlength="1000" optional />
                <div class="sm:col-span-2">
                    <button type="submit" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                        Enregistrer l'encaissement
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if ($invoice->status === 'draft')
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="font-semibold text-slate-900">Émettre la facture</p>
            <p class="mt-1 text-sm text-slate-600">
                L'émission attribue le numéro et inscrit la créance dans les comptes. La
                facture devient alors figée.
            </p>
            <div class="mt-3">
                <x-admin.confirm-dialog
                    title="Émettre cette facture ?"
                    message="L'émission attribue un numéro sans trou, inscrit la créance dans les comptes et fige le document. Elle est irréversible : seule l'annulation, elle aussi auditée, permet de revenir en arrière."
                    :action="route('admin.projects.invoices.issue.web', [$projectId, $invoice->id])"
                    confirm="Émettre la facture"
                    variant="primary">
                    <x-slot:trigger>
                        <button type="button" class="rounded-md bg-indigo-700 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Émettre</button>
                    </x-slot:trigger>
                </x-admin.confirm-dialog>
            </div>
        </div>
    @endif

    @if ($invoice->status === 'open')
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <p class="font-semibold text-slate-900">Annuler la facture</p>
                <p class="mt-1 text-sm text-slate-600">
                    L'annulation contre-passe l'écriture d'émission : la créance revient
                    dans les comptes. Elle est refusée si quelque chose a déjà été réglé.
                </p>
                <div class="mt-3">
                    <x-admin.confirm-dialog
                        title="Annuler cette facture ?"
                        message="L'écriture d'émission est contre-passée dans les comptes. Cette action est irréversible et journalisée."
                        :action="route('admin.projects.invoices.void.web', [$projectId, $invoice->id])"
                        confirm="Annuler la facture">
                        <x-slot:trigger>
                            <button type="button" class="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800">Annuler la facture</button>
                        </x-slot:trigger>
                        <x-admin.field name="reason" id="void-reason" label="Motif de l'annulation" required maxlength="500" />
                    </x-admin.confirm-dialog>
                </div>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <p class="font-semibold text-slate-900">Passer en irrécouvrable</p>
                <p class="mt-1 text-sm text-slate-600">
                    La créance quitte le compte du client et rejoint les créances
                    irrécouvrables. C'est un acte comptable, jamais un effet du temps.
                </p>
                <div class="mt-3">
                    <x-admin.confirm-dialog
                        title="Passer cette facture en irrécouvrable ?"
                        message="La créance quitte le compte du client et rejoint les créances irrécouvrables. C'est un acte comptable irréversible."
                        :action="route('admin.projects.invoices.uncollectible.web', [$projectId, $invoice->id])"
                        confirm="Passer en irrécouvrable">
                        <x-slot:trigger>
                            <button type="button" class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800">Passer en irrécouvrable</button>
                        </x-slot:trigger>
                        <x-admin.field name="reason" id="uncollectible-reason" label="Motif de l'écriture" required maxlength="500" />
                    </x-admin.confirm-dialog>
                </div>
            </div>
        </div>
    @endif
</div>
@endsection
