@extends('layouts.admin')

@section('title', \App\Http\View\LedgerAccountLabels::PAGE_TITLE)
@section('subtitle', $project->name)

@section('content')
@php
    $montant = fn (int $minor, string $currency): string => \Core\Support\Money\Money::ofMinor($minor, $currency)->groupedAmount();
@endphp
<div class="space-y-8">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="max-w-3xl rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-600">
            Les comptes du projet, tous : ceux qui ont servi et ceux qui n'ont encore jamais servi. Ils se créent
            tout seuls, quand l'activité en a besoin — jamais à la main. Un compte de portefeuille garde l'argent
            d'un client ; les autres comptes gardent l'argent du projet.
        </p>
        <a href="{{ route('admin.projects.ledger', ['projectId' => $projectId]) }}"
           class="text-sm font-medium text-indigo-700 hover:text-indigo-900 whitespace-nowrap">← {{ \App\Http\View\LedgerAccountLabels::BACK_TO_ACCOUNTING }}</a>
    </div>

    <section aria-labelledby="accounts">
        <h2 id="accounts" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ \App\Http\View\LedgerAccountLabels::ACCOUNTS_HEADING }}</h2>

        @include('admin.billing.partials.ledger-accounts', ['rows' => $rows, 'projectId' => $projectId, 'selected' => $selected])
    </section>

    @if (count($candidates) > 1 && $selected === null)
        <section aria-labelledby="devises">
            <h2 id="devises" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">{{ \App\Http\View\LedgerAccountLabels::MULTIPLE_CURRENCIES }}</h2>
            <ul class="space-y-1">
                @foreach ($candidates as $candidate)
                    <li>
                        <a href="{{ route('admin.projects.ledger.accounts', ['projectId' => $projectId, 'kind' => $candidate->kind, 'reference' => $candidate->reference, 'currency' => $candidate->currency]) }}"
                           class="text-sm font-medium text-indigo-700 hover:text-indigo-900">{{ $candidate->currency }}</a>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($selected !== null && $statement !== null)
        <section aria-labelledby="movements">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 id="movements" class="text-sm font-semibold uppercase tracking-wide text-slate-500">{{ \App\Http\View\LedgerAccountLabels::MOVEMENTS_HEADING }}</h2>
                <p class="rounded-lg border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
                    {{ \App\Http\View\LedgerAccountLabels::ACCOUNT_BALANCE }}<strong class="tabular-nums">{{ $montant($statement->closingBalanceMinor, $statement->currency) }} {{ $statement->currency }}</strong>
                </p>
            </div>

            <x-admin.table :caption="\App\Http\View\LedgerAccountLabels::MOVEMENTS_HEADING" :headers="\App\Http\View\LedgerAccountLabels::MOVEMENTS_HEADERS" :empty="\App\Http\View\LedgerAccountLabels::NO_MOVEMENT">
                @if (count($statement->lines) > 0)
                    <tbody class="divide-y divide-slate-100 bg-white">
                        @foreach ($statement->lines as $line)
                            <tr>
                                <td class="px-4 py-2 whitespace-nowrap font-mono text-xs text-slate-600">{{ $line->createdAt->format('d/m/Y H:i') }}</td>
                                <td class="px-4 py-2">
                                    <span class="rounded bg-slate-100 px-1.5 py-0.5 font-mono text-xs">{{ $line->transactionType }}</span>
                                    @if ($line->externalRef)<span class="ml-1 font-mono text-xs text-slate-500">{{ $line->externalRef }}</span>@endif
                                </td>
                                <td class="px-4 py-2">
                                    <span @class(['rounded px-1.5 py-0.5 text-xs font-medium', 'bg-rose-50 text-rose-700' => $line->direction === 'debit', 'bg-emerald-50 text-emerald-700' => $line->direction !== 'debit'])>{{ $line->direction === 'debit' ? 'débit' : 'crédit' }}</span>
                                </td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ $montant($line->amountMinor, $statement->currency) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums font-medium">{{ $montant($line->balanceAfterMinor, $statement->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                @endif
            </x-admin.table>

            <div class="mt-3">
                @include('admin.billing.partials.cursor-pager', ['routeName' => 'admin.projects.ledger.accounts', 'routeParams' => array_filter(['projectId' => $projectId, 'kind' => $selected->kind, 'reference' => $selected->reference, 'currency' => $selected->currency]), 'nextCursor' => $nextCursor, 'nextLabel' => 'Mouvements plus récents', 'previousLabel' => 'Mouvements plus anciens'])
            </div>
        </section>
    @endif
</div>
@endsection
