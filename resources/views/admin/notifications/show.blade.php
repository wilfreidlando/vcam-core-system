@extends('layouts.admin')

@section('title', 'Notification')
@section('subtitle', $project->name)

@section('content')
@php
    $field = 'text-sm uppercase tracking-wider font-semibold text-slate-600';
    $fmt = static fn (?string $iso): string => $iso !== null ? \Illuminate\Support\Carbon::parse($iso)->format('d/m/Y H:i:s') : '—';
    $json = static fn (array $data): string => (string) json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<div class="space-y-6">
    {{-- ADR-0062 — toutes les données de la notification, sans exception. --}}
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <div class="flex flex-wrap items-center gap-3 mb-5">
            <x-admin.status-badge :status="$notification->status" :final="$notification->isFinal" />
            
            <span class="text-lg font-semibold text-slate-900">{{ $notification->channel }} · {{ $notification->contentType }}</span>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-x-6 gap-y-4 text-base">
            <div class="md:col-span-2">
                <p class="{{ $field }}">ID</p>
                <p class="font-mono text-slate-700 break-all">{{ $notification->id }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Projet</p>
                <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-indigo-700 hover:text-indigo-900 font-medium">{{ $project->name }}</a>
            </div>
            <div>
                <p class="{{ $field }}">Créée le</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($notification->createdAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Dernière mise à jour</p>
                <p class="text-slate-800 tabular-nums">{{ $fmt($notification->updatedAt) }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Destinataire (référence)</p>
                <p class="font-mono text-slate-700 break-all">{{ $notification->toPartyRef }}</p>
            </div>
            <div>
                <p class="{{ $field }}">Prestataire</p>
                <p class="font-mono text-slate-700">{{ $notification->providerCode ?? '—' }}</p>
                <p class="mt-0.5 text-sm text-slate-500">Le service qui a envoyé le message.</p>
            </div>
            <div class="md:col-span-2">
                <p class="{{ $field }}">Identifiant du message chez le prestataire</p>
                <p class="font-mono text-slate-700 break-all">{{ $notification->providerMessageId ?? '—' }}</p>
            </div>
            <div class="md:col-span-3">
                <p class="{{ $field }}">Référence de l'intégrateur</p>
                <p class="font-mono text-slate-700 break-all">{{ $notification->clientReference }}</p>
                <p class="mt-0.5 text-sm text-slate-500">La clé que l'intégrateur a envoyée dans l'en-tête <code class="font-mono">Idempotency-Key</code> : elle empêche qu'un même message soit envoyé deux fois.</p>
            </div>
            @if ($notification->errorMessage !== null)
                <div class="md:col-span-3">
                    <p class="{{ $field }}">Erreur</p>
                    <p class="text-rose-700">{{ $notification->errorMessage }}</p>
                </div>
            @endif
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Contenu envoyé</h2>
            @if ($notification->content === [])
                <p class="text-slate-600">Non conservé : les messages envoyés avant la mise en place de la traçabilité ne gardent pas leur contenu.</p>
            @else
                <pre class="bg-slate-50 rounded-md p-3 text-sm font-mono text-slate-800 overflow-x-auto whitespace-pre-wrap break-all">{{ $json($notification->content) }}</pre>
            @endif
        </div>
        <div class="bg-white border border-slate-200 rounded-lg p-6">
            <h2 class="text-lg font-semibold text-slate-900 mb-3">Variables</h2>
            @if ($notification->variables === [])
                <p class="text-slate-600">Aucune.</p>
            @else
                <pre class="bg-slate-50 rounded-md p-3 text-sm font-mono text-slate-800 overflow-x-auto whitespace-pre-wrap break-all">{{ $json($notification->variables) }}</pre>
            @endif
        </div>
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Tentatives d'envoi</h2>
        <x-admin.table caption="Tentatives d'envoi" :headers="['#', 'Date', 'Prestataire', 'Statut', 'Identifiant du message', 'Erreur']" :empty="'Aucune tentative enregistrée.'">
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($notification->attempts as $attempt)
                <tr>
                    <td class="px-4 py-2 text-sm tabular-nums">{{ $attempt['attempt'] }}</td>
                    <td class="px-4 py-2 text-sm tabular-nums whitespace-nowrap">{{ $fmt($attempt['created_at']) }}</td>
                    <td class="px-4 py-2 text-sm font-mono">{{ $attempt['provider_code'] }}</td>
                    <td class="px-4 py-2"><x-admin.status-badge :status="$attempt['status']" /></td>
                    <td class="px-4 py-2 text-sm font-mono break-all">{{ $attempt['provider_message_id'] ?? '—' }}</td>
                    <td class="px-4 py-2 text-sm text-rose-700">{{ $attempt['error'] ?? '' }}</td>
                </tr>
            @endforeach
            </tbody>
        </x-admin.table>
    </div>

    <div class="text-right">
        <a href="{{ route('admin.projects.notifications.index', ['projectId' => $project->id]) }}" class="text-sm text-indigo-700 hover:text-indigo-900 font-medium">← Retour aux notifications</a>
    </div>
</div>
@endsection
