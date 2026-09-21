@extends('layouts.admin')

@section('title', 'Payments')
@section('subtitle', $selectedProjectName ?? '')

@section('content')
<form method="GET" action="{{ route('admin.payments.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Project</label>
            <select name="project_id" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm bg-white">
                <option value="">(select a project)</option>
                @foreach ($projects as $project)
                    <option value="{{ $project->id }}" @selected(($filters['project_id'] ?? '') === $project->id)>{{ $project->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Channel</label>
            <input type="text" name="channel" value="{{ $filters['channel'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Status</label>
            <select name="status" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm bg-white">
                <option value="">(any)</option>
                @foreach (['pending', 'processing', 'succeeded', 'failed', 'expired', 'refunded', 'canceled'] as $s)
                    <option value="{{ $s }}" @selected(($filters['status'] ?? '') === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div class="flex items-end gap-2 justify-end">
            <a href="{{ route('admin.payments.index') }}" class="text-xs text-slate-500 hover:text-slate-700">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Filter</button>
        </div>
    </div>
</form>

<x-admin.table
    :headers="['ID', 'Channel', 'Status', 'Amount', 'Currency', 'Actions']"
    :empty="'No payments match the filters.'">
    @foreach ($payments as $payment)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $payment->id }}">
                {{ substr($payment->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-700">
                {{ $payment->channel }}
            </td>
            <td class="px-4 py-2">
                @php $status = $payment->status; @endphp
                <span @class([
                    'inline-flex rounded-md px-2 py-0.5 font-mono text-xs',
                    'bg-emerald-100 text-emerald-700' => $status === 'succeeded',
                    'bg-rose-100 text-rose-700' => in_array($status, ['failed', 'expired'], true),
                    'bg-slate-100 text-slate-700' => in_array($status, ['pending', 'processing'], true),
                    'bg-amber-100 text-amber-700' => $status === 'refunded',
                    'bg-purple-100 text-purple-700' => $status === 'canceled',
                ])>
                    {{ $status }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-900 tabular-nums">
                {{-- Minor units (G5) → major units. The 2-decimal split is
                     correct for every currency this Core currently routes
                     payments in; a currency-decimals registry would be
                     needed to make this exact for every ISO 4217 currency,
                     out of scope for this read-only visibility pass. Same
                     pattern as reconciliation/new.blade.php. --}}
                {{ number_format($payment->amount / 100, 2) }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">
                {{ $payment->currency }}
            </td>
            <td class="px-4 py-2 text-sm">
                <a href="{{ route('admin.payments.show', ['payment' => $payment->id, 'project_id' => $filters['project_id'] ?? '']) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    View →
                </a>
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
