@extends('layouts.admin')

@section('title', 'Usage et quotas')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center gap-2 text-sm">
        @foreach ([['counters', null, 'Tous les compteurs'], ['counters', 'over80', 'Au-delà de 80 %'], ['counters', 'exceeded', 'Limite atteinte'], ['journal', null, 'Journal']] as [$v, $f, $label])
            <a href="{{ route('admin.projects.usage', array_filter(['projectId' => $projectId, 'view' => $v === 'journal' ? 'journal' : null, 'filter' => $f])) }}"
               @class(['rounded-md px-3 py-1.5 font-medium border', 'bg-slate-900 text-white border-slate-900' => $view === $v && $filter === $f, 'bg-white text-slate-700 border-slate-300 hover:bg-slate-50' => ! ($view === $v && $filter === $f)])>
                {{ $label }}
            </a>
        @endforeach
        <form method="GET" class="ml-auto flex items-center gap-2">
            <input type="hidden" name="view" value="{{ $view }}">
            <input name="customer" value="{{ $customer }}" placeholder="Référence client"
                   class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">
            <button class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">Filtrer</button>
        </form>
    </div>

    @if ($view === 'counters')
        <x-admin.table :headers="['Client', 'Compteur', 'Utilisé', 'Limite', '', 'Renouvellement']" empty="Aucun compteur pour ce filtre.">
            @foreach ($items as $row)
                <tr>
                    <td class="px-4 py-2 font-mono text-sm">{{ $row->customerRef }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->meter }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ number_format($row->used, 0, ',', ' ') }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $row->limit === null ? 'illimité' : number_format($row->limit, 0, ',', ' ') }}</td>
                    <td class="px-4 py-2 w-40">
                        @if ($row->limit !== null)
                            <div class="h-2 w-full rounded-full bg-slate-100">
                                <div @class(['h-2 rounded-full', 'bg-emerald-500' => $row->percent < 80, 'bg-amber-500' => $row->percent >= 80 && $row->percent < 100, 'bg-rose-500' => $row->percent >= 100])
                                     style="width: {{ $row->percent }}%"></div>
                            </div>
                        @endif
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ $row->windowEnd ? \Illuminate\Support\Carbon::parse($row->windowEnd)->format('d/m/Y') : '—' }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @else
        <x-admin.table :headers="['Enregistré', 'Client', 'Compteur', 'Quantité', 'Source', 'Clé']" empty="Aucune ligne dans le journal.">
            @foreach ($items as $row)
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-500">{{ \Illuminate\Support\Carbon::parse($row->recordedAt)->format('d/m/Y H:i') }}</td>
                    <td class="px-4 py-2 font-mono text-sm">{{ $row->customerRef ?? '(projet)' }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->meter }}</td>
                    <td @class(['px-4 py-2 text-sm tabular-nums', 'text-rose-700' => $row->quantity < 0])>{{ $row->quantity > 0 ? '+' : '' }}{{ $row->quantity }}</td>
                    <td class="px-4 py-2 text-sm">{{ $row->source }}</td>
                    <td class="px-4 py-2 font-mono text-xs text-slate-500 break-all">{{ $row->idempotencyKey }}</td>
                </tr>
            @endforeach
        </x-admin.table>
    @endif

    @if ($nextCursor)
        <div class="text-right">
            <a href="{{ route('admin.projects.usage', array_filter(['projectId' => $projectId, 'view' => $view === 'journal' ? 'journal' : null, 'filter' => $filter, 'customer' => $customer, 'cursor' => $nextCursor])) }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Page suivante →</a>
        </div>
    @endif
</div>
@endsection
