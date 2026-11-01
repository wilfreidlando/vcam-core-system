@extends('layouts.admin')

@section('title', 'Écritures du portefeuille')
@section('subtitle', $project->name)

@section('content')
<div class="mb-4 flex items-start justify-between gap-4">
    <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-base text-slate-600">
        Historique en partie double de ce portefeuille — chaque ligne est une écriture
        immuable (débit ou crédit) sur son compte, jamais modifiée ni supprimée
        après coup. Une correction est toujours une nouvelle transaction
        compensatoire, jamais une modification d'une écriture existante.
    </div>
    <a href="{{ route('admin.projects.wallets.index', ['projectId' => $project->id]) }}" class="shrink-0 text-base font-medium text-indigo-700 hover:text-indigo-900 whitespace-nowrap">
        ← Retour aux portefeuilles
    </a>
</div>

<div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-lg p-4">
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-600">Portefeuille</p>
        <p class="mt-1 font-mono text-slate-900 break-all">{{ $walletId }}</p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-4">
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-600">Disponible</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
            {{ \Core\Support\Money\Money::ofMinor($balance->availableMinor, $balance->currency)->groupedAmount() }}
            <span class="text-base font-mono text-slate-600">{{ $balance->currency }}</span>
        </p>
    </div>
    <div class="bg-white border border-slate-200 rounded-lg p-4">
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-600">Bloqué</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">
            {{ \Core\Support\Money\Money::ofMinor($balance->heldMinor, $balance->currency)->groupedAmount() }}
            <span class="text-base font-mono text-slate-600">{{ $balance->currency }}</span>
        </p>
    </div>
</div>

<x-admin.table
    caption="Écritures du portefeuille"
    :headers="['Date', 'Type de transaction', 'Référence externe', 'Sens', 'Montant', 'Identifiant']"
    :empty="'Aucune écriture pour ce portefeuille pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($entries as $entry)
        <tr>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $entry->createdAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-sm">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-slate-700">
                    {{ $entry->transactionType }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $entry->externalRef ?? '' }}">
                {{ $entry->externalRef ?? '—' }}
            </td>
            <td class="px-4 py-2 text-sm">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-emerald-100 text-emerald-700' => $entry->direction === 'credit',
                    'bg-rose-100 text-rose-700' => $entry->direction === 'debit',
                ])>
                    {{ $entry->direction === 'credit' ? 'crédit' : 'débit' }}
                </span>
            </td>
            <td class="px-4 py-2 text-base font-mono text-slate-900 tabular-nums text-right">
                {{ $entry->direction === 'debit' ? '−' : '+' }}{{ \Core\Support\Money\Money::ofMinor($entry->amountMinor, $entry->currency)->groupedAmount() }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 truncate max-w-[8rem]" title="{{ $entry->transactionId }}">
                {{ substr($entry->transactionId, 0, 8) }}…
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
