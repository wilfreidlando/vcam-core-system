@extends('layouts.admin')

@section('title', 'Grand livre')

@section('content')
@php
    $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];
    $fmt = fn (int $minor, string $currency): string => in_array($currency, $zero, true)
        ? number_format($minor, 0, ',', ' ')
        : number_format($minor / 100, 2, ',', ' ');
@endphp
<div class="space-y-8">
    <p class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
        Lecture seule. Chaque ligne est une écriture <strong>immuable</strong> en partie double : une correction est
        toujours une nouvelle transaction (<a class="text-indigo-700 hover:text-indigo-900" href="{{ route('admin.ledger-adjustments.index') }}">Corrections d'exploitation</a>),
        jamais la modification d'une écriture existante. Pour chaque devise, la somme des soldes nets doit être nulle.
    </p>

    <section aria-labelledby="balance">
        <h2 id="balance" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Balance de vérification</h2>

        @forelse ($currencies as $currency => $block)
            <div class="mb-6 overflow-hidden rounded-lg border border-slate-200 bg-white">
                <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3">
                    <h3 class="font-semibold text-slate-900">{{ $currency }}</h3>
                    @if (($block['net'] ?? 0) === 0)
                        <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">Équilibrée · somme nulle</span>
                    @else
                        <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800">Déséquilibre de {{ $fmt($block['net'], $currency) }} {{ $currency }}</span>
                    @endif
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                            <tr><th class="px-4 py-2">Compte</th><th class="px-4 py-2">Référence</th><th class="px-4 py-2 text-right">Débit</th><th class="px-4 py-2 text-right">Crédit</th><th class="px-4 py-2 text-right">Solde net (débit)</th></tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($block['accounts'] as $account)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs">{{ $account->kind }}</td>
                                    <td class="px-4 py-2 font-mono text-xs text-slate-500" title="{{ $account->reference }}">{{ \Illuminate\Support\Str::limit($account->reference, 24) ?: '—' }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($account->debitMinor, $currency) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ $fmt($account->creditMinor, $currency) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums font-medium">{{ $fmt($account->netDebitMinor(), $currency) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @empty
            <p class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">Aucune écriture pour ce projet.</p>
        @endforelse
    </section>

    <section aria-labelledby="transactions">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
            <h2 id="transactions" class="text-sm font-semibold uppercase tracking-wide text-slate-500">Transactions (les plus récentes d'abord)</h2>
            <form method="GET" class="flex items-center gap-2">
                <input name="type" value="{{ $type }}" placeholder="Type (ex. payment_collection)"
                       class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
                <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Filtrer</button>
            </form>
        </div>

        <div class="overflow-hidden rounded-lg border border-slate-200 bg-white">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr><th class="px-4 py-2">Date</th><th class="px-4 py-2">Type</th><th class="px-4 py-2">Référence</th><th class="px-4 py-2">Écritures</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @forelse ($transactions as $transaction)
                            <tr class="align-top">
                                <td class="px-4 py-2 whitespace-nowrap font-mono text-xs text-slate-600">{{ \Illuminate\Support\Carbon::parse($transaction->createdAt)->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2"><span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ $transaction->type }}</span>
                                    @if ($transaction->reversesTransactionId)<span class="ml-1 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800">contre-passation</span>@endif</td>
                                <td class="px-4 py-2 font-mono text-xs text-slate-500" title="{{ $transaction->externalRef }}">{{ \Illuminate\Support\Str::limit((string) $transaction->externalRef, 22) ?: '—' }}</td>
                                <td class="px-4 py-2">
                                    <ul class="space-y-0.5">
                                        @foreach ($transaction->lines as $line)
                                            <li class="flex items-center gap-2 text-xs">
                                                <span @class(['w-12 shrink-0 rounded px-1.5 py-0.5 text-center font-medium', 'bg-rose-50 text-rose-700' => $line->direction === 'debit', 'bg-emerald-50 text-emerald-700' => $line->direction !== 'debit'])>{{ $line->direction === 'debit' ? 'débit' : 'crédit' }}</span>
                                                <span class="font-mono">{{ $line->accountKind }}</span>
                                                <span class="text-slate-400" title="{{ $line->accountReference }}">{{ \Illuminate\Support\Str::limit($line->accountReference, 14) }}</span>
                                                <span class="ml-auto tabular-nums font-medium">{{ $fmt($line->amountMinor, $line->currency) }} {{ $line->currency }}</span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-4 py-6 text-center text-slate-500">Aucune transaction.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($nextCursor)
            <p class="mt-3 text-right">
                <a class="text-sm font-medium text-indigo-700 hover:text-indigo-900"
                   href="{{ route('admin.projects.ledger', array_filter(['projectId' => $projectId, 'type' => $type, 'cursor' => $nextCursor])) }}">Transactions plus anciennes →</a>
            </p>
        @endif
    </section>
</div>
@endsection
