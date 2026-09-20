@extends('layouts.admin')

@section('title', 'Organizations')

@section('content')
{{-- `$has` from the layout's <aside> scope isn't visible here — Blade
     renders a child's @section content before the parent layout (and its
     @php block) runs, so a permission gate in a child view must resolve
     OperatorContext itself, same as the layout does. --}}
@if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('projects:write'))
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.organizations.new') }}"
           class="inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white shadow-sm">
            + New organization
        </a>
    </div>
@endif

<x-admin.table
    :headers="['ID', 'Name', 'Status', 'Action']"
    :empty="'No organizations yet.'">
    @foreach ($organizations as $org)
        <tr>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $org->id }}">
                {{ $org->id }}
            </td>
            <td class="px-4 py-2 text-sm text-slate-900">
                {{ $org->name }}
            </td>
            <td class="px-4 py-2">
                <span @class([
                    'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                    'bg-emerald-100 text-emerald-700' => $org->status === 'active',
                    'bg-slate-100 text-slate-700' => $org->status !== 'active',
                ])>
                    {{ $org->status }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm space-x-3 whitespace-nowrap">
                <a href="{{ route('admin.projects.index', ['organization_id' => $org->id]) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    View projects →
                </a>
                @if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('projects:write'))
                    <a href="{{ route('admin.projects.new', ['organization_id' => $org->id]) }}"
                       class="text-slate-500 hover:text-slate-700 font-medium">
                        + New project
                    </a>
                @endif
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
