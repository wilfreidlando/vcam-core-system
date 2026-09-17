@extends('layouts.admin')

@section('title', 'Dashboard')

@section('content')
<div class="space-y-6">

    {{-- Top stat cards: 24h payment activity + health indicators. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">

        <x-admin.stat label="Payments (24h)"
            :value="$stats['payments_24h']['total'] ?? 0"
            hint="all attempts in last 24h" />

        <x-admin.stat label="Successes (24h)"
            :value="$stats['payments_24h']['succeeded'] ?? 0"
            hint="settled in last 24h"
            tone="emerald" />

        <x-admin.stat label="Dead webhooks"
            :value="$stats['dead_webhooks'] ?? 0"
            hint="need replay"
            :tone="($stats['dead_webhooks'] ?? 0) > 0 ? 'rose' : 'slate'" />

        <x-admin.stat label="Stuck payments"
            :value="$stats['stuck_payments'] ?? 0"
            hint="non-terminal > 15 min"
            :tone="($stats['stuck_payments'] ?? 0) > 0 ? 'amber' : 'slate'" />
    </div>

    {{-- Recent activity from the audit log --}}
    <div class="bg-white border border-slate-200 rounded-lg overflow-hidden">
        <div class="px-5 py-3 border-b border-slate-200 flex items-center justify-between">
            <h2 class="font-semibold text-slate-900">Recent activity</h2>
            <a href="{{ route('admin.audit-log.index') }}" class="text-sm font-medium text-indigo-600 hover:text-indigo-800">All audit entries →</a>
        </div>

        <x-admin.table
            :headers="['When', 'Actor', 'Action', 'Resource']"
            :empty="'No audit entries yet.'">
            @foreach ($recentAudit as $entry)
                <tr>
                    <td class="px-4 py-2.5 text-sm text-slate-600 whitespace-nowrap font-mono">
                        {{ $entry['occurred_at'] ?? '' }}
                    </td>
                    <td class="px-4 py-2.5 text-sm text-slate-700 truncate max-w-xs">
                        {{ $entry['actor'] ?? '' }}
                    </td>
                    <td class="px-4 py-2.5 text-sm">
                        <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">
                            {{ $entry['action'] ?? '' }}
                        </span>
                    </td>
                    <td class="px-4 py-2.5 text-sm text-slate-500 truncate max-w-xs font-mono">
                        {{ $entry['resource'] ?? '' }}
                    </td>
                </tr>
            @endforeach
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
                <p class="font-medium text-slate-900">Organizations</p>
                <p class="text-xs text-slate-500">View tenants, create new ones.</p>
            </div>
        </a>

        <a href="{{ route('admin.payments.index') }}"
           class="flex items-start gap-4 rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <div class="rounded-md bg-indigo-100 p-2 text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
            </div>
            <div>
                <p class="font-medium text-slate-900">Payments</p>
                <p class="text-xs text-slate-500">Search the payment ledger.</p>
            </div>
        </a>

        <a href="{{ route('admin.webhook-deliveries.dead') }}"
           class="flex items-start gap-4 rounded-lg border border-slate-200 bg-white p-5 hover:border-indigo-300 hover:bg-indigo-50/30 transition-colors">
            <div class="rounded-md bg-indigo-100 p-2 text-indigo-700">
                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            </div>
            <div>
                <p class="font-medium text-slate-900">Webhook DLQ</p>
                <p class="text-xs text-slate-500">Replay failed deliveries.</p>
            </div>
        </a>

    </div>
</div>
@endsection
