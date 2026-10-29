@extends('layouts.admin')

@section('title', 'Livraisons de webhooks')

@section('content')

{{-- Les erreurs de `replay()` (id invalide / introuvable / pas en échec) sont rendues par le bloc
     d'erreurs global du layout (`role="alert"`) : pas de bandeau local en double. --}}

<div class="mb-4 flex items-start justify-between gap-4 rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3 text-sm text-indigo-900">
    <p>
        Historique complet des livraisons — pas seulement les échecs. Utile pour
        diagnostiquer : confirmez qu'un événement a bien été tenté, et avec
        quel résultat exact, plutôt que de deviner depuis la seule file
        d'échecs.
    </p>
    <a href="{{ route('admin.webhook-events.index') }}" class="shrink-0 font-medium text-indigo-700 hover:text-indigo-900 whitespace-nowrap">
        Types d'événements →
    </a>
</div>

<form method="GET" action="{{ route('admin.webhook-deliveries.dead.web') }}" aria-label="Filtrer les livraisons" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    @if (($filters['project_id'] ?? '') !== '')
        <input type="hidden" name="project_id" value="{{ $filters['project_id'] }}" />
    @endif
    <div class="flex items-end gap-3">
        <x-admin.field name="status" type="select" label="Statut" :value="$filters['status'] ?? ''"
                       :options="['' => 'Tous', 'pending' => 'En attente', 'delivered' => 'Livré', 'dead' => 'En échec (mort)']" />
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="text-sm text-slate-500 hover:text-slate-700">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    caption="Livraisons de webhooks"
    :headers="['', 'ID', 'URL du endpoint', 'Projet', 'Statut', 'Type d\'événement', 'Tentatives', 'Dernière erreur', 'Dernière tentative', 'Action']"
    :empty="'Aucune livraison ne correspond aux filtres.'">
    @foreach ($deliveries as $delivery)
        @php
            $envelope = json_encode(
                ['id' => $delivery->eventId, 'type' => $delivery->eventType, 'data' => $delivery->payload],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
            );
        @endphp
        {{-- A `<tbody>` per delivery, not a shared one around the whole
             `@foreach` — `x-data` only scopes to descendants, and the
             summary `<tr>` and the detail `<tr>` below it are siblings,
             not parent/child; putting `x-data` on the summary row alone
             left the detail row's `x-show="showDetails"` unable to see
             it, so Alpine silently failed and rendered every row
             permanently expanded (review finding, live-tested). Multiple
             `<tbody>` per `<table>` is valid HTML — this is the standard
             way to group rows that need to share client-side state. --}}
        <tbody class="divide-y divide-slate-100 bg-white" x-data="{ showDetails: false }">
        <tr>
            <td class="px-2 py-2">
                {{-- Review finding: the ID text alone (same styling as
                     every other non-interactive cell) gave no visual hint
                     it was clickable. An explicit button with a rotating
                     chevron is the standard "expand this row" affordance. --}}
                <button
                    type="button"
                    @click="showDetails = !showDetails"
                    class="inline-flex items-center justify-center h-8 w-8 rounded-md text-slate-600 hover:bg-slate-100 hover:text-slate-800"
                    aria-expanded="false"
                    :aria-expanded="showDetails ? 'true' : 'false'"
                    aria-controls="delivery-detail-{{ $delivery->id }}"
                    aria-label="Voir le corps envoyé et la réponse reçue de la livraison {{ substr($delivery->id, 0, 8) }}"
                    title="Voir le corps envoyé et la réponse reçue">
                    <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-4 w-4 transition-transform" :class="{ 'rotate-90': showDetails }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </td>
            <td class="px-4 py-2 text-sm font-mono truncate max-w-[8rem]" title="{{ $delivery->id }}">
                <a href="{{ route('admin.webhook-deliveries.show', ['delivery' => $delivery->id]) }}" class="text-indigo-600 hover:text-indigo-800">{{ substr($delivery->id, 0, 8) }}…</a>
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[16rem]" title="{{ $endpointUrls[$delivery->endpointId] ?? $delivery->endpointId }}">
                {{ $endpointUrls[$delivery->endpointId] ?? substr($delivery->endpointId, 0, 8).'…' }}
            </td>
            <td class="px-4 py-2 text-base text-slate-900 truncate max-w-[10rem]" title="{{ $delivery->projectId }}">
                {{ $projectNames[$delivery->projectId] ?? substr($delivery->projectId, 0, 8).'…' }}
            </td>
            <td class="px-4 py-2 text-sm">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-rose-100 text-rose-700' => $delivery->status === 'dead',
                    'bg-emerald-100 text-emerald-700' => $delivery->status === 'delivered',
                    'bg-slate-100 text-slate-700' => $delivery->status === 'pending',
                ])>
                    {{ $delivery->status }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-slate-700">
                    {{ $delivery->eventType }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm text-center">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-rose-100 text-rose-700' => $delivery->attempts >= 8,
                    'bg-amber-100 text-amber-700' => $delivery->attempts < 8,
                ])>
                    {{ $delivery->attempts }}
                </span>
            </td>
            <td class="px-4 py-2 text-sm text-rose-700 max-w-xs truncate" title="{{ $delivery->lastError ?? '' }}">
                {{ $delivery->lastError ?? '—' }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-600 whitespace-nowrap">
                {{ $delivery->lastAttemptAt?->format('Y-m-d H:i:s') ?? '—' }}
            </td>
            <td class="px-4 py-2 text-sm">
                @if ($delivery->status === 'dead')
                    {{-- #26 — rejouer crée une nouvelle livraison : confirmation en dialogue accessible. --}}
                    <x-admin.confirm-dialog
                        title="Rejouer cette livraison ?"
                        message="La ligne en échec d'origine est conservée telle quelle ; une nouvelle livraison sera créée et remise en file."
                        :action="route('admin.webhook-deliveries.replay.web', ['delivery' => $delivery->id])"
                        confirm="Rejouer"
                        variant="primary">
                        <x-slot:trigger>
                            <button type="button" class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2.5 py-1.5 font-medium text-indigo-700 hover:bg-indigo-100">
                                <svg xmlns="http://www.w3.org/2000/svg" aria-hidden="true" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                                Rejouer<span class="sr-only"> la livraison {{ substr($delivery->id, 0, 8) }}</span>
                            </button>
                        </x-slot:trigger>
                    </x-admin.confirm-dialog>
                @else
                    <span class="text-slate-500">—</span>
                @endif
            </td>
        </tr>
        <tr id="delivery-detail-{{ $delivery->id }}" x-show="showDetails" x-cloak>
            <td colspan="10" class="px-4 py-3 bg-slate-50 border-t border-slate-100">
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-1">Corps envoyé</p>
                        <pre class="text-xs font-mono text-slate-800 bg-white border border-slate-200 rounded-md p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ $envelope }}</pre>
                    </div>
                    <div>
                        <p class="text-sm font-semibold text-slate-700 mb-1">
                            Réponse reçue
                            @if ($delivery->lastResponseStatus !== null)
                                <span class="ml-1 font-mono font-normal text-slate-600">HTTP {{ $delivery->lastResponseStatus }}</span>
                            @endif
                        </p>
                        @if ($delivery->lastResponseBody !== null && $delivery->lastResponseBody !== '')
                            <pre class="text-xs font-mono text-slate-800 bg-white border border-slate-200 rounded-md p-3 overflow-x-auto whitespace-pre-wrap break-all">{{ $delivery->lastResponseBody }}</pre>
                        @else
                            <p class="text-sm text-slate-500 italic">
                                {{ $delivery->lastResponseStatus === null ? "Aucune réponse reçue — la requête n'a jamais atteint l'endpoint." : '(corps de réponse vide)' }}
                            </p>
                        @endif
                    </div>
                </div>
            </td>
        </tr>
        </tbody>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
