@extends('layouts.admin')

@section('title', 'Wallets')
@section('subtitle', $project->name)

@section('content')
<div class="mb-4 flex items-center justify-between">
    <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-base text-slate-600">
        Lecture seule — les wallets ne peuvent jamais être modifiés depuis
        la console admin. Un wallet n'est crédité qu'en conséquence
        explicite de l'initiation d'un paiement par l'appelant, jamais
        manuellement.
    </div>
    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-base font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap ml-4">
        ← Retour au projet
    </a>
</div>

<div class="mb-4">
    <x-admin.search-box placeholder="Rechercher par ID de tiers ou devise…" :value="$search" />
</div>

{{-- Stats — per-currency totals across this project's wallets (capped
     at 500 wallets for the aggregate, see WalletsController::summarize()). --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-lg p-4">
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">Total wallets</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $stats['total'] }}</p>
    </div>
    @forelse ($stats['byCurrency'] as $currency => $totals)
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-sm font-semibold uppercase tracking-wider text-slate-500">{{ $currency }} disponible</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ \Core\Support\Money\Money::ofMinor($totals['availableMinor'], $currency)->formattedAmount() }}</p>
            <p class="mt-0.5 text-sm text-slate-500">{{ $totals['count'] }} wallet{{ $totals['count'] === 1 ? '' : 's' }} · {{ \Core\Support\Money\Money::ofMinor($totals['heldMinor'], $currency)->formattedAmount() }} bloqué</p>
        </div>
    @empty
        <div class="bg-white border border-slate-200 rounded-lg p-4 sm:col-span-2 lg:col-span-3 flex items-center">
            <p class="text-base text-slate-500">Aucun wallet à résumer pour le moment.</p>
        </div>
    @endforelse
</div>

<x-admin.table
    :headers="['ID wallet', 'Tiers', 'Devise', 'Statut', 'Disponible', 'Bloqué', '']"
    :empty="'Aucun wallet pour ce projet pour le moment.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($wallets as $wallet)
        <tr>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $wallet->id }}">
                {{ substr($wallet->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $wallet->partyId }}">
                {{ substr($wallet->partyId, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-base font-mono text-slate-700">
                {{ $wallet->currency }}
            </td>
            <td class="px-4 py-2">
                <span @class([
                    'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                    'bg-emerald-100 text-emerald-700' => $wallet->status === 'active',
                    'bg-slate-100 text-slate-700' => $wallet->status !== 'active',
                ])>
                    {{ $wallet->status }}
                </span>
            </td>
            {{-- Minor units -> major units, via Money::formattedAmount()
                 — currency-exponent-correct (0 decimals for XAF). --}}
            <td class="px-4 py-2 text-base font-mono text-slate-900 tabular-nums text-right">
                {{ \Core\Support\Money\Money::ofMinor($wallet->availableMinor, $wallet->currency)->formattedAmount() }}
            </td>
            <td class="px-4 py-2 text-base font-mono text-slate-500 tabular-nums text-right">
                {{ \Core\Support\Money\Money::ofMinor($wallet->heldMinor, $wallet->currency)->formattedAmount() }}
            </td>
            <td class="px-4 py-2 text-sm text-right">
                <a href="{{ route('admin.projects.wallets.ledger', ['projectId' => $project->id, 'walletId' => $wallet->id]) }}" class="font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">
                    Écritures →
                </a>
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
