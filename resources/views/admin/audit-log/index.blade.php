@extends('layouts.admin')

@section('title', "Journal d'audit")

@section('subtitle')
    {{ number_format((int) ($total ?? 0)) }} entrées
@endsection

@section('content')
<form method="GET" action="{{ route('admin.audit-log.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-end">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Ressource</label>
            <input type="text" name="resource" value="{{ $filters['resource'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Acteur</label>
            <input type="text" name="actor" value="{{ $filters['actor'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Action</label>
            <input type="text" name="action" value="{{ $filters['action'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Depuis le</label>
            <input type="date" name="from" value="{{ $filters['from'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Jusqu'au</label>
            <input type="date" name="to" value="{{ $filters['to'] ?? '' }}" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base" />
        </div>
    </div>
    <div class="mt-3 flex items-center justify-end gap-2">
        <a href="{{ route('admin.audit-log.index') }}" class="text-sm text-slate-500 hover:text-slate-700">Réinitialiser</a>
        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
    </div>
</form>

<x-admin.table
    :headers="['Quand', 'Acteur', 'Action', 'Ressource', 'Corrélation', 'Détails']"
    :empty="'Aucune entrée d\'audit ne correspond aux filtres.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($entries as $entry)
        @php
            // Whether there is anything worth expanding — a row with
            // three null payloads (before/after/context) has no detail
            // to show, so its toggle is hidden entirely rather than
            // opening onto an empty box.
            $hasDetail = ! empty($entry->before) || ! empty($entry->after) || ! empty($entry->context);
        @endphp
        <tr x-data="{ expanded: false }">
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $entry->occurredAt->format('Y-m-d H:i:s') }}
            </td>
            <td class="px-4 py-2 text-sm text-slate-700 truncate max-w-[10rem]">
                {{ $entry->actor }}
            </td>
            <td class="px-4 py-2">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                    {{ $entry->action }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm text-slate-600 truncate max-w-[12rem] font-mono">
                {{ $entry->resource }}
            </td>
            <td class="px-4 py-2 text-sm text-slate-500 truncate max-w-[8rem] font-mono">
                {{ $entry->correlationId ?? '' }}
            </td>
            <td class="px-4 py-2 text-sm align-top">
                @if ($hasDetail)
                    <button type="button" @click="expanded = ! expanded" class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 font-medium whitespace-nowrap">
                        <span x-text="expanded ? 'Masquer ▾' : 'Voir ▸'"></span>
                    </button>
                    {{-- Kept inside this same <td> rather than a sibling
                         <tr> — Alpine's x-data scopes to an element and
                         its descendants only, so a second <tr> couldn't
                         share this row's `expanded` state without a
                         global store; nesting the detail here avoids that
                         entirely and stays valid table markup. --}}
                    <div x-show="expanded" x-cloak class="mt-2 space-y-1.5 w-max max-w-sm">
                        @if (! empty($entry->before))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Avant</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                        @if (! empty($entry->after))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Après</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                        @if (! empty($entry->context))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Contexte</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                    </div>
                @else
                    <span class="text-slate-400">—</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
