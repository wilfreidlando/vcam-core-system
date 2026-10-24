@extends('layouts.admin')

@section('title', 'Clés API')
@section('subtitle', $project->name)

{{-- ADR-0082 — plusieurs clés nommées par projet, portées choisies par modèle,
     restriction réseau et expiration optionnelles, rotation sans coupure,
     révocation d'urgence motivée. --}}
@section('content')
@php
    $scopeLabel = function (array $scopes) use ($templates, $templateScopes): string {
        if (in_array('*', $scopes, true)) {
            return 'Accès total';
        }
        $sorted = $scopes; sort($sorted);
        foreach ($templateScopes as $key => $list) {
            $l = $list; sort($l);
            if ($l === $sorted) {
                return $templates[$key];
            }
        }

        return 'Personnalisée · '.count($scopes).' portée(s)';
    };
    $now = new DateTimeImmutable();
@endphp
<div class="max-w-4xl mx-auto space-y-6">

    @if ($errors->any())
        <div class="rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800">
            @foreach ($errors->all() as $error)
                <p>{{ $error }}</p>
            @endforeach
        </div>
    @endif

    @if (session('status'))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3 text-base text-emerald-800">
            {{ session('status') }}
        </div>
    @endif

    @if (! empty($created))
        <div class="rounded-md border border-emerald-200 bg-emerald-50 px-4 py-3">
            <p class="text-base font-semibold text-emerald-800">Nouvelle clé : {{ $created['prefix'] }}</p>
            <p class="mt-1 text-base text-emerald-700">
                Affichée uniquement maintenant, jamais stockée ni journalisée en clair — copiez-la avant de quitter cette page :
            </p>
            <code class="mt-1 block rounded bg-emerald-100 px-2 py-1.5 font-mono text-sm text-emerald-900 break-all">{{ $created['plaintextToken'] }}</code>
        </div>
    @endif

    <section class="bg-white border border-slate-200 rounded-lg">
        <header class="px-6 py-4 border-b border-slate-200">
            <h2 class="text-lg font-semibold text-slate-900">Clés du projet</h2>
            <p class="text-sm text-slate-600 mt-1">Une clé par composant (serveur, portail, versements…) : chacune ne porte que les droits dont elle a besoin. À ne jamais partager hors de vos serveurs.</p>
        </header>

        @forelse ($keys as $key)
            @php
                $expired = $key->expiresAt !== null && $key->expiresAt <= $now;
            @endphp
            <div class="px-6 py-4 border-b border-slate-100 last:border-b-0" x-data="{ confirm: null }">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-900">{{ $key->name }}</p>
                        <p class="font-mono text-sm text-slate-600">{{ $key->prefix }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! $key->active)
                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-600">Expirée</span>
                        @elseif ($key->replacedByApiKeyId !== null)
                            <span class="rounded-full bg-amber-100 px-2.5 py-0.5 text-xs font-medium text-amber-800">Remplacée · valide jusqu'au {{ $key->expiresAt?->format('d/m/Y H:i') }}</span>
                        @elseif ($key->expiresAt !== null)
                            <span class="rounded-full bg-sky-100 px-2.5 py-0.5 text-xs font-medium text-sky-800">Active · expire le {{ $key->expiresAt->format('d/m/Y') }}</span>
                        @else
                            <span class="rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-medium text-emerald-800">Active</span>
                        @endif
                        @if ($key->hasFullAccess())
                            <span class="rounded-full bg-rose-100 px-2.5 py-0.5 text-xs font-medium text-rose-800" title="Clé à accès total : préférez un modèle de portées.">Accès total</span>
                        @endif
                    </div>
                </div>

                <dl class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-1.5 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Portées</dt><dd class="text-slate-900 text-right" title="{{ implode(', ', $key->scopes) }}">{{ $scopeLabel($key->scopes) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Réseau</dt><dd class="font-mono text-xs text-slate-700 text-right">{{ $key->allowedCidrs === [] ? 'toute adresse' : implode(', ', $key->allowedCidrs) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Créée le</dt><dd class="text-slate-900">{{ $key->createdAt->format('d/m/Y H:i') }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-500">Dernière utilisation</dt><dd class="text-slate-900">{{ $key->lastUsedAt?->format('d/m/Y H:i') ?? 'jamais' }}@if ($key->lastUsedIp) <span class="font-mono text-xs text-slate-500">· {{ $key->lastUsedIp }}</span>@endif</dd></div>
                </dl>

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($key->active && $key->replacedByApiKeyId === null)
                        <button type="button" @click="confirm = 'rotate'" class="rounded-md border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-3 py-1.5 text-sm font-medium">Faire tourner</button>
                    @endif
                    <button type="button" @click="confirm = 'revoke'" class="rounded-md border border-rose-300 text-rose-700 hover:bg-rose-50 px-3 py-1.5 text-sm font-medium">Révoquer</button>
                </div>

                <div x-show="confirm !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50" @click.self="confirm = null">
                    <div class="bg-white rounded-lg shadow-xl p-6 max-w-md mx-4">
                        <template x-if="confirm === 'rotate'">
                            <form method="POST" action="{{ route('admin.projects.api-keys.rotate', ['projectId' => $project->id, 'apiKeyId' => $key->id]) }}">
                                @csrf
                                <p class="font-semibold text-slate-900 mb-2">Faire tourner « {{ $key->name }} » ?</p>
                                <p class="text-sm text-slate-600 mb-3">Une nouvelle clé, avec les mêmes portées et la même restriction réseau, s'affichera une seule fois. {{ $key->prefix }} reste valide le temps de déployer la nouvelle, puis cesse de fonctionner seule.</p>
                                <label class="block text-sm font-medium text-slate-700 mb-1" for="grace-{{ $key->id }}">Ancienne clé valide encore (jours)</label>
                                <input id="grace-{{ $key->id }}" type="number" name="grace_days" min="{{ $graceDays['min'] }}" max="{{ $graceDays['max'] }}" value="{{ $graceDays['default'] }}" required class="w-28 rounded-md border-slate-300 text-sm">
                                <div class="mt-4 flex justify-end gap-2">
                                    <button type="button" @click="confirm = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                    <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-3 py-1.5 text-sm font-medium text-white">Créer la remplaçante</button>
                                </div>
                            </form>
                        </template>
                        <template x-if="confirm === 'revoke'">
                            <form method="POST" action="{{ route('admin.projects.api-keys.revoke', ['projectId' => $project->id, 'apiKeyId' => $key->id]) }}">
                                @csrf
                                <p class="font-semibold text-slate-900 mb-2">Révoquer « {{ $key->name }} » immédiatement ?</p>
                                <p class="text-sm text-slate-600 mb-3">{{ $key->prefix }} sera supprimée : toute application qui l'utilise est rejetée dès sa prochaine requête.</p>
                                <label class="block text-sm font-medium text-slate-700 mb-1" for="reason-{{ $key->id }}">Motif (journalisé)</label>
                                <textarea id="reason-{{ $key->id }}" name="reason" rows="2" minlength="3" maxlength="500" required class="w-full rounded-md border-slate-300 text-sm" placeholder="Clé exposée dans un dépôt, départ d'un prestataire…"></textarea>
                                <div class="mt-4 flex justify-end gap-2">
                                    <button type="button" @click="confirm = null" class="rounded-md border border-slate-300 px-3 py-1.5 text-sm">Annuler</button>
                                    <button type="submit" class="rounded-md bg-rose-600 hover:bg-rose-700 px-3 py-1.5 text-sm font-medium text-white">Révoquer</button>
                                </div>
                            </form>
                        </template>
                    </div>
                </div>
            </div>
        @empty
            <p class="px-6 py-6 text-base text-slate-500">Aucune clé pour ce projet.</p>
        @endforelse
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-6" x-data='{ template: @js(old('template', 'server')) }'>
        <h2 class="text-lg font-semibold text-slate-900">Nouvelle clé</h2>
        <form method="POST" action="{{ route('admin.projects.api-keys.store', ['projectId' => $project->id]) }}" class="mt-4 space-y-5">
            @csrf
            <div>
                <label for="name" class="block text-sm font-medium text-slate-700 mb-1">Nom</label>
                <input id="name" name="name" type="text" maxlength="80" required value="{{ old('name') }}" placeholder="Serveur de facturation" class="w-full rounded-md border-slate-300 text-sm">
            </div>

            <fieldset>
                <legend class="block text-sm font-medium text-slate-700 mb-2">Portées</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($templates as $value => $label)
                        <label class="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 cursor-pointer hover:bg-slate-50">
                            <input type="radio" name="template" value="{{ $value }}" x-model="template" class="mt-1">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">{{ $label }}</span>
                                <span class="block text-xs text-slate-500">{{ count($templateScopes[$value]) }} portée(s)@if ($value === 'payouts') · clé séparée conseillée, avec restriction réseau @endif</span>
                            </span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 cursor-pointer hover:bg-slate-50">
                        <input type="radio" name="template" value="custom" x-model="template" class="mt-1">
                        <span class="block text-sm font-medium text-slate-900">Personnalisée</span>
                    </label>
                </div>
                <div x-show="template === 'custom'" x-cloak class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-1">
                    @foreach ($allScopes as $scope)
                        <label class="flex items-center gap-2 text-sm font-mono text-slate-700">
                            <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true))>
                            {{ $scope }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <div>
                <label for="allowed_cidrs" class="block text-sm font-medium text-slate-700 mb-1">Restriction réseau <span class="font-normal text-slate-500">(facultatif)</span></label>
                <textarea id="allowed_cidrs" name="allowed_cidrs" rows="2" class="w-full rounded-md border-slate-300 font-mono text-sm" placeholder="203.0.113.0/24&#10;2001:db8::/32">{{ old('allowed_cidrs') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">Un bloc par ligne. Une fois renseignée, tout appel depuis une autre adresse est refusé (403).</p>
            </div>

            <div>
                <label for="expires_at" class="block text-sm font-medium text-slate-700 mb-1">Expiration <span class="font-normal text-slate-500">(facultatif, heure UTC)</span></label>
                <input id="expires_at" name="expires_at" type="datetime-local" value="{{ old('expires_at') }}" class="rounded-md border-slate-300 text-sm">
            </div>

            <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">Créer la clé</button>
        </form>
    </section>

    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-500 hover:text-slate-700">
        Retour au projet
    </a>
</div>
@endsection
