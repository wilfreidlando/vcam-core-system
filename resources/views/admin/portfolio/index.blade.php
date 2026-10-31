@extends('layouts.admin')

@section('title', 'Portefeuille')

@section('content')
@php
    $money = function (array $figures): string {
        if ($figures === []) {
            return '—';
        }

        $zero = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];

        return collect($figures)->map(fn (int $minor, string $currency): string => in_array($currency, $zero, true)
            ? number_format($minor, 0, ',', ' ').' '.$currency
            : number_format($minor / 100, 2, ',', ' ').' '.$currency)->implode(' · ');
    };
@endphp
<div class="space-y-6">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <x-admin.stat label="Revenu récurrent mensuel" :text="$money($totals['mrr'])" hint="contrats actifs et impayés, au mois"/>
        <x-admin.stat label="Encaissé" :text="$money($totals['collected'])" hint="lu au grand livre" tone="emerald"/>
        <x-admin.stat label="Créances" :text="$money($totals['receivables'])" hint="ce qu'on vous doit" :tone="$totals['receivables'] === [] ? 'slate' : 'amber'"/>
        <x-admin.stat label="Clients actifs" :text="$totals['active'].' / '.$totals['customers']" :hint="$totals['pastDue'].' impayé(s)'" :tone="$totals['pastDue'] > 0 ? 'rose' : 'slate'"/>
    </div>

    <form method="GET" role="search" aria-label="Rechercher un projet" class="flex items-center gap-2">
        <x-admin.field name="q" label="Rechercher un projet" label-hidden :value="$search" placeholder="Rechercher un projet" wrapper-class="w-full max-w-xs" class="text-sm" />
        <button class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Rechercher</button>
    </form>

    <x-admin.table caption="Portefeuille par projet" :headers="['Projet', 'MRR', 'Tendance', 'Actifs', 'Essais', 'Impayés', 'Nouveaux / perdus', 'Encaissé', 'Créances', 'À surveiller']" empty="Aucun projet.">
        <tbody class="divide-y divide-slate-100 bg-white">
        @foreach ($rows as $row)
            @php $m = $row['metrics']; @endphp
            <tr>
                <td class="px-4 py-3 text-sm font-medium">
                    <a href="{{ route('admin.projects.show', $row['project']->id) }}" class="text-indigo-700 hover:text-indigo-900">{{ $row['project']->name }}</a>
                    @if ($row['project']->status !== 'active')
                        <span class="ml-1 rounded bg-slate-100 px-1.5 py-0.5 text-xs text-slate-600">{{ $row['project']->status }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 text-sm tabular-nums">{{ $money($m->mrrMinor) }}</td>
                <td class="px-4 py-3 text-sm">
                    @forelse ($row['trend'] as $currency => $series)
                        <x-admin.curve :label="'Revenu récurrent mensuel · '.$currency" :series="$series" field="mrrMinor" :currency="$currency" compact />
                    @empty
                        <span class="text-slate-500">—</span>
                    @endforelse
                </td>
                <td class="px-4 py-3 text-sm tabular-nums">{{ $m->activeCustomers }}</td>
                <td class="px-4 py-3 text-sm tabular-nums">{{ $m->trialing }}</td>
                <td @class(['px-4 py-3 text-sm tabular-nums', 'text-rose-700 font-semibold' => $m->pastDue > 0])>{{ $m->pastDue }}</td>
                <td class="px-4 py-3 text-sm tabular-nums">+{{ $m->newThisMonth }} / −{{ $m->lostThisMonth }}</td>
                <td class="px-4 py-3 text-sm tabular-nums">{{ $money($m->collectedMinor) }}</td>
                <td class="px-4 py-3 text-sm tabular-nums">{{ $money($m->receivablesMinor) }}</td>
                <td class="px-4 py-3 text-sm">
                    @forelse ($row['attention'] as $flag)
                        <span class="mr-1 inline-block rounded-md bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-800">{{ $flag }}</span>
                    @empty
                        <span class="text-slate-600">—</span>
                    @endforelse
                </td>
            </tr>
        @endforeach
        </tbody>
    </x-admin.table>

    @if ($truncated)
        <p class="text-sm text-slate-600">Seuls les 50 premiers projets sont affichés : affinez la recherche.</p>
    @endif

    @if (count($rows) > 0)
        <div class="space-y-3">
            <h2 class="text-base font-semibold text-slate-900">Créances par ancienneté</h2>
            <x-admin.table caption="Créances par ancienneté" :headers="['Projet', 'Devise', 'Non échues', '1–30 jours', '31–60 jours', '> 60 jours']" empty="Aucune créance ouverte.">
                <tbody class="divide-y divide-slate-100 bg-white">
                @foreach ($rows as $row)
                    @foreach ($row['metrics']->receivableAging as $currency => $b)
                        <tr>
                            <td class="px-4 py-2 text-sm">{{ $row['project']->name }}</td>
                            <td class="px-4 py-2 text-sm font-mono">{{ $currency }}</td>
                            @foreach (['current', 'd1_30', 'd31_60', 'd60_plus'] as $bucket)
                                <td @class(['px-4 py-2 text-sm tabular-nums', 'text-rose-700 font-semibold' => $bucket === 'd60_plus' && $b[$bucket] > 0])>{{ number_format($b[$bucket], 0, ',', ' ') }}</td>
                            @endforeach
                        </tr>
                    @endforeach
                @endforeach
                </tbody>
            </x-admin.table>
        </div>
    @endif
</div>
@endsection
