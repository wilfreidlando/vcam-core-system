@extends('layouts.admin')

@section('title', 'Audit log')

@section('subtitle')
    {{ number_format((int) ($total ?? 0)) }} entries
@endsection

@section('content')
<form method="GET" action="{{ route('admin.audit-log.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Resource</label>
            <input type="text" name="resource" value="{{ $filters['resource'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Actor</label>
            <input type="text" name="actor" value="{{ $filters['actor'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">Action</label>
            <input type="text" name="action" value="{{ $filters['action'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">From</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
        </div>
        <div>
            <label class="block text-xs font-medium text-slate-700 mb-1">To</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm" />
        </div>
    </div>
    <div class="mt-3 flex items-center justify-end gap-2">
        <a href="{{ route('admin.audit-log.index') }}" class="text-xs text-slate-500 hover:text-slate-700">Reset</a>
        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Filter</button>
    </div>
</form>

<x-admin.table
    :headers="['When', 'Actor', 'Action', 'Resource', 'Correlation']"
    :empty="'No audit entries match the filters.'">
    @foreach ($entries as $entry)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-600 whitespace-nowrap">
                {{ $entry['occurred_at'] ?? '' }}
            </td>
            <td class="px-4 py-2 text-xs text-slate-700 truncate max-w-[10rem]">
                {{ $entry['actor'] ?? '' }}
            </td>
            <td class="px-4 py-2">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                    {{ $entry['action'] ?? '' }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs text-slate-600 truncate max-w-[12rem] font-mono">
                {{ $entry['resource'] ?? '' }}
            </td>
            <td class="px-4 py-2 text-xs text-slate-500 truncate max-w-[8rem] font-mono">
                {{ $entry['correlation_id'] ?? '' }}
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
