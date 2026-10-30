@extends('layouts.admin')

@section('title', "Journal d'audit")

@section('subtitle')
    {{ number_format((int) ($total ?? 0)) }} entrées
@endsection

@section('content')
<form method="GET" action="{{ route('admin.audit-log.index') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4" aria-label="Filtrer le journal d'audit">
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3 items-start">
        <x-admin.field name="resource" label="Ressource" :value="$filters['resource'] ?? ''" />
        <x-admin.field name="actor" label="Acteur" :value="$filters['actor'] ?? ''" hint="Identifiant brut, par ex. operator:…" />
        <x-admin.field name="action" label="Action" :value="$filters['action'] ?? ''" hint="Code brut, par ex. api_key.issued" />
        <x-admin.field name="from" type="date" label="Depuis le" :value="$filters['from'] ?? ''" />
        <x-admin.field name="to" type="date" label="Jusqu'au" :value="$filters['to'] ?? ''" />
    </div>
    <div class="mt-3 flex items-center justify-end gap-2">
        <a href="{{ route('admin.audit-log.index') }}" class="text-sm text-slate-600 hover:text-slate-800">Réinitialiser</a>
        <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
    </div>
</form>

<x-admin.table
    caption="Journal d'audit"
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
        <tr x-data="auditLog">
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $entry->occurredAt->format('Y-m-d H:i:s') }}
            </td>
            {{-- F-08 / V-01 — the actor is a label; the internal identifier stays in `title` and sr-only text. --}}
            <td class="px-4 py-2 text-sm text-slate-700 whitespace-nowrap">
                @include('admin.audit-log._actor', ['actor' => $actorLabels[$entry->actor] ?? new \App\Http\View\ActorLabel($entry->actor, $entry->actor)])
            </td>
            <td class="px-4 py-2 whitespace-nowrap">
                @include('admin.audit-log._action', ['action' => $entry->action])
            </td>
            <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap">
                @include('admin.audit-log._resource', ['resource' => $actorLabels[$entry->resource] ?? new \App\Http\View\ActorLabel($entry->resource, $entry->resource)])
            </td>
            <td class="px-4 py-2 text-sm text-slate-600 truncate max-w-[8rem] min-w-[6rem] font-mono">
                {{ $entry->correlationId ?? '' }}
            </td>
            <td class="px-4 py-2 text-sm align-top">
                @if ($hasDetail)
                    <button type="button" @click="toggle"
                            aria-controls="audit-detail-{{ $entry->id }}" aria-expanded="false" :aria-expanded="ariaExpanded"
                            class="inline-flex items-center gap-1 rounded px-2.5 py-1.5 text-indigo-700 hover:text-indigo-900 font-medium whitespace-nowrap">
                        <span x-text="label">Voir ▸</span><span class="sr-only"> le détail de l'entrée {{ $entry->id }}</span>
                    </button>
                    {{-- Kept inside this same <td> rather than a sibling
                         <tr> — Alpine's x-data scopes to an element and
                         its descendants only, so a second <tr> couldn't
                         share this row's `expanded` state without a
                         global store; nesting the detail here avoids that
                         entirely and stays valid table markup. --}}
                    <div id="audit-detail-{{ $entry->id }}" x-show="expanded" x-cloak class="mt-2 space-y-1.5 w-max max-w-sm">
                        @if (! empty($entry->before))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Avant</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->before, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                        @if (! empty($entry->after))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Après</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->after, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                        @if (! empty($entry->context))
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-slate-600">Contexte</p>
                                <pre class="text-xs font-mono bg-slate-50 border border-slate-200 rounded px-2 py-1.5 overflow-x-auto whitespace-pre-wrap break-all">{{ json_encode($entry->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                            </div>
                        @endif
                    </div>
                @else
                    <span class="text-slate-500">—</span>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
