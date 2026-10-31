@extends('layouts.admin')

@section('title', 'Export des données')

@section('content')
<div class="space-y-6">
    <section class="rounded-lg border border-slate-200 bg-white p-6 space-y-3" aria-labelledby="export-request-title">
        <h2 id="export-request-title" class="text-base font-semibold text-slate-900">Demander un export</h2>
        <p class="text-sm text-slate-600">
            Une archive <code>.zip</code> de fichiers JSON Lines, un par type d'objet (clients, offres, abonnements, factures,
            paiements, notifications, usage, portefeuilles, comptabilité) au format de l'API publique, plus la balance du
            projet à la date de l'export. Elle est préparée en arrière-plan, chiffrée au repos, et supprimée au bout de
            7 jours. Les données personnelles d'un client effacé restent effacées.
        </p>
        <form method="POST" action="{{ route('admin.projects.exports.store', $projectId) }}">
            @csrf
            <button class="rounded-md bg-slate-900 px-4 py-2 text-sm font-medium text-white hover:bg-slate-700">
                Demander un export
            </button>
        </form>
    </section>

    <section class="space-y-3" aria-labelledby="export-history-title">
        <h2 id="export-history-title" class="text-base font-semibold text-slate-900">Exports précédents</h2>
        {{-- V-05 — the download-link note belongs to this table, announced as its description. --}}
        <p id="export-link-note" class="text-sm text-slate-600">Le lien de téléchargement est généré au clic et valable 15 minutes.</p>
        {{-- V-01 — the table scrolls horizontally; the « Par » column keeps a readable width. --}}
        <x-admin.table caption="Exports précédents" :headers="['Demandé le', 'Par', 'Statut', 'Disponible jusqu\'au', '']" empty="Aucun export pour ce projet.">
            @foreach ($exports as $export)
                <tr>
                    <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($export->createdAt)->format('d/m/Y H:i') }}</td>
                    {{-- F-08 — « Opérateur — e-mail », never `operator:<uuid>` as main text. --}}
                    <td class="px-4 py-2 text-sm text-slate-700 whitespace-nowrap">
                        @include('admin.audit-log._actor', ['actor' => $requesterLabels[$export->requestedBy] ?? new \App\Http\View\ActorLabel($export->requestedBy, $export->requestedBy)])
                    </td>
                    <td class="px-4 py-2 text-sm whitespace-nowrap">
                        @switch($export->status)
                            @case('done') {{ $export->available ? 'Prêt' : 'Fichier supprimé' }} @break
                            @case('failed') <span class="text-rose-700">Échec</span> @break
                            @case('running') En cours @break
                            @default En attente
                        @endswitch
                    </td>
                    <td class="px-4 py-2 text-sm text-slate-600 whitespace-nowrap">{{ $export->available ? \Illuminate\Support\Carbon::parse($export->expiresAt)->format('d/m/Y H:i') : '—' }}</td>
                    <td class="px-4 py-2 text-right">
                        @if ($export->available)
                            <form method="POST" action="{{ route('admin.projects.exports.download', ['projectId' => $projectId, 'exportId' => $export->id]) }}">
                                @csrf
                                <button class="rounded-md px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:text-indigo-900 hover:bg-indigo-50">Télécharger<span class="sr-only"> l'export du {{ \Illuminate\Support\Carbon::parse($export->createdAt)->format('d/m/Y H:i') }}</span></button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </x-admin.table>
    </section>
</div>
@endsection
