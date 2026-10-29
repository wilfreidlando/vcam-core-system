@extends('layouts.admin')

@section('title', 'Projets')

@section('content')
{{-- `$has` from the layout's <aside> scope isn't visible here — Blade
     renders a child's @section content before the parent layout (and its
     @php block) runs, so a permission gate in a child view must resolve
     OperatorContext itself, same as the layout does. --}}
@if(app(\Core\Identity\Contracts\OperatorContext::class)->hasPermissionAnywhere('projects:write'))
    <div class="mb-4 flex justify-end">
        <a href="{{ route('admin.projects.new') }}"
           class="inline-flex items-center gap-2 rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
            + Nouveau projet
        </a>
    </div>
@endif

{{-- B2 — chaque champ du filtre a un <label for> et une bordure visible. --}}
<form method="GET" action="{{ route('admin.projects.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4" role="search" aria-label="Filtrer les projets">
    <div class="flex flex-col sm:flex-row sm:items-end gap-3">
        <x-admin.field name="search" label="Rechercher par nom" :value="$search ?? ''" placeholder="Nom du projet…" autocomplete="off" wrapper-class="flex-1" />
        <x-admin.field name="organization_id" label="ID de l'organisation" :value="$filters['organization_id'] ?? ''" autocomplete="off" class="font-mono" wrapper-class="flex-1" />
        <div class="flex items-center gap-2">
            <a href="{{ route('admin.projects.index') }}" class="py-1.5 px-2.5 text-sm text-slate-600 hover:text-slate-800">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    caption="Projets"
    :headers="['ID', 'Organisation', 'Nom', 'Statut', 'Actions']"
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
            <td class="px-4 py-2 text-base whitespace-nowrap">
                <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}"
                   class="inline-block py-1.5 px-2.5 text-indigo-700 hover:text-indigo-900 font-medium">
                    Voir →
                    <span class="sr-only">{{ $project->name }}</span>
                </a>
                @if(app(\Core\Identity\Contracts\OperatorContext::class)->scopeFor('projects:write')->allowsProject($project->id))
                    <a href="{{ route('admin.projects.edit', ['projectId' => $project->id]) }}"
                       class="inline-block py-1.5 px-2.5 text-slate-600 hover:text-slate-800 font-medium">
                        Modifier
                        <span class="sr-only">{{ $project->name }}</span>
                    </a>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
