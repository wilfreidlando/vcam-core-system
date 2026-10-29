@extends('layouts.admin')

@section('title', 'Indicateurs')

@section('content')
@php
    $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];
    $amount = fn (int $minor, string $currency): string => in_array($currency, $zero, true)
        ? number_format($minor, 0, ',', ' ').' '.$currency
        : number_format($minor / 100, 2, ',', ' ').' '.$currency;
    $money = fn (array $figures): string => $figures === [] ? '—' : collect($figures)->map(fn (int $minor, string $c): string => $amount($minor, $c))->implode(' · ');

    // A curve is a polyline in a 320×90 box; the whole series sits between 0 and its maximum.
    $curve = function (array $values): array {
        $n = count($values);
        $max = max(1, ...($values === [] ? [1] : $values));
        $coords = [];
        foreach ($values as $i => $v) {
            $x = $n === 1 ? 160 : round(8 + ($i / ($n - 1)) * 304, 1);
            $y = round(82 - ($v / $max) * 74, 1);
            $coords[] = $x.','.$y;
        }

        return [implode(' ', $coords), $max];
    };
@endphp
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">{{ $project->name }} — chiffres du grand livre et des contrats, au jour le jour.</p>
        <div class="flex gap-2 text-sm">
            @foreach ($periods as $p)
                <a href="{{ route('admin.projects.indicators', ['projectId' => $projectId, 'days' => $p]) }}"
                   @class(['rounded-md px-3 py-1.5 font-medium border', 'bg-slate-900 text-white border-slate-900' => $days === $p, 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => $days !== $p])>{{ $p }} jours</a>
            @endforeach
        </div>
    </div>

    @if ($metrics)
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
            <x-admin.stat label="Revenu récurrent mensuel" :value="$money($metrics->mrrMinor)" hint="contrats actifs et impayés, au mois"/>
            <x-admin.stat label="Encaissé ce mois" :value="$money($metrics->collectedMinor)" hint="lu au grand livre" tone="emerald"/>
            <x-admin.stat label="Créances" :value="$money($metrics->receivablesMinor)" hint="ce qu'on vous doit" :tone="$metrics->receivablesMinor === [] ? 'slate' : 'amber'"/>
            <x-admin.stat label="Attrition du mois" :value="number_format($metrics->churnBps / 100, 1, ',', ' ').' %'" :hint="$metrics->lostThisMonth.' perdu(s), '.$metrics->newThisMonth.' nouveau(x)'" :tone="$metrics->churnBps > 500 ? 'rose' : 'slate'"/>
        </div>
    @endif

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
                        @php
                            $values = $series->map(fn ($p) => $p->{$field})->all();
                            [$line, $max] = $curve($values);
                            $last = $values === [] ? 0 : end($values);
                        @endphp
                        <figure class="rounded-lg border border-slate-200 bg-white p-4">
                            <figcaption class="flex items-baseline justify-between text-sm">
                                <span class="font-medium text-slate-800">{{ $label }}{{ count($currencies) > 1 ? ' · '.$currency : '' }}</span>
                                <span class="tabular-nums text-slate-600">{{ $amount($last, $currency) }}</span>
                            </figcaption>
                            <svg viewBox="0 0 320 90" class="mt-2 h-24 w-full" role="img"
                                 aria-label="{{ $label }} sur {{ $series->count() }} jour(s), de {{ $amount($values[0] ?? 0, $currency) }} à {{ $amount($last, $currency) }}">
                                <line x1="8" y1="82" x2="312" y2="82" class="stroke-slate-200" stroke-width="1"/>
                                <polyline points="{{ $line }}" fill="none" class="stroke-indigo-600" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>
                            </svg>
                            <div class="mt-1 flex justify-between text-xs text-slate-400">
                                <span>{{ $series->first()?->day }}</span><span>max {{ $amount($max, $currency) }}</span><span>{{ $series->last()?->day }}</span>
                            </div>
                        </figure>
                    @endforeach
                @endforeach
            </div>
        @endif
    </section>

    @if ($metrics)
        <section aria-labelledby="aging">
            <h2 id="aging" class="mb-3 text-sm font-semibold uppercase tracking-wide text-slate-500">Créances par ancienneté</h2>
            <x-admin.table :headers="['Devise', 'Non échues', '1 à 30 jours', '31 à 60 jours', 'Plus de 60 jours']" empty="Aucune facture ouverte.">
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
        <ul class="divide-y divide-slate-100 rounded-lg border border-slate-200 bg-white">
            @foreach ($checklist as $item)
                <li class="flex items-start gap-3 px-4 py-3">
                    <span @class(['mt-0.5 inline-flex h-5 w-5 flex-none items-center justify-center rounded-full text-xs font-bold', 'bg-emerald-100 text-emerald-700' => $item['done'], 'bg-amber-100 text-amber-700' => ! $item['done']])
                          aria-hidden="true">{{ $item['done'] ? '✓' : '!' }}</span>
                    <div>
                        <p class="text-sm font-medium text-slate-900">{{ $item['label'] }} <span class="sr-only">{{ $item['done'] ? '(fait)' : '(à faire)' }}</span></p>
                        <p class="text-sm text-slate-500">{{ $item['hint'] }}</p>
                    </div>
                </li>
            @endforeach
        </ul>
    </section>
</div>
@endsection
