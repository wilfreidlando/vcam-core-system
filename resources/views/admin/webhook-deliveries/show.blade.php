@extends('layouts.admin')

@section('title', 'Livraison webhook')

{{-- ADR-0062 — une livraison et toutes ses tentatives. --}}
@section('content')
@php
    $field = 'text-sm uppercase tracking-wider font-semibold text-slate-500';
    $fmt = static fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i:s') : '—';
    $pretty = static function (?string $raw): string {
        if ($raw === null || $raw === '') {
            return '—';
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? (string) json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $raw;
    };
@endphp
<div class="space-y-6">
    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <x-admin.status-badge :status="$delivery->status" />
            <span class="font-mono text-sm text-slate-600">{{ $delivery->eventType }}</span>
            @if ($delivery->status === 'dead' && $canReplay && $delivery->endpointActive)
                <form method="POST" action="{{ route('admin.webhook-deliveries.replay.web', ['delivery' => $delivery->id]) }}" class="ml-auto">
                    @csrf
                    <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-sm font-medium text-white">Rejouer</button>
                </form>
            @endif
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4 text-base">
            <div class="md:col-span-2"><p class="{{ $field }}">ID livraison</p><p class="font-mono text-slate-700 break-all">{{ $delivery->id }}</p></div>
            <div><p class="{{ $field }}">Projet</p><p class="text-slate-800">{{ $projectNames[$delivery->projectId] ?? $delivery->projectId }}</p></div>
            <div class="md:col-span-2"><p class="{{ $field }}">ID événement (X-Event-Id)</p><p class="font-mono text-slate-700 break-all">{{ $delivery->eventId }}</p></div>
            <div><p class="{{ $field }}">Tentatives</p><p class="text-slate-800">{{ $delivery->attemptCount }}</p></div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Endpoint</p>
                <p class="font-mono text-sm text-slate-700 break-all">{{ $delivery->endpointUrl ?? $delivery->endpointId }}</p>
                @if ($delivery->endpointActive === false)
                    <p class="text-sm text-rose-600">Endpoint désactivé — aucune livraison ni rejeu possible.</p>
                @endif
            </div>
            <div><p class="{{ $field }}">Dernière tentative</p><p class="text-slate-800 tabular-nums">{{ $fmt($delivery->lastAttemptAt) }}</p></div>
            <div><p class="{{ $field }}">Livrée le</p><p class="text-slate-800 tabular-nums">{{ $fmt($delivery->deliveredAt) }}</p></div>
            <div><p class="{{ $field }}">Prochain essai</p><p class="text-slate-800 tabular-nums">{{ $fmt($delivery->nextRetryAt) }}</p></div>
            <div><p class="{{ $field }}">Rejeu de</p><p class="font-mono text-sm text-slate-700 break-all">{{ $delivery->replayedFromId ?? '—' }}</p></div>
            @if ($delivery->lastError !== null)
                <div class="md:col-span-3"><p class="{{ $field }}">Dernière erreur</p><p class="text-rose-700">{{ $delivery->lastError }}</p></div>
            @endif
        </div>
        <details class="mt-5">
            <summary class="cursor-pointer text-sm text-indigo-600">Payload de l'événement</summary>
            <pre class="mt-2 max-h-96 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100 whitespace-pre-wrap break-all">{{ json_encode($delivery->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </details>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-4">Tentatives</h2>
        @if ($delivery->attempts === [])
            <p class="text-slate-500 text-sm">Aucune tentative enregistrée (livraison antérieure à l'historique des tentatives, ou pas encore tentée).</p>
        @else
            <ol class="space-y-4">
                @foreach ($delivery->attempts as $attempt)
                    <li class="border border-slate-200 rounded-md p-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <span class="font-semibold text-slate-900">Tentative {{ $attempt->attempt }}</span>
                            <span class="font-mono text-xs text-slate-500">{{ $fmt($attempt->attemptedAt) }}</span>
                            <span @class([
                                'inline-flex rounded-md px-2 py-0.5 text-xs font-mono',
                                'bg-emerald-100 text-emerald-700' => $attempt->outcome === 'delivered',
                                'bg-rose-100 text-rose-700' => $attempt->outcome === 'dead',
                                'bg-amber-100 text-amber-700' => $attempt->outcome === 'retry_scheduled',
                            ])>{{ ['delivered' => 'livré', 'dead' => 'abandon (DLQ)', 'retry_scheduled' => 'nouvel essai programmé'][$attempt->outcome] ?? $attempt->outcome }}</span>
                            <span class="text-sm text-slate-700">{{ $attempt->responseStatus !== null ? 'HTTP '.$attempt->responseStatus : 'aucune réponse' }}</span>
                            <span class="text-sm text-slate-500">{{ $attempt->durationMs !== null ? $attempt->durationMs.' ms' : '' }}</span>
                        </div>
                        @if ($attempt->error !== null)
                            <p class="mt-1 text-sm text-rose-700">{{ $attempt->error }}</p>
                        @endif
                        <div class="mt-3 grid grid-cols-1 lg:grid-cols-2 gap-3">
                            <div>
                                <p class="text-xs font-semibold text-slate-500 mb-1">Requête envoyée — POST {{ $attempt->url }}</p>
                                <pre class="max-h-80 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100 whitespace-pre-wrap break-all">{{ json_encode($attempt->requestHeaders, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}

{{ $pretty($attempt->requestBody) }}</pre>
                            </div>
                            <div>
                                <p class="text-xs font-semibold text-slate-500 mb-1">Réponse du récepteur</p>
                                <pre class="max-h-80 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100 whitespace-pre-wrap break-all">{{ $pretty($attempt->responseBody) }}</pre>
                            </div>
                        </div>
                        @if ($attempt->correlationId !== null)
                            <p class="mt-2 text-xs text-slate-500">Correlation-Id : <span class="font-mono">{{ $attempt->correlationId }}</span></p>
                        @endif
                    </li>
                @endforeach
            </ol>
        @endif
    </div>

    <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="block text-sm text-indigo-600 hover:text-indigo-800">← Toutes les livraisons</a>
</div>
@endsection
