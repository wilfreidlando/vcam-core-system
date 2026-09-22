@extends('layouts.admin')

@section('title', 'Livraisons de webhooks')

@section('content')

{{-- `withErrors()` from `replay()` ('id' — invalid/not found/not dead) has
     no standing form field to attach an inline @error() to on this
     table-only page, so without this banner those failures were silently
     dropped (same reasoning as reconciliation/new.blade.php). --}}
@if ($errors->any())
    <div class="mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
        @foreach ($errors->all() as $error)
            <p>{{ $error }}</p>
        @endforeach
    </div>
@endif

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

<form method="GET" action="{{ route('admin.webhook-deliveries.dead.web') }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    @if (($filters['project_id'] ?? '') !== '')
        <input type="hidden" name="project_id" value="{{ $filters['project_id'] }}" />
    @endif
    <div class="flex items-end gap-3">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
            <select name="status" class="block rounded-md border border-slate-300 px-3 py-2 text-base bg-white">
                <option value="" @selected(($filters['status'] ?? '') === '')>Tous</option>
                <option value="pending" @selected(($filters['status'] ?? '') === 'pending')>En attente</option>
                <option value="delivered" @selected(($filters['status'] ?? '') === 'delivered')>Livré</option>
                <option value="dead" @selected(($filters['status'] ?? '') === 'dead')>En échec (mort)</option>
            </select>
        </div>
        <div class="flex items-end gap-2">
            <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="text-sm text-slate-500 hover:text-slate-700">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
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
        <tbody class="divide-y divide-slate-100 bg-white" x-data="{ confirmReplay: false, showDetails: false }">
        <tr>
            <td class="px-2 py-2">
                {{-- Review finding: the ID text alone (same styling as
                     every other non-interactive cell) gave no visual hint
                     it was clickable. An explicit button with a rotating
                     chevron is the standard "expand this row" affordance. --}}
                <button
                    type="button"
                    @click="showDetails = !showDetails"
                    class="inline-flex items-center justify-center h-6 w-6 rounded-md text-slate-500 hover:bg-slate-100 hover:text-slate-700"
                    :aria-expanded="showDetails.toString()"
                    title="Voir le corps envoyé et la réponse reçue">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 transition-transform" :class="{ 'rotate-90': showDetails }" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                    </svg>
                </button>
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[8rem]" title="{{ $delivery->id }}">
                {{ substr($delivery->id, 0, 8) }}…
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
                    <button
                        @click="confirmReplay = true"
                        type="button"
                        class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-700 hover:bg-indigo-100">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                        Rejouer
                    </button>
                    <form
                        x-show="confirmReplay"
                        x-cloak
                        @submit="confirmReplay = false"
                        method="POST"
                        action="{{ route('admin.webhook-deliveries.replay.web', ['delivery' => $delivery->id]) }}"
                        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                        @click.self="confirmReplay = false">
                        @csrf
                        <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                            <p class="font-semibold text-slate-900 mb-2">Rejouer cette livraison ?</p>
                            <p class="text-sm text-slate-600 mb-4">
                                La ligne en échec d'origine est conservée telle
                                quelle ; une nouvelle livraison sera créée et
                                remise en file.
                            </p>
                            <div class="flex justify-end gap-2">
                                <button type="button" @click="confirmReplay = false"
                                        class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                <button type="submit"
                                        class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Confirmer</button>
                            </div>
                        </div>
                    </form>
                @else
                    <span class="text-slate-400">—</span>
                @endif
            </td>
        </tr>
        <tr x-show="showDetails" x-cloak>
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
                                <span class="ml-1 font-mono font-normal text-slate-500">HTTP {{ $delivery->lastResponseStatus }}</span>
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
