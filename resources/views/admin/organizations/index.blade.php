@extends('layouts.admin')

@section('title', 'Organizations')

@section('content')
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
            <td class="px-4 py-2 text-sm">
                <a href="{{ route('admin.projects.index', ['organization_id' => $org->id]) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    View projects →
                </a>
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
