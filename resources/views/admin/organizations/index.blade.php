@extends('layouts.admin')

@section('title', 'Organizations')

@section('content')
<form method="GET" action="{{ route('admin.organizations.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="flex items-end gap-3">
        <div class="flex-1">
            <label class="block text-xs font-medium text-slate-700 mb-1">Organization ID</label>
            <input type="text" name="organization_id" value="{{ $filters['organization_id'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.organizations.index') }}" class="text-xs text-slate-500 hover:text-slate-700">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Filter</button>
        </div>
    </div>
</form>

<x-admin.table
    :headers="['ID', 'Name', 'Status', 'Projects', 'Action']"
    :empty="'No organizations match the filter.'">
    @foreach ($organizations as $org)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $org['id'] ?? '' }}">
                {{ $org['id'] ?? '' }}
            </td>
            <td class="px-4 py-2 text-sm text-slate-900">
                {{ $org['name'] ?? '' }}
            </td>
            <td class="px-4 py-2">
                <span @class([
                    'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                    'bg-emerald-100 text-emerald-700' => ($org['status'] ?? '') === 'active',
                    'bg-slate-100 text-slate-700' => ($org['status'] ?? '') !== 'active',
                ])>
                    {{ $org['status'] ?? '' }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs text-slate-700">
                {{-- Project counts would come from ListProjects with org filter.
                     Today the list endpoint doesn't aggregate counts, so this
                     column intentionally shows nothing until the contract is
                     extended. --}}
                —
            </td>
            <td class="px-4 py-2 text-sm">
                <a href="{{ route('admin.projects.index', ['organization_id' => $org['id'] ?? '']) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    View projects →
                </a>
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
