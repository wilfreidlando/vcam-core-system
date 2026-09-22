@extends('layouts.admin')

@section('title', 'Projets')

@section('content')
{{-- `$has` from the layout's <aside> scope isn't visible here — Blade
     renders a child's @section content before the parent layout (and its
     @php block) runs, so a permission gate in a child view must resolve
     OperatorContext itself, same as the layout does. --}}
@if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('projects:write'))
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.projects.new') }}"
           class="inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
            + Nouveau projet
        </a>
    </div>
@endif

<form method="GET" action="{{ route('admin.projects.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="flex items-end gap-3">
        <div class="flex-1">
            <label class="block text-sm font-medium text-slate-700 mb-1">Rechercher par nom</label>
            <input type="text" name="search" value="{{ $search ?? '' }}" placeholder="Nom du projet…" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
        <div class="flex-1">
            <label class="block text-sm font-medium text-slate-700 mb-1">ID de l'organisation</label>
            <input type="text" name="organization_id" value="{{ $filters['organization_id'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base font-mono" />
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.projects.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    :headers="['ID', 'Organisation', 'Nom', 'Statut', 'Action']"
    :empty="'Aucun projet ne correspond au filtre.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($projects as $project)
        <tr>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $project->id }}">
                {{ substr($project->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-base text-slate-900 truncate max-w-[10rem]" title="{{ $project->organizationId }}">
                {{ $organizationNames[$project->organizationId] ?? substr($project->organizationId, 0, 8).'…' }}
            </td>
            <td class="px-4 py-2 text-base text-slate-900">
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
            <td class="px-4 py-2 text-base space-x-3 whitespace-nowrap">
                <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}"
                   class="text-indigo-600 hover:text-indigo-800 font-medium">
                    Voir →
                </a>
                @if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermission('projects:write'))
                    <a href="{{ route('admin.projects.edit', ['projectId' => $project->id]) }}"
                       class="text-slate-500 hover:text-slate-700 font-medium">
                        Modifier
                    </a>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
