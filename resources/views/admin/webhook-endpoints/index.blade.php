@extends('layouts.admin')

@section('title', 'Points d\'entrée des événements')
@section('subtitle', $project->name)

@section('content')
<div class="max-w-4xl mx-auto space-y-6">

    <div class="text-right">
        <a href="{{ route('admin.webhook-events.index') }}" class="text-base text-indigo-600 hover:text-indigo-800 font-medium">
            Quels événements vais-je recevoir ? →
        </a>
    </div>

    {{-- Statut et erreurs : rendus par le layout (role="status" / role="alert"). --}}

    @if (! empty($created))
        <x-admin.alert variant="success" title="Point d\'entrée enregistré : {{ $created['url'] }}">
            <p>Secret de signature — copiez-le maintenant, il ne sera plus jamais affiché :</p>
            <code class="mt-1 block rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900 break-all">{{ $created['secret'] }}</code>
            <p class="mt-2">Remettez-le à l'application cliente hors du Core : l'application n'a pas de clé d'écriture, elle n'a que ce secret.</p>
        </x-admin.alert>
    @endif

    @if (! empty($rotated))
        <x-admin.alert variant="success" title="Nouveau secret de signature">
            <p>Affiché uniquement maintenant — copiez-le maintenant, il ne sera plus jamais affiché :</p>
            <code class="mt-1 block rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900 break-all">{{ $rotated['secret'] }}</code>
            <span class="mt-2 block">Jusqu'au {{ $rotated['previousSecretExpiresAt'] }}, chaque livraison porte aussi <code class="font-mono">X-Signature-Previous</code>, calculée avec l'ancien secret : le destinataire ne casse pas. Mettez à jour le vérificateur du destinataire avant cette échéance.</span>
        </x-admin.alert>
    @endif

    @if (! empty($tested))
        <x-admin.alert :variant="$tested['delivered'] ? 'success' : 'error'"
                       :title="$tested['delivered'] ? 'Le destinataire a bien reçu l\'événement de test.' : 'Le destinataire n\'a pas accepté l\'événement de test.'">
            <p>
                Réponse : {{ $tested['responseStatus'] ?? 'aucune' }}
                @if ($tested['durationMs'] !== null)
                    — {{ $tested['durationMs'] }} ms
                @endif
                — identifiant de l'événement <span class="font-mono">{{ $tested['eventId'] }}</span>.
            </p>
            @if (! empty($tested['error']))
                <p class="mt-1">Erreur : {{ $tested['error'] }}</p>
            @endif
            @if (! empty($tested['responseBody']))
                <p class="mt-1">Réponse du destinataire :</p>
                <pre class="mt-1 overflow-x-auto rounded bg-slate-50 border border-slate-200 px-2 py-1.5 text-xs font-mono text-slate-800">{{ $tested['responseBody'] }}</pre>
            @endif
        </x-admin.alert>
    @endif

    {{-- Existing endpoints — never shows the signing secret (there is no
         read path for it; WebhookEndpointRepository never exposes one).
         Active AND inactive endpoints both show (review finding: this
         used to query active-only, so a deactivated endpoint simply
         vanished — no way to see it was there, let alone reactivate or
         edit it). --}}
    <x-admin.table caption="Points d\'entrée enregistrés pour ce projet" :headers="['URL de réception', 'Événements', 'État', 'Dernière livraison', '']">
        <tbody class="divide-y divide-slate-100 bg-white">
        @forelse ($endpoints as $endpoint)
            @php
                $isAll = $endpoint->events === ['*'];
            @endphp
            <tr x-data="endpointEditor">
                <td class="px-4 py-3 align-top">
                    <span class="font-mono text-sm text-slate-900 break-all">{{ $endpoint->url }}</span>
                </td>
                <td class="px-4 py-3 align-top text-sm text-slate-600">
                    @if ($isAll)
                        Tous les événements
                    @else
                        <span class="font-mono text-xs break-all">{{ implode(', ', $endpoint->events) }}</span>
                    @endif
                </td>
                <td class="px-4 py-3 align-top">
                    <span @class([
                        'inline-flex rounded-full px-2 py-0.5 text-xs font-mono',
                        'bg-emerald-100 text-emerald-700' => $endpoint->active,
                        'bg-slate-100 text-slate-700' => ! $endpoint->active,
                    ])>
                        {{ $endpoint->active ? 'actif' : 'inactif' }}
                    </span>
                </td>
                <td class="px-4 py-3 align-top text-sm text-slate-600">
                    @php
                        $last = $lastDeliveries[$endpoint->id] ?? null;
                    @endphp
                    @if ($last === null)
                        <span class="text-slate-500">jamais</span>
                    @else
                        <x-admin.status-badge :status="$last['status']" />
                        <span class="mt-0.5 block text-xs text-slate-500">
                            {{ $last['at'] ?? 'en cours' }}
                        </span>
                    @endif
                </td>
                <td class="px-4 py-3 align-top">
                    <div class="flex flex-wrap items-center gap-1 text-sm">
                        <button type="button" @click="toggle"
                                aria-controls="endpoint-edit-{{ $endpoint->id }}" aria-expanded="false" :aria-expanded="ariaExpanded"
                                class="rounded-md px-2.5 py-1.5 font-medium text-indigo-700 hover:bg-indigo-50">
                            <span x-show="notEditing">Modifier</span>
                            <span x-show="editing" x-cloak>Fermer</span>
                            <span class="sr-only"> le point d\'entrée {{ $endpoint->url }}</span>
                        </button>

                        <form method="POST" action="{{ route('admin.projects.webhook-endpoints.test', ['projectId' => $project->id, 'endpointId' => $endpoint->id]) }}">
                            @csrf
                            <button type="submit" class="rounded-md px-2.5 py-1.5 font-medium text-indigo-700 hover:bg-indigo-50">
                                Tester<span class="sr-only"> {{ $endpoint->url }}</span>
                            </button>
                        </form>

                        {{-- C9–C13 : le déclencheur annonce un dialogue (aria-haspopup / aria-expanded posés par le composant). --}}
                        <x-admin.confirm-dialog
                            :id="'rotate-'.$endpoint->id"
                            title="Faire tourner le secret de signature ?"
                            message="Un nouveau secret s'affichera une seule fois. Pendant le recouvrement, chaque livraison porte deux signatures (X-Signature et X-Signature-Previous) : le destinataire ne casse pas."
                            :action="route('admin.projects.webhook-endpoints.rotate-secret', ['projectId' => $project->id, 'endpointId' => $endpoint->id])"
                            confirm="Générer le nouveau secret"
                            variant="primary">
                            <x-slot:trigger>
                                <button type="button" class="rounded-md px-2.5 py-1.5 font-medium text-indigo-700 hover:bg-indigo-50">Faire tourner le secret<span class="sr-only"> de {{ $endpoint->url }}</span></button>
                            </x-slot:trigger>
                            <x-admin.field name="overlap_hours" type="number" label="Recouvrement (heures)" :id="'overlap-'.$endpoint->id"
                                           :value="$overlap['default']" required :min="$overlap['min']" :max="$overlap['max']" class="w-28" />
                        </x-admin.confirm-dialog>

                        <form method="POST" action="{{ route('admin.projects.webhook-endpoints.'.($endpoint->active ? 'deactivate' : 'activate'), ['projectId' => $project->id, 'endpointId' => $endpoint->id]) }}">
                            @csrf
                            <button type="submit" class="rounded-md px-2.5 py-1.5 font-medium {{ $endpoint->active ? 'text-rose-700 hover:bg-rose-50' : 'text-emerald-700 hover:bg-emerald-50' }}">
                                {{ $endpoint->active ? 'Désactiver' : 'Réactiver' }}<span class="sr-only"> {{ $endpoint->url }}</span>
                            </button>
                        </form>
                    </div>

                    @if ($endpoint->previous_secret_expires_at !== null)
                        <div class="mt-2 flex flex-wrap items-center justify-between gap-2 rounded-md bg-amber-50 border border-amber-200 px-3 py-2 text-sm text-amber-900">
                            <span>Double signature active : l'ancien secret signe encore jusqu'au {{ \Carbon\CarbonImmutable::parse($endpoint->previous_secret_expires_at)->format('d/m/Y H:i') }}.</span>
                            <x-admin.confirm-dialog
                                :id="'revoke-'.$endpoint->id"
                                title="Révoquer l'ancien secret maintenant ?"
                                message="Les livraisons ne porteront plus que la signature du nouveau secret : un destinataire qui vérifie encore l'ancien les rejettera."
                                :action="route('admin.projects.webhook-endpoints.revoke-previous-secret', ['projectId' => $project->id, 'endpointId' => $endpoint->id])"
                                confirm="Révoquer">
                                <x-slot:trigger>
                                    <button type="button" class="rounded-md px-2.5 py-1.5 font-medium text-rose-700 hover:bg-rose-100">Révoquer l'ancien secret<span class="sr-only"> de {{ $endpoint->url }}</span></button>
                                </x-slot:trigger>
                                <x-admin.field name="reason" type="textarea" label="Motif (journalisé)" :id="'wh-reason-'.$endpoint->id" :rows="2" required minlength="3" maxlength="500" />
                            </x-admin.confirm-dialog>
                        </div>
                    @endif

                    <div id="endpoint-edit-{{ $endpoint->id }}" x-show="editing" x-cloak class="mt-3 pt-3 border-t border-slate-100">
                        <form method="POST" action="{{ route('admin.projects.webhook-endpoints.update', ['projectId' => $project->id, 'endpointId' => $endpoint->id]) }}" class="space-y-3">
                            @csrf
                            @method('PUT')

                            <div>
                                <label for="edit-url-{{ $endpoint->id }}" class="block text-sm font-medium text-slate-700 mb-1">URL de réception</label>
                                <input
                                    type="url"
                                    id="edit-url-{{ $endpoint->id }}"
                                    name="url"
                                    value="{{ $endpoint->url }}"
                                    required
                                    class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono" />
                            </div>

                            <div x-data="eventSelection" data-all="{{ $isAll ? 'true' : 'false' }}">
                                {{-- B8/B9 : un groupe de cases à cocher est un fieldset, son titre un legend. --}}
                                <fieldset>
                                <legend class="block text-sm font-medium text-slate-700 mb-1">Sur quoi être notifié ?</legend>
                                <div class="space-y-1.5">
                                    @foreach ($eventGroups as $group)
                                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-2">{{ $group['label'] }}</p>
                                        @foreach ($group['events'] as $event)
                                            <label class="flex items-start gap-2 text-sm text-slate-700" :class="dimmedClass">
                                                <input
                                                    type="checkbox"
                                                    name="events[]"
                                                    value="{{ $event['type'] }}"
                                                    :disabled="all"
                                                    @checked(in_array($event['type'], $endpoint->events, true))
                                                    class="mt-0.5 rounded border-slate-300" />
                                                <span>{{ $event['label'] }} <span class="font-mono text-xs text-slate-500">{{ $event['type'] }}</span></span>
                                            </label>
                                        @endforeach
                                    @endforeach
                                    <label class="flex items-center gap-2 text-sm text-slate-700 pt-1.5 border-t border-slate-100">
                                        <input type="checkbox" name="all" value="1" @checked($isAll) @change="toggleAll" class="rounded border-slate-300" />
                                        Tout recevoir
                                    </label>
                                </div>
                                </fieldset>
                            </div>

                            <button
                                type="submit"
                                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-2 text-sm font-medium text-white shadow-sm">
                                Enregistrer les modifications
                            </button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-base text-slate-600">
                    Aucun point d'entrée enregistré pour ce projet pour le moment.
                </td>
            </tr>
        @endforelse
        </tbody>
    </x-admin.table>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Enregistrer le point d'entrée d'une application cliente</h2>
        <p class="text-base text-slate-600 mb-3">
            Chaque événement publié par ce projet (client créé, abonnement renouvelé, facture
            réglée, limite atteinte…) sera livré, signé (HMAC-SHA256), à cette URL. Le secret
            de signature n'est affiché qu'une seule fois, juste après l'enregistrement :
            remettez-le à l'application cliente hors du Core.
        </p>

        <form method="POST" action="{{ route('admin.projects.webhook-endpoints.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <x-admin.errors />

            <x-admin.field name="url" type="url" label="URL de réception" id="url" required autofocus
                           placeholder="https://exemple.com/evenements/core" class="font-mono"
                           hint="L'adresse que le Core appellera — elle doit être joignable depuis l'extérieur." />

            <div x-data="eventSelection" data-all="{{ old('all') === '1' ? 'true' : 'false' }}">
                <fieldset aria-describedby="events-hint{{ $errors->has('events') ? ' events-error' : '' }}">
                    <legend class="block text-base font-medium text-slate-700 mb-1">Sur quoi voulez-vous être notifié ?</legend>
                    <p id="events-hint" class="mb-2 text-sm text-slate-600">
                        Les événements du commerce du projet (clients, abonnements, factures, limites d'usage) sont
                        pré-sélectionnés : ce sont ceux dont une application cliente a besoin. Voir
                        <a href="{{ route('admin.webhook-events.index') }}" class="text-indigo-700 hover:text-indigo-900 underline">le détail de ce que chaque événement envoie</a>.
                    </p>
                    <div class="space-y-2">
                        @foreach ($eventGroups as $group)
                            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 mt-3">{{ $group['label'] }}</p>
                            @foreach ($group['events'] as $event)
                                @php
                                    $checked = is_array(old('events'))
                                        ? in_array($event['type'], old('events'), true)
                                        : $event['preselected'];
                                @endphp
                                <label class="flex items-start gap-2 text-base text-slate-700" :class="dimmedClass">
                                    <input
                                        type="checkbox"
                                        name="events[]"
                                        value="{{ $event['type'] }}"
                                        :disabled="all"
                                        @checked($checked)
                                        class="mt-1 rounded border-slate-300" />
                                    <span>{{ $event['label'] }} <span class="font-mono text-xs text-slate-500">{{ $event['type'] }}</span></span>
                                </label>
                            @endforeach
                        @endforeach
                        <label class="flex items-center gap-2 text-base text-slate-700 pt-2 border-t border-slate-100">
                            <input type="checkbox" name="all" value="1" @checked(old('all') === '1') @change="toggleAll" class="rounded border-slate-300" />
                            Tout recevoir
                        </label>
                    </div>
                </fieldset>
                @error('events')
                    <p id="events-error" class="mt-1 text-sm font-medium text-rose-700"><span class="sr-only">Erreur : </span>{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer le point d'entrée
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-600 hover:text-slate-800">
                Retour au projet
            </a>
        </form>
    </div>
</div>
@endsection
