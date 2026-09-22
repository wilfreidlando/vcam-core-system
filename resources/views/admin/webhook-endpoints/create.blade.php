@extends('layouts.admin')

@section('title', 'Endpoints webhook')
@section('subtitle', $project->name)

@section('content')
<div class="max-w-md mx-auto space-y-6">

    <div class="text-right">
        <a href="{{ route('admin.webhook-events.index') }}" class="text-base text-indigo-600 hover:text-indigo-800 font-medium">
            Quels événements vais-je recevoir ? →
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if ($errors->any() && ! $errors->has('url') && ! $errors->has('categories'))
        {{-- `url`/`categories` errors render inline next to their field
             (create or edit form, whichever was submitted); anything
             else (e.g. activate/deactivate failing) has no field to
             attach to, so it needs this banner — same reasoning as
             webhook-deliveries/dead.blade.php's replay-error banner. --}}
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if (! empty($created))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-base font-semibold text-emerald-800">Endpoint enregistré : {{ $created['url'] }}</p>
            <p class="mt-1 text-base text-emerald-700">
                Secret de signature — affiché uniquement maintenant, jamais stocké ni journalisé :
                <code class="ml-1 rounded bg-emerald-100 px-1.5 py-0.5 font-mono text-emerald-900 break-all">{{ $created['secret'] }}</code>
            </p>
        </div>
    @endif

    {{-- Existing endpoints — never shows the signing secret (there is no
         read path for it; WebhookEndpointRepository never exposes one).
         Active AND inactive endpoints both show (review finding: this
         used to query active-only, so a deactivated endpoint simply
         vanished — no way to see it was there, let alone reactivate or
         edit it). --}}
    <div class="bg-white border border-slate-200 rounded-lg p-6">
        <h2 class="text-lg font-semibold text-slate-900 mb-3">Endpoints enregistrés</h2>
        @if (count($endpoints) === 0)
            <p class="text-base text-slate-500">Aucun endpoint webhook enregistré pour ce projet pour le moment.</p>
        @else
            <ul class="divide-y divide-slate-100">
                @foreach ($endpoints as $endpoint)
                    @php
                        $matchedCategory = collect($categories)->search(
                            fn (array $category) => $category['events'] === $endpoint->events,
                        );
                        $isAll = $endpoint->events === ['*'];
                    @endphp
                    <li class="py-3 text-base" x-data="{ editing: false }">
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-slate-900 truncate">{{ $endpoint->url }}</span>
                            <span @class([
                                'shrink-0 inline-flex rounded-full px-2 py-0.5 text-xs font-mono',
                                'bg-emerald-100 text-emerald-700' => $endpoint->active,
                                'bg-slate-100 text-slate-700' => ! $endpoint->active,
                            ])>
                                {{ $endpoint->active ? 'actif' : 'inactif' }}
                            </span>
                        </div>
                        <div class="flex items-center justify-between gap-2 mt-0.5">
                            <p class="text-sm text-slate-500">
                                @if ($isAll)
                                    Tout
                                @elseif ($matchedCategory !== false)
                                    {{ $categories[$matchedCategory]['label'] }}
                                @else
                                    <span class="font-mono">{{ implode(', ', $endpoint->events) }}</span>
                                @endif
                            </p>
                            <div class="flex items-center gap-3 text-sm">
                                <button type="button" @click="editing = ! editing" class="font-medium text-indigo-600 hover:text-indigo-800">
                                    <span x-show="! editing">Modifier</span>
                                    <span x-show="editing" x-cloak>Fermer</span>
                                </button>
                                <form method="POST" action="{{ route('admin.projects.webhook-endpoints.'.($endpoint->active ? 'deactivate' : 'activate'), ['projectId' => $project->id, 'endpointId' => $endpoint->id]) }}">
                                    @csrf
                                    <button type="submit" class="font-medium {{ $endpoint->active ? 'text-rose-600 hover:text-rose-800' : 'text-emerald-600 hover:text-emerald-800' }}">
                                        {{ $endpoint->active ? 'Désactiver' : 'Réactiver' }}
                                    </button>
                                </form>
                            </div>
                        </div>

                        <div x-show="editing" x-cloak class="mt-3 pt-3 border-t border-slate-100">
                            <form method="POST" action="{{ route('admin.projects.webhook-endpoints.update', ['projectId' => $project->id, 'endpointId' => $endpoint->id]) }}" class="space-y-3">
                                @csrf
                                @method('PUT')

                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">URL de réception</label>
                                    <input
                                        type="url"
                                        name="url"
                                        value="{{ $endpoint->url }}"
                                        required
                                        class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm font-mono" />
                                </div>

                                <div x-data="{ all: {{ $isAll ? 'true' : 'false' }} }">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Sur quoi être notifié ?</label>
                                    <div class="space-y-1.5">
                                        @foreach ($categories as $key => $category)
                                            <label class="flex items-center gap-2 text-sm text-slate-700" :class="{ 'opacity-50': all }">
                                                <input
                                                    type="checkbox"
                                                    name="categories[]"
                                                    value="{{ $key }}"
                                                    :disabled="all"
                                                    @checked($category['events'] === $endpoint->events)
                                                    class="rounded border-slate-300" />
                                                {{ $category['label'] }}
                                            </label>
                                        @endforeach
                                        <label class="flex items-center gap-2 text-sm text-slate-700 pt-1.5 border-t border-slate-100">
                                            <input type="checkbox" name="all" value="1" x-model="all" @checked($isAll) class="rounded border-slate-300" />
                                            Tout recevoir
                                        </label>
                                    </div>
                                </div>

                                <button
                                    type="submit"
                                    class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-2 text-sm font-medium text-white shadow-sm">
                                    Enregistrer les modifications
                                </button>
                            </form>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <div class="bg-white border border-slate-200 rounded-lg p-6">

        <h2 class="text-lg font-semibold text-slate-900 mb-1">Enregistrer un endpoint webhook</h2>
        <p class="text-base text-slate-600 mb-3">
            Chaque événement publié par ce projet (paiement mis à jour, wallet
            crédité…) sera livré, signé (HMAC-SHA256), à cette URL. Le secret
            de signature n'est affiché qu'une seule fois, juste après
            l'enregistrement.
        </p>

        <form method="POST" action="{{ route('admin.projects.webhook-endpoints.store', ['projectId' => $project->id]) }}" class="space-y-4">
            @csrf

            <div>
                <label for="url" class="block text-base font-medium text-slate-700 mb-1">URL de réception</label>
                <input
                    type="url"
                    name="url"
                    id="url"
                    value="{{ old('url') }}"
                    required
                    autofocus
                    placeholder="https://exemple.com/webhooks/core"
                    class="block w-full rounded-md border border-slate-300 px-4 py-2.5 text-base shadow-sm font-mono" />
                @error('url')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <div x-data="{ all: {{ old('all') === '1' ? 'true' : 'false' }} }">
                <label class="block text-base font-medium text-slate-700 mb-1">Sur quoi voulez-vous être notifié ?</label>
                <div class="space-y-2">
                    @foreach ($categories as $key => $category)
                        <label class="flex items-center gap-2 text-base text-slate-700" :class="{ 'opacity-50': all }">
                            <input
                                type="checkbox"
                                name="categories[]"
                                value="{{ $key }}"
                                :disabled="all"
                                @checked(is_array(old('categories')) && in_array($key, old('categories'), true))
                                class="rounded border-slate-300" />
                            {{ $category['label'] }}
                        </label>
                    @endforeach
                    <label class="flex items-center gap-2 text-base text-slate-700 pt-2 border-t border-slate-100">
                        <input type="checkbox" name="all" value="1" x-model="all" class="rounded border-slate-300" />
                        Tout recevoir
                    </label>
                </div>
                <p class="mt-1 text-sm text-slate-500">
                    « Tout recevoir » mélange des types d'événements différents sur la même URL — préférez une
                    catégorie ciblée si votre intégration doit interpréter la réponse.
                    Voir <a href="{{ route('admin.webhook-events.index') }}" class="text-indigo-600 hover:text-indigo-800 underline">le détail de ce que chaque catégorie envoie</a>.
                </p>
                @error('categories')
                    <p class="mt-1 text-sm text-rose-600">{{ $message }}</p>
                @enderror
            </div>

            <button
                type="submit"
                class="w-full inline-flex justify-center items-center rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">
                Enregistrer l'endpoint
            </button>

            <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
                Retour au projet
            </a>
        </form>
    </div>
</div>
@endsection
