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

    {{-- Statut et erreurs : rendus par le layout (role="status" / role="alert"). --}}

    @if (! empty($created))
        {{-- Le secret est lu dans la vue de CETTE réponse (`$created`), jamais flashé en session. --}}
        <x-admin.alert variant="success" title="Nouvelle clé : {{ $created['prefix'] }}">
            Affichée uniquement maintenant, jamais stockée ni journalisée en clair — copiez-la avant de quitter cette page :
            <code class="mt-1 block rounded bg-emerald-100 px-2 py-1.5 font-mono text-sm text-emerald-900 break-all">{{ $created['plaintextToken'] }}</code>
        </x-admin.alert>
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
            <div class="px-6 py-4 border-b border-slate-100 last:border-b-0" >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-semibold text-slate-900">{{ $key->name }}</p>
                        <p class="font-mono text-sm text-slate-600">{{ $key->prefix }}</p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @if (! $key->active)
                            <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-medium text-slate-700">Expirée</span>
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
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">Portées</dt><dd class="text-slate-900 text-right" title="{{ implode(', ', $key->scopes) }}">{{ $scopeLabel($key->scopes) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">Réseau</dt><dd class="font-mono text-xs text-slate-700 text-right">{{ $key->allowedCidrs === [] ? 'toute adresse' : implode(', ', $key->allowedCidrs) }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">Créée le</dt><dd class="text-slate-900">{{ $key->createdAt->format('d/m/Y H:i') }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-slate-600">Dernière utilisation</dt><dd class="text-slate-900">{{ $key->lastUsedAt?->format('d/m/Y H:i') ?? 'jamais' }}@if ($key->lastUsedIp) <span class="font-mono text-xs text-slate-600">· {{ $key->lastUsedIp }}</span>@endif</dd></div>
                </dl>

                <div class="mt-3 flex flex-wrap gap-2">
                    @if ($key->active && $key->replacedByApiKeyId === null)
                        <x-admin.confirm-dialog
                            :id="'rotate-'.$key->id"
                            title="Faire tourner « {{ $key->name }} » ?"
                            :message="'Une nouvelle clé, avec les mêmes portées et la même restriction réseau, s\'affichera une seule fois. '.$key->prefix.' reste valide le temps de déployer la nouvelle, puis cesse de fonctionner seule.'"
                            :action="route('admin.projects.api-keys.rotate', ['projectId' => $project->id, 'apiKeyId' => $key->id])"
                            confirm="Créer la remplaçante"
                            variant="primary">
                            <x-slot:trigger>
                                <button type="button" class="rounded-md border border-indigo-300 text-indigo-700 hover:bg-indigo-50 px-3 py-1.5 text-sm font-medium">Faire tourner<span class="sr-only"> la clé {{ $key->name }}</span></button>
                            </x-slot:trigger>
                            <x-admin.field name="grace_days" type="number" label="Ancienne clé valide encore (jours)" :id="'grace-'.$key->id" required
                                           :min="$graceDays['min']" :max="$graceDays['max']" :value="$graceDays['default']" class="w-28" />
                        </x-admin.confirm-dialog>
                    @endif
                    <x-admin.confirm-dialog
                        :id="'revoke-'.$key->id"
                        title="Révoquer « {{ $key->name }} » immédiatement ?"
                        :message="$key->prefix.' sera supprimée : toute application qui l\'utilise est rejetée dès sa prochaine requête.'"
                        :action="route('admin.projects.api-keys.revoke', ['projectId' => $project->id, 'apiKeyId' => $key->id])"
                        confirm="Révoquer">
                        <x-slot:trigger>
                            <button type="button" class="rounded-md border border-rose-300 text-rose-700 hover:bg-rose-50 px-3 py-1.5 text-sm font-medium">Révoquer<span class="sr-only"> la clé {{ $key->name }}</span></button>
                        </x-slot:trigger>
                        <x-admin.field name="reason" type="textarea" label="Motif (journalisé)" :id="'reason-'.$key->id" :rows="2" required minlength="3" maxlength="500"
                                       placeholder="Exemple : clé exposée dans un dépôt, départ d'un prestataire" />
                    </x-admin.confirm-dialog>
                </div>
            </div>
        @empty
            <p class="px-6 py-6 text-base text-slate-600">Aucune clé pour ce projet.</p>
        @endforelse
    </section>

    <section class="bg-white border border-slate-200 rounded-lg p-6" x-data="apiKeyTemplate" data-template="{{ old('template', 'server') }}">
        <h2 class="text-lg font-semibold text-slate-900">Nouvelle clé</h2>
        <form method="POST" action="{{ route('admin.projects.api-keys.store', ['projectId' => $project->id]) }}" class="mt-4 space-y-5">
            @csrf
            <x-admin.errors />
            <x-admin.field name="name" label="Nom" required maxlength="80" placeholder="Serveur de facturation" />

            <fieldset>
                <legend class="block text-sm font-medium text-slate-700 mb-2">Portées</legend>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                    @foreach ($templates as $value => $label)
                        <label class="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 cursor-pointer hover:bg-slate-50">
                            <input type="radio" name="template" value="{{ $value }}" @checked(old('template', 'server') === $value) @change="selectTemplate" class="mt-1">
                            <span>
                                <span class="block text-sm font-medium text-slate-900">{{ $label }}</span>
                                <span class="block text-xs text-slate-600">{{ count($templateScopes[$value]) }} portée(s)@if ($value === 'payouts') · clé séparée conseillée, avec restriction réseau @endif</span>
                            </span>
                        </label>
                    @endforeach
                    <label class="flex items-start gap-2 rounded-md border border-slate-200 px-3 py-2 cursor-pointer hover:bg-slate-50">
                        <input type="radio" name="template" value="custom" @checked(old('template', 'server') === 'custom') @change="selectTemplate" class="mt-1">
                        <span class="block text-sm font-medium text-slate-900">Personnalisée</span>
                    </label>
                </div>
                <div x-show="custom" x-cloak class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-1">
                    @foreach ($allScopes as $scope)
                        <label class="flex items-center gap-2 text-sm font-mono text-slate-700">
                            <input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', []), true))>
                            {{ $scope }}
                        </label>
                    @endforeach
                </div>
            </fieldset>

            <x-admin.field name="allowed_cidrs" type="textarea" label="Restriction réseau" optional :rows="2" class="font-mono"
                           placeholder="203.0.113.0/24&#10;2001:db8::/32"
                           hint="Un bloc par ligne. Une fois renseignée, tout appel depuis une autre adresse est refusé (403)." />
            <x-admin.field name="expires_at" type="datetime-local" label="Expiration (heure UTC)" optional />

            <button type="submit" class="rounded-md bg-indigo-600 hover:bg-indigo-700 px-4 py-2.5 text-base font-medium text-white shadow-sm">Créer la clé</button>
        </form>
    </section>

    <a href="{{ route('admin.projects.show', ['projectId' => $project->id]) }}" class="block text-center text-base text-slate-600 hover:text-slate-800">
        Retour au projet
    </a>
</div>
@endsection
