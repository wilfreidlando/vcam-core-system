@extends('layouts.admin')

@section('title', 'Indicateurs')

@section('content')
@php
    $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];
    $amount = fn (int $minor, string $currency): string => in_array($currency, $zero, true)
        ? number_format($minor, 0, ',', ' ').' '.$currency
        : number_format($minor / 100, 2, ',', ' ').' '.$currency;
    $money = fn (array $figures): string => $figures === [] ? '—' : collect($figures)->map(fn (int $minor, string $c): string => $amount($minor, $c))->implode(' · ');
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            {{ $project->name }} — @if ($mayReadMoney)
                chiffres du grand livre et des contrats, au jour le jour.
            @else
                état de branchement du projet.
            @endif
        </p>
        @if ($mayReadMoney)
            <div class="flex gap-2 text-sm">
                @foreach ($periods as $p)
                    <a href="{{ route('admin.projects.indicators', ['projectId' => $projectId, 'days' => $p]) }}"
                       @if ($days === $p) aria-current="page" @endif
                       @class(['rounded-md px-3 py-1.5 font-medium border', 'bg-slate-900 text-white border-slate-900' => $days === $p, 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => $days !== $p])>{{ $p }} jours</a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($metrics)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-admin.stat label="Revenu récurrent mensuel" :text="$money($metrics->mrrMinor)" hint="contrats actifs et impayés, au mois"/>
            <x-admin.stat label="Encaissé ce mois" :text="$money($metrics->collectedMinor)" hint="lu au grand livre" tone="emerald"/>
            <x-admin.stat label="Créances" :text="$money($metrics->receivablesMinor)" hint="ce qu'on vous doit" :tone="$metrics->receivablesMinor === [] ? 'slate' : 'amber'"/>
            <x-admin.stat label="Attrition du mois" :text="number_format($metrics->churnBps / 100, 1, ',', ' ').' %'" :hint="$metrics->lostThisMonth.' perdu(s), '.$metrics->newThisMonth.' nouveau(x)'" :tone="$metrics->churnBps > 500 ? 'rose' : 'slate'"/>
        </div>
    @endif

    @if ($mayReadMoney)
        <section aria-labelledby="curves">
            <h2 id="curves" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Courbes</h2>
            @if ($points === [])
                <p class="rounded-lg border border-dashed border-slate-300 bg-white p-6 text-sm text-slate-500">
                    Pas encore d'historique : les chiffres du jour sont enregistrés chaque nuit (<code>billing:snapshot-metrics</code>).
                </p>
            @else
                <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
                    @foreach ($currencies as $currency)
                        @php $series = collect($points)->where('currency', $currency)->values(); @endphp
                        @foreach ([['mrrMinor', 'Revenu récurrent mensuel'], ['collectedMinor', 'Encaissé du mois'], ['receivablesMinor', 'Créances']] as [$field, $label])
                            <x-admin.curve
                                :label="$label.(count($currencies) > 1 ? ' · '.$currency : '')"
                                :series="$series"
                                :field="$field"
                                :currency="$currency" />
                        @endforeach
                    @endforeach
                </div>
            @endif
        </section>
    @endif

    @if ($metrics)
        <section aria-labelledby="aging">
            <h2 id="aging" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Créances par ancienneté</h2>
            <x-admin.table caption="Créances ouvertes par ancienneté et par devise" :headers="['Devise', 'Non échues', '1 à 30 jours', '31 à 60 jours', 'Plus de 60 jours']" empty="Aucune facture ouverte.">
                @foreach ($metrics->receivableAging as $currency => $row)
                    <tr>
                        <td class="px-4 py-2 text-sm font-mono">{{ $currency }}</td>
                        <td class="px-4 py-2 text-sm tabular-nums">{{ $amount($row['current'], $currency) }}</td>
                        <td class="px-4 py-2 text-sm tabular-nums">{{ $amount($row['d1_30'], $currency) }}</td>
                        <td class="px-4 py-2 text-sm tabular-nums">{{ $amount($row['d31_60'], $currency) }}</td>
                        <td @class(['px-4 py-2 text-sm tabular-nums', 'text-rose-700 font-semibold' => $row['d60_plus'] > 0])>{{ $amount($row['d60_plus'], $currency) }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
            <p class="mt-2 text-sm text-slate-500">
                Le détail est dans <a class="text-indigo-700 hover:text-indigo-900" href="{{ route('admin.projects.invoices.index', ['projectId' => $projectId]) }}">les factures du projet</a> ;
                la relance d'un client passe par son portail ou par votre propre message (ADR-0098).
            </p>
        </section>
    @endif

    <section aria-labelledby="checklist">
        <h2 id="checklist" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Ce projet est-il prêt à encaisser ?</h2>

        @if ($blocked)
            <div role="alert" class="mb-3 rounded-lg border border-amber-300 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-900">Ce projet est actif, mais il n'est pas encore prêt à encaisser.</p>
                <ul class="mt-1 list-inside list-disc text-sm text-amber-800">
                    @foreach ($missing as $item)
                        <li>{{ $item['label'] }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
            @foreach ($checklist as $item)
                <li @class(['flex items-start gap-3 px-4 py-3', 'opacity-60' => ! $item['applicable']])>
                    <span @class(['mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700' => $item['done'], 'bg-amber-100 text-amber-700' => ! $item['done'] && $item['applicable'], 'bg-slate-100 text-slate-500' => ! $item['applicable']])
                          aria-hidden="true">{{ $item['done'] ? '✓' : ($item['applicable'] ? '!' : '–') }}</span>
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $item['label'] }} <span class="sr-only">{{ ! $item['applicable'] ? '(sans objet)' : ($item['done'] ? '(fait)' : '(à faire)') }}</span></p>
                        <p class="text-sm text-slate-500">{{ $item['hint'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</div>
@endsection
