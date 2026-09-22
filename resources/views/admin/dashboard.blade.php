@extends('layouts.admin')

@section('title', 'Tableau de bord')

@section('content')
<div class="space-y-8">

    {{-- Platform row: tenancy footprint. --}}
    <div>
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-2">Plateforme</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            <x-admin.stat label="Organisations"
                :value="$stats['organizations'] ?? 0"
                hint="clients intégrés">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h2"/></svg>
                </x-slot:icon>
            </x-admin.stat>

            <x-admin.stat label="Projets"
                :value="$stats['projects'] ?? 0"
                hint="toutes organisations confondues">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                </x-slot:icon>
            </x-admin.stat>

            <x-admin.stat label="Opérateurs"
                :value="$stats['operators'] ?? 0"
                hint="utilisateurs de la console">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </x-slot:icon>
            </x-admin.stat>
        </div>
    </div>

    {{-- Money row: payment activity + health. --}}
    <div>
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-2">Argent</p>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">

            <x-admin.stat label="Paiements (24h)"
                :value="$stats['payments_24h']['total'] ?? 0"
                hint="toutes tentatives sur 24h">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
                </x-slot:icon>
            </x-admin.stat>

            <x-admin.stat label="Réussites (24h)"
                :value="$stats['payments_24h']['succeeded'] ?? 0"
                hint="finalisés sur 24h"
                tone="emerald">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </x-slot:icon>
            </x-admin.stat>

            <x-admin.stat label="Paiements bloqués"
                :value="$stats['stuck_payments'] ?? 0"
                hint="non finalisés depuis > 15 min"
                :tone="($stats['stuck_payments'] ?? 0) > 0 ? 'amber' : 'slate'">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                </x-slot:icon>
            </x-admin.stat>
        </div>
    </div>

    {{-- Delivery row: outbound webhook health. --}}
    <div>
        <p class="text-sm font-semibold uppercase tracking-wider text-slate-500 mb-2">Livraison</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">

            <x-admin.stat label="Webhooks en échec"
                :value="$stats['dead_webhooks'] ?? 0"
                hint="à rejouer"
                :tone="($stats['dead_webhooks'] ?? 0) > 0 ? 'rose' : 'slate'">
                <x-slot:icon>
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                </x-slot:icon>
            </x-admin.stat>
        </div>
    </div>

    {{-- Recent activity from the audit log --}}
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden shadow-sm">
        <div class="px-5 py-3.5 border-b border-slate-200 bg-slate-50/60 flex items-center justify-between">
            <h2 class="font-semibold text-slate-900">Activité récente</h2>
            <a href="{{ route('admin.audit-log.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">Tout le journal d'audit →</a>
        </div>

        <x-admin.table
            :headers="['Quand', 'Acteur', 'Action', 'Ressource']"
            :empty="'Aucune entrée d\'audit pour le moment.'">
            <tbody class="divide-y divide-slate-100 bg-white">
            @foreach ($recentAudit as $entry)
                <tr class="hover:bg-slate-50/60">
                    <td class="px-4 py-3 text-base text-slate-600 whitespace-nowrap font-mono">
                        {{ $entry->occurredAt->format('Y-m-d H:i:s') }}
                    </td>
                    <td class="px-4 py-3 text-base text-slate-700 truncate max-w-xs">
                        {{ $entry->actor }}
                    </td>
                    <td class="px-4 py-3 text-sm">
                        <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                            {{ $entry->action }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-base text-slate-500 truncate max-w-xs font-mono">
                        {{ $entry->resource }}
                    </td>
                </tr>
            @endforeach
            </tbody>
        </x-admin.table>
    </div>

    {{-- Quick actions --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">

        <a href="{{ route('admin.organizations.index') }}"
           class="flex items-start gap-4 rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <div class="rounded-md bg-indigo-100 p-2 text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h2"/></svg>
            </div>
            <div>
                <p class="text-lg font-medium text-slate-900">Organisations</p>
                <p class="text-sm text-slate-500">Voir les clients, en créer de nouveaux.</p>
            </div>
        </a>

        <a href="{{ route('admin.payments.index') }}"
           class="flex items-start gap-4 rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <div class="rounded-md bg-indigo-100 p-2 text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
            </div>
            <div>
                <p class="text-lg font-medium text-slate-900">Paiements</p>
                <p class="text-sm text-slate-500">Rechercher dans le registre des paiements.</p>
            </div>
        </a>

        <a href="{{ route('admin.webhook-deliveries.dead.web') }}"
           class="flex items-start gap-4 rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <div class="rounded-md bg-indigo-100 p-2 text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <p class="text-lg font-medium text-slate-900">Webhooks en échec</p>
                <p class="text-sm text-slate-500">Rejouer les livraisons échouées.</p>
            </div>
        </a>

    </div>
</div>
@endsection
