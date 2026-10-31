@extends('layouts.admin')

@section('title', 'Usage et limites')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach ([['counters', null, 'Tous les compteurs'], ['counters', 'over80', 'Au-delà de 80 %'], ['counters', 'exceeded', 'Limite atteinte'], ['journal', null, 'Journal']] as [$v, $f, $label])
            <a href="{{ route('admin.projects.usage', array_filter(['projectId' => $projectId, 'view' => $v === 'journal' ? 'journal' : null, 'filter' => $f])) }}"
               @if ($view === $v && $filter === $f) aria-current="page" @endif
               @class(['rounded-md px-3 py-1.5 font-medium border', 'bg-slate-900 text-white border-slate-900' => $view === $v && $filter === $f, 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => ! ($view === $v && $filter === $f)])>
                {{ $label }}
            </a>
        @endforeach
        <form method="GET" class="ml-auto flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            @if ($filter)
                <input type="hidden" name="filter" value="{{ $filter }}">
            @endif
            <x-admin.field name="customer" label="Filtrer par référence client" label-hidden :value="$customer" placeholder="Référence client" autocomplete="off" class="font-mono" />
            <button type="submit" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Filtrer</button>
        </form>
    </div>

    @if ($view === 'counters')
        <x-admin.table caption="Compteurs d'usage par client" :headers="['Client', 'Compteur', 'Consommation', 'Renouvellement']" empty="Aucun compteur pour ce filtre.">
            @foreach ($items as $row)
                <tr>
                    <td class="px-4 py-2 font-mono text-sm">{{ $row->customerRef }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->meter }}</td>
                    <td class="px-4 py-2 w-72">
                        <x-admin.meter :label="'Compteur '.$row->meter.' — '.$row->customerRef" :used="(int) $row->used" :limit="$row->limit === null ? null : (int) $row->limit" :id="'meter-usage-'.$loop->index" />
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600">{{ $row->windowEnd ? \Illuminate\Support\Carbon::parse($row->windowEnd)->format('d/m/Y') : '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @else
        <x-admin.table caption="Journal d'usage" :headers="['Enregistré', 'Client', 'Compteur', 'Quantité', 'Source', 'Référence de la requête']" empty="Aucune ligne dans le journal.">
            @foreach ($items as $row)
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-600">{{ \Illuminate\Support\Carbon::parse($row->recordedAt)->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-2 font-mono text-sm">{{ $row->customerRef ?? '(projet)' }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->meter }}</td>
                    <td @class(['px-4 py-2 text-sm tabular-nums', 'text-rose-700' => $row->quantity < 0])>{{ $row->quantity > 0 ? '+' : '' }}{{ $row->quantity }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->source }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-slate-500 break-all">{{ $row->idempotencyKey }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif

    @include('admin.billing.partials.cursor-pager', ['routeName' => 'admin.projects.usage', 'routeParams' => array_filter(['projectId' => $projectId, 'view' => $view === 'journal' ? 'journal' : null, 'filter' => $filter, 'customer' => $customer]), 'nextCursor' => $nextCursor])
</div>
@endsection
