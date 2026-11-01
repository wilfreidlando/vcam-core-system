@extends('layouts.admin')

@section('title', 'Notifications')
@section('subtitle', $project->name)

@section('content')
<div class="mb-4 flex justify-end">
    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="text-base font-medium text-indigo-700 hover:text-indigo-900 whitespace-nowrap">← Retour au projet</a>
</div>

<form method="GET" action="{{ route('admin.projects.notifications.index', ['projectId' => $project->id]) }}" aria-label="Filtrer les notifications" class="bg-white border border-slate-200 rounded-lg p-4 mb-4">
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 items-end">
        @php
            $statusOptions = ['' => '(tous)'];
            $statusLabels = [
                'pending' => 'En cours',
                'sent' => 'Envoyé',
                'delivered' => 'Livré',
                'read' => 'Lu',
                'failed' => 'Échec',
            ];
            foreach ($statuses as $s) {
                $statusOptions[$s] = $statusLabels[$s] ?? ucfirst($s);
            }
        @endphp
        <x-admin.field name="status" type="select" label="Statut" :value="$filters['status'] ?? ''" :options="$statusOptions" />
        <div class="flex items-end gap-2 justify-end">
            <a href="{{ route('admin.projects.notifications.index', ['projectId' => $project->id]) }}" class="text-sm text-slate-600 hover:text-slate-800">Réinitialiser</a>
            <button type="submit" class="inline-flex items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2 text-base font-medium text-white">Filtrer</button>
        </div>
    </div>
</form>

<x-admin.table
    caption="Notifications du projet"
    :headers="['Créée le', 'Identifiant', 'Canal', 'Type', 'Destinataire', 'Statut', 'Prestataire', '']"
    :empty="'Aucune notification ne correspond aux filtres.'">
    <tbody class="divide-y divide-slate-100 bg-white">
    @foreach ($notifications as $notification)
        <tr>
            <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap tabular-nums">
                {{ $notification->createdAt !== null ? \Illuminate\Support\Carbon::parse($notification->createdAt)->format('d/m/Y H:i') : '—' }}
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700" title="{{ $notification->id }}">{{ substr($notification->id, 0, 8) }}…</td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->channel }}</td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->contentType }}</td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700 truncate max-w-[10rem]" title="{{ $notification->toPartyRef }}">{{ $notification->toPartyRef ?? '—' }}</td>
            <td class="px-4 py-2">
                <x-admin.status-badge :status="$notification->status" />
                @if ($notification->errorMessage !== null)
                    <p class="mt-0.5 text-xs text-rose-700 truncate max-w-[14rem]" title="{{ $notification->errorMessage }}">{{ $notification->errorMessage }}</p>
                @endif
            </td>
            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $notification->providerCode ?? '—' }}</td>
            <td class="px-4 py-2 text-sm text-right">
                <a href="{{ route('admin.projects.notifications.show', ['projectId' => $project->id, 'notificationId' => $notification->id]) }}" class="font-medium text-indigo-700 hover:text-indigo-900 whitespace-nowrap">Détail →</a>
            </td>
        </tr>
    @endforeach
    </tbody>
</x-admin.table>

<x-admin.pagination :page="$page" :per-page="$perPage" :total="$total" />
@endsection
