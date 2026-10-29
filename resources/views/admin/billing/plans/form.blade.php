@extends('layouts.admin')

@section('title', 'Nouveau plan')

@section('content')
<div class="max-w-2xl space-y-6">
    <form method="POST" action="{{ route('admin.projects.plans.store', $projectId) }}"
          class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="code" class="block text-sm font-medium text-slate-700">Code</label>
                <input id="code" name="code" required maxlength="64" pattern="[a-z0-9][a-z0-9_-]*"
                       value="{{ old('code') }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                <p class="mt-1 text-xs text-slate-500">Stable dans le temps : minuscules, chiffres, <code>-</code> et <code>_</code>.</p>
            </div>
            <div>
                <label for="visibility" class="block text-sm font-medium text-slate-700">Visibilité</label>
                <select id="visibility" name="visibility"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                    <option value="public">Proposé publiquement</option>
                    <option value="private">Attribué seulement</option>
                </select>
            </div>
        </div>

        <div>
            <label for="name" class="block text-sm font-medium text-slate-700">Nom</label>
            <input id="name" name="name" required maxlength="200" value="{{ old('name') }}"
                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div>
            <label for="description" class="block text-sm font-medium text-slate-700">Description</label>
            <textarea id="description" name="description" rows="3" maxlength="2000"
                      class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">{{ old('description') }}</textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Créer le plan
            </button>
            <a href="{{ route('admin.projects.plans.index', $projectId) }}"
               class="text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
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
                <div>
                    <label for="plan_code" class="block text-sm font-medium text-slate-700">Plan (code)</label>
                    <input id="plan_code" name="plan_code" required
                           value="{{ old('code', '') }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
                <div>
                    <label for="price_minor" class="block text-sm font-medium text-slate-700">Prix (unités mineures)</label>
                    <input id="price_minor" name="price_minor" type="number" min="0" required
                           value="{{ old('price_minor', 0) }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="currency" class="block text-sm font-medium text-slate-700">Devise</label>
                    <input id="currency" name="currency" size="3" required value="{{ old('currency', 'XAF') }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div>
                    <label for="interval_unit" class="block text-sm font-medium text-slate-700">Unité</label>
                    <select id="interval_unit" name="interval_unit"
                            class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                        <option value="day">jour</option>
                        <option value="week">semaine</option>
                        <option value="month" selected>mois</option>
                        <option value="year">an</option>
                    </select>
                </div>
                <div>
                    <label for="interval_count" class="block text-sm font-medium text-slate-700">Nombre</label>
                    <input id="interval_count" name="interval_count" type="number" min="1" max="365" required
                           value="{{ old('interval_count', 1) }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="trial_days" class="block text-sm font-medium text-slate-700">Essai (jours)</label>
                    <input id="trial_days" name="trial_days" type="number" min="0" max="365"
                           value="{{ old('trial_days', 0) }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label for="grace_days" class="block text-sm font-medium text-slate-700">Grâce (jours)</label>
                    <input id="grace_days" name="grace_days" type="number" min="0" max="90"
                           value="{{ old('grace_days', 0) }}"
                           class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label for="features" class="block text-sm font-medium text-slate-700">Fonctionnalités (JSON)</label>
                <textarea id="features" name="features" rows="4" placeholder='{"api_access": {"type": "boolean", "enabled": true}}'
                          class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 font-mono text-xs">{{ old('features', '') }}</textarea>
                <p class="mt-1 text-xs text-slate-500">
                    Les noms sont ceux du projet, validés par format — le Core n'en connaît aucun.
                </p>
            </div>

            <button type="submit"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                Ajouter la version
            </button>
        </form>
    </div>
</div>
@endsection
