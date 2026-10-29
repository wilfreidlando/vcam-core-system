@extends('layouts.admin')

@section('title', 'Nouveau plan')

@section('content')
{{-- Les erreurs de validation sont listées une fois par le layout (role="alert") ;
     chaque champ reprend son message (aria-describedby, aria-invalid). Dans chaque
     champ, old() passe AVANT la valeur par défaut : la saisie de l'opérateur survit
     à un refus du serveur. --}}
<div class="max-w-2xl space-y-6">
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
                Créer le plan
            </button>
            <a href="{{ route('admin.projects.plans.index', $projectId) }}"
               class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
        </div>
    </form>

    <div class="rounded-lg border border-slate-200 bg-white p-6">
        <h2 class="text-base font-semibold text-slate-900">Ajouter une version</h2>
        <p class="mt-1 text-sm text-slate-600">
            Le plan doit exister : une version porte le prix, la période, l'essai, le délai
            de grâce et les fonctionnalités. Elle reste <strong>brouillon</strong> jusqu'à sa
            publication.
        </p>

        <form method="POST" action="{{ route('admin.projects.plans.versions.add', $projectId) }}"
              class="mt-4 space-y-4">
            @csrf
            <div class="grid gap-4 sm:grid-cols-3">
                <x-admin.field name="plan_code" label="Plan (code)" required maxlength="64" pattern="[a-z0-9][a-z0-9_\-]*" class="font-mono" />
                <x-admin.field name="price_minor" label="Prix (unités mineures)" type="number" min="0" required :value="0" />
                <x-admin.field name="currency" label="Devise" required minlength="3" maxlength="3" class="font-mono uppercase" value="XAF" />
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <x-admin.field name="interval_unit" label="Unité" type="select" value="month"
                               :options="['day' => 'jour', 'week' => 'semaine', 'month' => 'mois', 'year' => 'an']" />
                <x-admin.field name="interval_count" label="Nombre" type="number" min="1" max="365" required :value="1" />
                <x-admin.field name="trial_days" label="Essai (jours)" type="number" min="0" max="365" :value="0" />
                <x-admin.field name="grace_days" label="Grâce (jours)" type="number" min="0" max="90" :value="0" />
            </div>

            {{-- Case décochée = rien n'est envoyé : le champ caché porte alors « 0 », sinon le
                 contrôleur retomberait sur « accès maintenu » (défaut #29). --}}
            <div>
                <input type="hidden" name="grace_access" value="0">
                <div class="flex items-center gap-2">
                    <input id="grace_access" name="grace_access" type="checkbox" value="1"
                           @checked(old('grace_access', true))
                           aria-describedby="grace_access-hint"
                           class="h-4 w-4 rounded border border-slate-400">
                    <label for="grace_access" class="text-sm font-medium text-slate-800">Maintenir l'accès pendant la grâce</label>
                </div>
                <p id="grace_access-hint" class="mt-1 text-sm text-slate-600">
                    Décochée, les droits du client sont fermés dès l'impayé, sans attendre la fin du délai de grâce.
                </p>
            </div>

            <x-admin.field name="features" label="Fonctionnalités (JSON)" type="textarea" :rows="4" optional
                           placeholder='{"api_access": {"type": "boolean", "enabled": true}}' class="font-mono text-sm"
                           hint="Les noms sont ceux du projet, validés par format — le Core n'en connaît aucun." />

            <button type="submit"
                    class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">
                Ajouter la version
            </button>
        </form>
    </div>
</div>
@endsection
