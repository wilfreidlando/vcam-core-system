@extends('layouts.admin')

@section('title', 'Application cliente')

@section('content')
@php
    $ready = $target->isReady();
    $address = $target->baseUrl;
@endphp
<div class="space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">L'application d'un projet, branchée sur le Core</p>
        <p>
            Une application de gestion peut laisser le Core managing ses clients : le Core va chercher
            la forme qu'elle veut, puis les clients qu'elle a déjà. Tout se passe ici — aucune commande,
            aucun fichier.
        </p>
    </div>

    {{-- ① Les événements --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
        <h2 class="text-base font-semibold text-slate-900">① L'application reçoit-elle les événements ?</h2>

        @if ($endpoint === null)
            <p class="text-sm text-slate-700">
                {{ $target->reason ?? 'Aucun point d\'entrée n\'est enregistré pour ce projet.' }}
            </p>
            <a href="{{ route('admin.projects.webhook-endpoints.index', $projectId) }}"
               class="inline-block rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                Enregistrer le point d'entrée
            </a>
        @else
            <p class="text-sm text-slate-700">
                <span class="font-mono text-slate-900">{{ $endpoint->url }}</span>
            </p>
            <p class="text-sm text-slate-600">
                Événements :
                @forelse ($endpoint->events as $event)
                    <span class="font-mono">{{ $event }}</span>@if (! $loop->last), @endif
                @empty
                    <span class="text-slate-500">aucun</span>
                @endforelse
            </p>
        @endif
    </div>

    {{-- ② L'adresse appelée --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
        <h2 class="text-base font-semibold text-slate-900">② L'application parle-t-elle au Core ?</h2>

        @if (! $ready)
            <p class="text-sm text-slate-700">
                {{ $target->reason ?? 'Le Core ne sait pas encore à qui parler.' }}
            </p>
        @else
            <p class="text-sm text-slate-700">
                Le Core appelle l'application à
                <span class="font-mono text-slate-900">{{ $address }}</span>,
                et se signe avec le secret du point d'entrée — le même secret que les événements.
            </p>

            @if (count($target->choices) > 0)
                <form method="POST" action="{{ route('admin.projects.customers.application.address', $projectId) }}"
                      class="max-w-2xl space-y-4">
                    @csrf

                    @if (count($target->choices) > 1)
                        <x-admin.field name="endpoint_id" type="select" label="Point d'entrée" required
                                       hint="Plusieurs points d'entrée sont enregistrés : choisissez celui qui reçoit les événements de l'application.">
                            <select id="field-endpoint_id" name="endpoint_id" required
                                    class="block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm">
                                @foreach ($target->choices as $choice)
                                    <option value="{{ $choice->id }}" @selected($choice->id === $target->endpointId)>
                                        {{ $choice->url }}
                                    </option>
                                @endforeach
                            </select>
                        </x-admin.field>
                    @else
                        <input type="hidden" name="endpoint_id" value="{{ $target->endpointId }}">
                    @endif

                    <x-admin.field name="base_url" type="url" label="Adresse de l'application" required
                                   :value="$address"
                                   hint="Celle que le Core appelle. Elle est déduite du point d'entrée : corrigez-la seulement si l'API de l'application n'est pas sur le même hôte." />

                    <div class="flex items-center gap-3 pt-1">
                        <button type="submit"
                                class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">
                            Enregistrer l'adresse
                        </button>
                    </div>
                </form>
            @endif
        @endif
    </div>

    {{-- ③ La forme du client --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
        <h2 class="text-base font-semibold text-slate-900">③ La forme du client</h2>

        @if ($form === null)
            <p class="text-sm text-slate-700">Aucune forme déclarée : le Core attend la forme que l'application veut.</p>
        @else
            <p class="text-sm text-slate-700">
                La forme en vigueur : la référence suit
                <span class="font-mono text-slate-900">{{ $form->uniqueKeyPattern }}</span>,
                et {{ count($form->fields) }} champ{{ count($form->fields) > 1 ? 's' : '' }} annoncé{{ count($form->fields) > 1 ? 's' : '' }}.
            </p>
        @endif

        @if ($proposal !== null)
            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                L'application a proposé une forme qui attend votre décision.
                <a href="{{ route('admin.projects.customers.schema', $projectId) }}"
                   class="ml-1 font-medium underline">Lisez-la</a>.
            </div>
        @else
            <form method="POST" action="{{ route('admin.projects.customers.application.form', $projectId) }}">
                @csrf
                <button type="submit"
                        @disabled(! $ready)
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                    Récupérer la forme de l'application
                </button>
            </form>
        @endif
    </div>

    {{-- ④ Les clients --}}
    <div class="rounded-lg border border-slate-200 bg-white p-5 space-y-3">
        <h2 class="text-base font-semibold text-slate-900">④ Les clients de l'application</h2>

        <p class="text-sm text-slate-700">
            @if ($knownCustomers === 0)
                Le Core ne connaît encore aucun client de ce projet.
            @else
                Le Core connaît {{ $knownCustomers }} client{{ $knownCustomers > 1 ? 's' : '' }} de ce projet.
            @endif
        </p>

        <form method="POST" action="{{ route('admin.projects.customers.application.clients.preview', $projectId) }}">
            @csrf
            <button type="submit"
                    @disabled(! $ready)
                    class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800 disabled:cursor-not-allowed disabled:bg-slate-300">
                Créer les clients depuis l'application
            </button>
        </form>
    </div>

    @if ($report !== null)
        @php
            $created = (int) $report['created'];
            $known = (int) $report['alreadyKnown'];
            $refused = (int) $report['refused'];
        @endphp
        <div class="space-y-4 rounded-lg border border-slate-200 bg-white p-5">
            <div>
                <h2 class="text-base font-semibold text-slate-900">
                    {{ $reportKind === 'preview' ? 'Aperçu' : 'Rapport' }}
                </h2>
                <p class="mt-1 text-sm text-slate-700">
                    {{ $report['lines'] }} client{{ $report['lines'] > 1 ? 's' : '' }} dans l'application ·
                    {{ $created }} à créer · {{ $known }} déjà connu{{ $known > 1 ? 's' : '' }} ·
                    {{ $refused }} refusé{{ $refused > 1 ? 's' : '' }}.
                </p>
                @if ($report['interrupted'] !== null)
                    <p class="mt-2 text-sm font-medium text-amber-800">{{ $report['interrupted'] }}</p>
                @endif
            </div>

            @if ($reportKind === 'preview')
                <form method="POST" action="{{ route('admin.projects.customers.application.clients.import', $projectId) }}">
                    @csrf
                    <button type="submit"
                            class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                        Créer les clients
                    </button>
                </form>
            @endif

            <x-admin.table caption="Clients refusés" :headers="['Ligne', 'Référence', 'Motif']" empty="Aucun client refusé.">
                @if (count($report['refusals']) > 0)
                    <tbody class="divide-y divide-slate-100 bg-white">
                    @foreach ($report['refusals'] as $refusal)
                        <tr>
                            <td class="px-4 py-2 text-sm tabular-nums">{{ $refusal['line'] }}</td>
                            <td class="px-4 py-2 text-sm font-mono text-slate-700">{{ $refusal['reference'] }}</td>
                            <td class="px-4 py-2 text-sm text-slate-700">{{ $refusal['reason'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                @endif
            </x-admin.table>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <a href="{{ route('admin.projects.customers.index', $projectId) }}"
           class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">← Retour aux clients</a>
    </div>
</div>
@endsection
