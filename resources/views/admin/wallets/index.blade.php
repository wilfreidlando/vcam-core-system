@extends('layouts.admin')

@section('title', 'Wallets')
@section('subtitle', $project->name)

@section('content')
<div class="mb-4 flex items-center justify-between">
    <div class="rounded-md border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        Read-only — wallets are never mutated from the admin console
        (ADR-0033 §1). A wallet is credited only as an explicit,
        caller-supplied side effect of a payment's own initiation
        (ADR-0037), never manually.
    </div>
    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap ml-4">
        ← Back to project
    </a>
</div>

{{-- Stats — per-currency totals across this project's wallets (capped
     at 500 wallets for the aggregate, see WalletsController::summarize()). --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white border border-slate-200 rounded-lg p-4">
        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Total wallets</p>
        <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ $stats['total'] }}</p>
    </div>
    @forelse ($stats['byCurrency'] as $currency => $totals)
        <div class="bg-white border border-slate-200 rounded-lg p-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">{{ $currency }} available</p>
            <p class="mt-1 text-2xl font-semibold text-slate-900 tabular-nums">{{ number_format($totals['availableMinor'] / 100, 2) }}</p>
            <p class="mt-0.5 text-xs text-slate-500">{{ $totals['count'] }} wallet{{ $totals['count'] === 1 ? '' : 's' }} · {{ number_format($totals['heldMinor'] / 100, 2) }} held</p>
        </div>
    @empty
        <div class="bg-white border border-slate-200 rounded-lg p-4 sm:col-span-2 lg:col-span-3 flex items-center">
            <p class="text-sm text-slate-500">No wallets to summarize yet.</p>
        </div>
    @endforelse
</div>

<x-admin.table
    :headers="['Wallet ID', 'Party', 'Currency', 'Status', 'Available', 'Held']"
    :empty="'No wallets for this project yet.'">
    @foreach ($wallets as $wallet)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $wallet->id }}">
                {{ substr($wallet->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $wallet->partyId }}">
                {{ substr($wallet->partyId, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">
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
            {{-- Minor units -> major units assumes a 2-decimal currency
                 (true for every currency this Core currently routes
                 payments in); a currency-decimals registry would be
                 needed to make this exact for every ISO 4217 currency,
                 out of scope for this read-only visibility pass. --}}
            <td class="px-4 py-2 text-sm font-mono text-slate-900 tabular-nums text-right">
                {{ number_format($wallet->availableMinor / 100, 2) }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-500 tabular-nums text-right">
                {{ number_format($wallet->heldMinor / 100, 2) }}
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
