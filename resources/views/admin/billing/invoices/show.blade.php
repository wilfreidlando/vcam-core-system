@extends('layouts.admin')

@section('title', 'Facture')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <a href="{{ route('admin.projects.invoices.index', $projectId) }}"
           class="text-sm font-medium text-slate-600 hover:text-slate-900">← Toutes les factures</a>
        <span class="text-sm text-slate-500">Client : <span class="font-mono">{{ $invoice->customerRef }}</span></span>
    </div>

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

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
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->subtotalMinor, $invoice->currency)->formattedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Taxe</dt>
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->taxMinor, $invoice->currency)->formattedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Total</dt>
                <dd class="text-base font-semibold text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->totalMinor, $invoice->currency)->formattedAmount() }} {{ $invoice->currency }}</dd>
            </div>
            <div>
                <dt class="text-slate-500">Déjà réglé</dt>
                <dd class="text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($invoice->amountPaidMinor, $invoice->currency)->formattedAmount() }} {{ $invoice->currency }}</dd>
            </div>
        </dl>

        @if ($invoice->voidReason !== null)
            <p class="mt-4 rounded-md bg-slate-50 px-3 py-2 text-sm text-slate-600">
                Annulée — {{ $invoice->voidReason }}
            </p>
        @endif
    </div>

    <x-admin.table :headers="['Désignation', 'Qté', 'Prix unitaire', 'Montant', 'Taxe']" empty="Cette facture n'a aucune ligne.">
        @if (count($invoice->lines) > 0)
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($invoice->lines as $line)
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-700">{{ $line->description }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums">{{ $line->quantity }}</td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->unitAmountMinor, $invoice->currency)->formattedAmount() }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-900 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->amountMinor, $invoice->currency)->formattedAmount() }}
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-700 tabular-nums whitespace-nowrap">
                        {{ \Core\Support\Money\Money::ofMinor($line->taxAmountMinor, $invoice->currency)->formattedAmount() }}
                        <span class="text-slate-400">({{ number_format($line->taxRateBps / 100, 2) }} %)</span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        @endif
    </x-admin.table>

    @if ($invoice->status === 'draft')
        <div class="rounded-lg border border-slate-200 bg-white p-5">
            <p class="font-semibold text-slate-900">Émettre la facture</p>
            <p class="mt-1 text-sm text-slate-600">
                L'émission attribue le numéro et inscrit la créance au grand livre. La
                facture devient alors figée.
            </p>
            <form method="POST" action="{{ route('admin.projects.invoices.issue.web', [$projectId, $invoice->id]) }}"
                  class="mt-3">
                @csrf
                <button type="submit" class="rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                    Émettre
                </button>
            </form>
        </div>
    @endif

    @if ($invoice->status === 'open')
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <p class="font-semibold text-slate-900">Annuler la facture</p>
                <p class="mt-1 text-sm text-slate-600">
                    L'annulation contre-passe l'écriture d'émission. Elle est refusée
                    si quelque chose a déjà été réglé.
                </p>
                <form method="POST" action="{{ route('admin.projects.invoices.void.web', [$projectId, $invoice->id]) }}"
                      class="mt-3 flex items-center gap-2">
                    @csrf
                    <input name="reason" required maxlength="500" placeholder="Motif de l'annulation"
                           class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="rounded-md bg-rose-700 px-3 py-2 text-sm font-semibold text-white hover:bg-rose-800">
                        Annuler
                    </button>
                </form>
            </div>

            <div class="rounded-lg border border-slate-200 bg-white p-5">
                <p class="font-semibold text-slate-900">Passer en irrécouvrable</p>
                <p class="mt-1 text-sm text-slate-600">
                    La créance quitte le compte du client et rejoint les créances
                    irrécouvrables. C'est un acte comptable, jamais un effet du temps.
                </p>
                <form method="POST" action="{{ route('admin.projects.invoices.uncollectible.web', [$projectId, $invoice->id]) }}"
                      class="mt-3 flex items-center gap-2">
                    @csrf
                    <input name="reason" required maxlength="500" placeholder="Motif de l'écriture"
                           class="flex-1 rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <button type="submit" class="rounded-md bg-amber-700 px-3 py-2 text-sm font-semibold text-white hover:bg-amber-800">
                        Passer en irrécouvrable
                    </button>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection
