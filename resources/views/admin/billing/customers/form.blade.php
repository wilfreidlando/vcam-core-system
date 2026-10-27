@extends('layouts.admin')

@section('title', $customer === null ? 'Nouveau client' : 'Modifier le client')

@section('content')
<div class="max-w-2xl space-y-6">
    <form method="POST"
          action="{{ $customer === null
              ? route('admin.projects.customers.store', $projectId)
              : route('admin.projects.customers.update.web', [$projectId, $customer->externalRef]) }}"
          class="space-y-4 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        @if ($customer !== null)
            <input type="hidden" name="version" value="{{ $customer->version }}">
        @endif

        <div>
            <label for="external_ref" class="block text-sm font-medium text-slate-700">Référence chez le projet</label>
            <input id="external_ref" name="external_ref" required
                   value="{{ $customer?->externalRef ?? old('external_ref') }}"
                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm{{ $customer !== null ? ' bg-slate-100' : '' }}"
                   {{ $customer !== null ? 'readonly' : '' }}>
            <p class="mt-1 text-xs text-slate-500">
                L'identifiant opaque que le projet connaît : lettres, chiffres et
                <code>. _ : -</code>.
            </p>
        </div>

        <div>
            <label for="display_name" class="block text-sm font-medium text-slate-700">Nom affiché</label>
            <input id="display_name" name="display_name" required maxlength="200"
                   value="{{ $customer?->displayName ?? old('display_name') }}"
                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            {{-- ADR-0083 §3 — the console never shows personal data in
                 clear: the value is masked, and the field starts empty.
                 Leaving it empty keeps the stored value; filling it
                 replaces it. Unmasking is an audited action, and the Core
                 does not offer one in this lot. --}}
            <div>
                <label for="email" class="block text-sm font-medium text-slate-700">Contact (courriel)</label>
                <input id="email" name="email" type="email" maxlength="255"
                       value="{{ old('email') }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">
                    Chiffré au repos, jamais affiché en clair en console.
                    @if ($customer !== null && $customer->email !== null)
                        <span class="block">Valeur actuelle : {{ \Core\Support\Privacy\PersonalDataMask::email($customer->email) }} — laissez le champ vide pour la conserver.</span>
                    @endif
                </p>
            </div>
            <div>
                <label for="phone" class="block text-sm font-medium text-slate-700">Téléphone</label>
                <input id="phone" name="phone" maxlength="32"
                       value="{{ old('phone') }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
                @if ($customer !== null && $customer->phone !== null)
                    <p class="mt-1 text-xs text-slate-500">
                        Valeur actuelle : {{ \Core\Support\Privacy\PersonalDataMask::phone($customer->phone) }} — laissez le champ vide pour la conserver.
                    </p>
                @endif
            </div>
        </div>

        <div>
            <label for="locale" class="block text-sm font-medium text-slate-700">Langue</label>
            <input id="locale" name="locale" maxlength="16"
                   value="{{ $customer?->locale ?? old('locale', 'fr') }}"
                   class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit"
                    class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">
                {{ $customer === null ? 'Déclarer le client' : 'Enregistrer' }}
            </button>
            <a href="{{ route('admin.projects.customers.index', $projectId) }}"
               class="text-sm font-medium text-slate-600 hover:text-slate-900">Annuler</a>
        </div>
    </form>
</div>
@endsection
