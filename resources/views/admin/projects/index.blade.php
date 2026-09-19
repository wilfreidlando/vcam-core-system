@extends('layouts.admin')

@section('title', 'Projects')

@section('content')
<form method="GET" action="{{ route('admin.projects.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="flex items-end gap-3">
        <div class="flex-1">
            <label class="block text-xs font-medium text-slate-700 mb-1">Organization ID</label>
            <input type="text" name="organization_id" value="{{ $filters['organization_id'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-1.5 text-sm font-mono" />
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.projects.index') }}" class="text-xs text-slate-500 hover:text-slate-700">Reset</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Filter</button>
        </div>
    </div>
</form>

<x-admin.table
    :headers="['ID', 'Organization', 'Name', 'Status', 'Action']"
    :empty="'No projects match the filter.'">
    @foreach ($projects as $project)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $project->id }}">
                {{ substr($project->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $project->organizationId }}">
                {{ substr($project->organizationId, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-sm text-slate-900">
                {{ $project->name }}
            </td>
            <td class="px-4 py-2">
                <span @class([
                    'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                    'bg-emerald-100 text-emerald-700' => $project->status === 'active',
                    'bg-slate-100 text-slate-700' => $project->status !== 'active',
                ])>
                    {{ $project->status }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm">
                <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    View →
                </a>
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
