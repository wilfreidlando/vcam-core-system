@extends('layouts.admin')

@section('title', $version !== null ? 'Corriger la version '.$version->version : ($plan !== null ? 'Ajouter une version' : 'Nouvelle offre'))

@section('content')
@php
    // Les compteurs se choisissent : on ne les saisit pas deux fois.
    $counterOptions = array_combine($counters, $counters);
@endphp
{{-- Les erreurs de validation sont listées une fois par le layout (role="alert") ;
     chaque champ reprend son message (aria-describedby, aria-invalid). Dans chaque
     champ, old() passe AVANT la valeur par défaut : la saisie de l'opérateur survit
     à un refus du serveur. --}}
<div class="max-w-2xl space-y-6">
    @if ($plan === null)
        <form method="POST" action="{{ route('admin.projects.plans.store', $projectId) }}"
              class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
            @csrf

            <div class="grid gap-4 sm:grid-cols-2">
                <x-admin.field name="code" label="Code" required maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" class="font-mono"
                               hint="Stable dans le temps : minuscules, chiffres, tirets et underscores." />
                <x-admin.field name="visibility" label="Visibilité" type="select" value="public"
                               :options="['public' => 'Proposé publiquement', 'private' => 'Attribué seulement']" />
            </div>

            <x-admin.field name="name" label="Nom" required maxlength="200" />

            <x-admin.field name="description" label="Description" type="textarea" :rows="3" maxlength="2000" optional />

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    Créer l'offre
                </button>
                <a href="{{ route('admin.projects.plans.index', $projectId) }}"
                   class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
            </div>
        </form>
    @endif

    <div class="rounded-lg border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">
            @if ($version !== null)
                Corriger la version {{ $version->version }}
            @else
                Ajouter une version
            @endif
        </h2>
        <p class="mt-1 text-sm text-slate-600">
            @if ($version !== null)
                Une version publiée est figée : tant que celle-ci est <strong>brouillon</strong>, vous pouvez
                la corriger autant de fois qu'il le faut.
            @else
                Une version porte le prix, la période, l'essai, le délai de grâce et les fonctionnalités.
                Elle reste <strong>brouillon</strong> jusqu'à sa publication.
            @endif
        </p>

        <form method="POST"
              action="{{ $version !== null
                  ? route('admin.projects.plans.versions.update', [$projectId, $plan?->code, $version->version])
                  : ($plan !== null
                      ? route('admin.projects.plans.versions.store', [$projectId, $plan->code])
                      : route('admin.projects.plans.versions.add', $projectId)) }}"
              class="mt-4 space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-3">
                @if ($plan === null)
                    <x-admin.field name="plan_code" label="Offre (code)" required maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" class="font-mono" />
                @else
                    <div>
                        <span class="mb-1 block text-sm font-medium text-slate-700">Offre</span>
                        <p class="text-sm text-slate-900"><span class="font-mono">{{ $plan->code }}</span> — {{ $plan->name }}</p>
                    </div>
                @endif
                <x-admin.field name="price_minor" label="Prix (unités mineures)" type="number" min="0" required
                               :value="old('price_minor', $version?->priceMinor ?? 0)" />
                <x-admin.field name="currency" label="Devise" required minlength="3" maxlength="3" class="font-mono uppercase"
                               :value="old('currency', $version?->currency ?? 'XAF')" />
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-admin.field name="interval_unit" label="Unité" type="select"
                               :value="old('interval_unit', $version?->intervalUnit ?? 'month')"
                               :options="['day' => 'jour', 'week' => 'semaine', 'month' => 'mois', 'year' => 'an']" />
                <x-admin.field name="interval_count" label="Nombre" type="number" min="1" max="365" required
                               :value="old('interval_count', $version?->intervalCount ?? 1)" />
                <x-admin.field name="trial_days" label="Essai (jours)" type="number" min="0" max="365"
                               :value="old('trial_days', $version?->trialDays ?? 0)" />
                <x-admin.field name="grace_days" label="Grâce (jours)" type="number" min="0" max="90"
                               :value="old('grace_days', $version?->graceDays ?? 0)" />
            </div>

            {{-- Case décochée = rien n'est envoyé : le champ caché porte alors « 0 », sinon le
                 contrôleur retomberait sur « accès maintenu » (défaut #29). --}}
            <div>
                <input type="hidden" name="grace_access" value="0">
                <div class="flex items-center gap-2">
                    <input id="grace_access" name="grace_access" type="checkbox" value="1"
                           @checked(old('grace_access', $version?->graceAccess ?? true))
                           aria-describedby="grace_access-hint"
                           class="h-4 w-4 rounded border-slate-400">
                    <label for="grace_access" class="text-sm font-medium text-slate-800">Maintenir l'accès pendant la grâce</label>
                </div>
                <p id="grace_access-hint" class="mt-1 text-sm text-slate-600">
                    Décochée, les droits du client sont fermés dès l'impayé, sans attendre la fin du délai de grâce.
                </p>
            </div>

            {{-- Les fonctionnalités se déclarent bloc par bloc : jamais de JSON saisi.
                 Un bloc sans nom est vide et ignoré ; seul le réglage du type choisi
                 est lu (l'interrupteur, ou la limite et son compteur). --}}
            <fieldset class="space-y-3">
                <legend class="text-sm font-semibold text-slate-900">Fonctionnalités de la version</legend>
                <p class="text-sm text-slate-600">
                    Une fonctionnalité par bloc : un nom, un type, puis les réglages de ce type.
                    « Activée » ne compte que pour un interrupteur ; « Compteur », « Limite », « Remise »,
                    « Dépassement » et « Seuil d'alerte » ne comptent que pour une limite d'usage.
                    Un bloc sans nom est ignoré, et les noms sont ceux du projet, vérifiés par format.
                </p>

                @for ($index = 0; $index < max(3, count($counting), (int) old('features_rows', 0)); $index++)
                    @php($written = $counting[$index] ?? [])
                    <div class="space-y-3 rounded-md border border-slate-200 p-3">
                        <div class="grid gap-3 sm:grid-cols-2">
                            <x-admin.field :name="'features['.$index.'][name]'" label="Nom" maxlength="64" class="font-mono"
                                           :value="old('features['.$index.'][name]', $written['element'] ?? '')"
                                           hint="Minuscules, chiffres, « . » et « _ » (64 caractères au plus)." />
                            <x-admin.field :name="'features['.$index.'][type]'" label="Type" type="select" placeholder="Choisir le type"
                                           :value="old('features['.$index.'][type]', $written['type'] ?? '')"
                                           :options="['boolean' => 'Interrupteur : activée ou non', 'limit' => 'Limite d\'usage d\'un compteur']" />
                        </div>
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <x-admin.field :name="'features['.$index.'][enabled]'" label="Activée" type="select" placeholder="Choisir"
                                           :value="old('features['.$index.'][enabled]', ($written['enabled'] ?? null) === null ? '' : ($written['enabled'] ? '1' : '0'))"
                                           :options="['1' => 'Oui', '0' => 'Non']" />
                            <x-admin.field :name="'features['.$index.'][counter]'" label="Compteur" type="select" placeholder="Choisir un compteur"
                                           :options="$counterOptions"
                                           :value="old('features['.$index.'][counter]', $written['counter'] ?? '')"
                                           hint="Un compteur du projet : il se déclare dans Réglages du commerce → Limites d'usage." />
                            <x-admin.field :name="'features['.$index.'][limit]'" label="Limite" type="number" min="0"
                                           :value="old('features['.$index.'][limit]', $written['limit'] ?? '')"
                                           hint="Le nombre autorisé ; vide = illimité." />
                            <x-admin.field :name="'features['.$index.'][reset]'" label="Remise" type="select"
                                           :value="old('features['.$index.'][reset]', $written['reset'] ?? 'period')"
                                           :options="['period' => 'À chaque période', 'never' => 'Jamais']" />
                            <x-admin.field :name="'features['.$index.'][overage]'" label="Dépassement" type="select"
                                           :value="old('features['.$index.'][overage]', $written['overage'] ?? 'block')"
                                           :options="['block' => 'Bloquer', 'allow' => 'Autoriser et signaler']" />
                            <x-admin.field :name="'features['.$index.'][threshold_bps]'" label="Seuil d'alerte (points de base)" type="number" min="1" max="10000"
                                           :value="old('features['.$index.'][threshold_bps]', $written['alert'] ?? '')"
                                           hint="8000 = 80 % ; vide = pas d'alerte." />
                        </div>
                    </div>
                @endfor

                <button type="submit" name="add_row" value="features" formnovalidate
                        class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">
                    + Ajouter une fonctionnalité
                </button>
            </fieldset>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                    {{ $version !== null ? 'Enregistrer les corrections' : 'Ajouter la version' }}
                </button>
                <a href="{{ route('admin.projects.plans.index', $projectId) }}"
                   class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Retour aux offres</a>
            </div>
        </form>
    </div>
</div>
@endsection
