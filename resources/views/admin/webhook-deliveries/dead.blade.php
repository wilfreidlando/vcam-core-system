@extends('layouts.admin')

@section('title', 'Webhook DLQ')

@section('content')
<x-admin.table
    :headers="['ID', 'Endpoint', 'Project', 'Event type', 'Attempts', 'Last error', 'Last attempt', 'Action']"
    :empty="'No dead webhook deliveries. 🎉'">
    @foreach ($deliveries as $delivery)
        <tr x-data="{ confirmReplay: false }">
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $delivery->id }}">
                {{ substr($delivery->id, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $delivery->endpointId }}">
                {{ substr($delivery->endpointId, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $delivery->projectId }}">
                {{ substr($delivery->projectId, 0, 8) }}…
            </td>
            <td class="px-4 py-2 text-xs">
                <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-slate-700">
                    {{ $delivery->eventType }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs text-center">
                <span @class([
                    'inline-flex rounded-full px-2 py-0.5 font-mono',
                    'bg-rose-100 text-rose-700' => $delivery->attempts >= 8,
                    'bg-amber-100 text-amber-700' => $delivery->attempts < 8,
                ])>
                    {{ $delivery->attempts }}
                </span>
            </td>
            <td class="px-4 py-2 text-xs text-rose-700 max-w-xs truncate" title="{{ $delivery->lastError ?? '' }}">
                {{ $delivery->lastError ?? '' }}
            </td>
            <td class="px-4 py-2 text-xs font-mono text-slate-600 whitespace-nowrap">
                {{ $delivery->lastAttemptAt?->format('Y-m-d H:i:s') ?? '' }}
            </td>
            <td class="px-4 py-2 text-xs">
                <button
                    @click="confirmReplay = true"
                    type="button"
                    class="inline-flex items-center gap-1 rounded-md bg-indigo-50 px-2 py-1 font-medium text-indigo-700 hover:bg-indigo-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                    Replay
                </button>
                <form
                    x-show="confirmReplay"
                    x-cloak
                    @submit="confirmReplay = false"
                    method="POST"
                    action="{{ route('admin.webhook-deliveries.replay', ['delivery' => $delivery->id]) }}"
                    class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50"
                    @click.self="confirmReplay = false">
                    @csrf
                    <div class="bg-white rounded-lg shadow-xl p-6 max-w-sm mx-4" @click.stop>
                        <p class="font-semibold text-slate-900 mb-2">Replay this delivery?</p>
                        <p class="text-sm text-slate-600 mb-4">
                            The original dead row is kept intact; a new
                            delivery row will be created and re-queued.
                        </p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="confirmReplay = false"
                                    class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Cancel</button>
                            <button type="submit"
                                    class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Confirm</button>
                        </div>
                    </div>
                </form>
            </td>
        </tr>
    @endforeach
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
