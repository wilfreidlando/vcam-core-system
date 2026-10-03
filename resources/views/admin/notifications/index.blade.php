@extends('layouts.admin')

@section('title', 'Notifications')
@section('subtitle', $project->name)

@section('content')
<div class="mb-4 flex justify-end">
    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-base font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">← Retour au projet</a>
</div>

<form method="GET" action="{{ route('admin.projects.notifications.index', ['projectId' => $project->id]) }}" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Statut</label>
            <select name="status" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base bg-white">
                <option value="">(tous)</option>
                @foreach ($statuses as $s)
                    <option value="{{ $s }}" @selected($filters['status'] === $s)>{{ ucfirst($s) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Environnement</label>
            <select name="environment" class="block w-full rounded-md border border-slate-300 px-3 py-2 text-base bg-white">
                <option value="">(tous)</option>
                <option value="live" @selected($filters['environment'] === 'live')>Live</option>
                <option value="sandbox" @selected($filters['environment'] === 'sandbox')>Sandbox</option>
            </select>
        </div>
        <div class="flex items-end gap-2 justify-end">
            <a href="{{ route('admin.projects.notifications.index', ['projectId' => $project->id]) }}" class="text-sm text-slate-500 hover:text-slate-700">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    :headers="['Créée le', 'ID', 'Env.', 'Canal', 'Type', 'Destinataire', 'Statut', 'Provider', '']"
    :empty="'Aucune notification ne correspond aux filtres.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($notifications as $notification)
        <tr>
            <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap tabular-nums">
                {{ $notification->createdAt !== null ? \Illuminate\Support\Carbon::parse($notification->createdAt)->format('d/m/Y H:i') : '—' }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700" title="{{ $notification->id }}">{{ substr($notification->id, 0, 8) }}…</td>
            <td class="px-4 py-2"><x-admin.env-badge :env="$notification->environment" /></td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->channel }}</td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->contentType }}</td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $notification->toPartyRef }}">{{ $notification->toPartyRef ?? '—' }}</td>
            <td class="px-4 py-2">
                <x-admin.status-badge :status="$notification->status" />
                @if ($notification->errorMessage !== null)
                    <p class="mt-0.5 text-xs text-rose-600 truncate max-w-[14rem]" title="{{ $notification->errorMessage }}">{{ $notification->errorMessage }}</p>
                @endif
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->providerCode ?? '—' }}</td>
            <td class="px-4 py-2 text-sm text-right">
                <a href="{{ route('admin.projects.notifications.show', ['projectId' => $project->id, 'notificationId' => $notification->id]) }}" class="font-medium text-indigo-600 hover:text-indigo-800 whitespace-nowrap">Détail →</a>
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
