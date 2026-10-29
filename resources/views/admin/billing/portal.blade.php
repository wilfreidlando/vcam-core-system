@extends('layouts.admin')

@section('title', 'Portail client')

@section('content')
<div class="max-w-3xl space-y-6">
    <div class="rounded-lg border border-indigo-100 bg-indigo-50 px-4 py-3.5 text-sm text-indigo-900 leading-relaxed">
        <p class="font-semibold mb-1">L'espace de vos clients, à vos couleurs</p>
        <p>
            Vos clients y suivent leur abonnement, paient leurs factures et consultent leur utilisation. Le paiement
            utilise les canaux de la plateforme : <strong>vous n'avez aucun prestataire à configurer</strong>.
            Ouvrez-le pour un client avec <code>POST /v1/portal/sessions</code>.
        </p>
    </div>

    <form method="POST" action="{{ route('admin.projects.portal.save', $projectId) }}" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <label for="display_name" class="block text-sm font-medium text-slate-700">Nom affiché</label>
                <input id="display_name" name="display_name" required maxlength="80" value="{{ old('display_name', $settings->displayName) }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="primary_color" class="block text-sm font-medium text-slate-700">Couleur de la marque</label>
                <div class="mt-1 flex items-center gap-3">
                    <input id="primary_color" name="primary_color" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="{{ old('primary_color', $settings->primaryColor) }}"
                           class="w-32 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                    <span class="inline-block h-8 w-8 rounded-md border border-slate-200" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', (string) old('primary_color', $settings->primaryColor)) ? old('primary_color', $settings->primaryColor) : '#2563eb' }}"></span>
                </div>
                <p class="mt-1 text-xs text-slate-500">Format <code>#rrggbb</code>.</p>
            </div>
            <div>
                <label for="locale" class="block text-sm font-medium text-slate-700">Langue</label>
                <input id="locale" name="locale" maxlength="5" value="{{ old('locale', $settings->locale) }}"
                       class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
            </div>
            <div class="sm:col-span-2">
                <label for="logo_url" class="block text-sm font-medium text-slate-700">Logo (URL https)</label>
                <input id="logo_url" name="logo_url" maxlength="500" value="{{ old('logo_url', $settings->logoUrl) }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label for="support_url" class="block text-sm font-medium text-slate-700">Aide (URL https ou <code>mailto:</code>)</label>
                <input id="support_url" name="support_url" maxlength="500" value="{{ old('support_url', $settings->supportUrl) }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
        </div>

        <div>
            <label for="allowed_return_urls" class="block text-sm font-medium text-slate-700">Adresses de retour autorisées</label>
            <textarea id="allowed_return_urls" name="allowed_return_urls" rows="3"
                      class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">{{ old('allowed_return_urls', implode("\n", $settings->allowedReturnUrls)) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Une par ligne : origine exacte, avec un préfixe de chemin facultatif. Tout autre <code>return_url</code> est refusé.</p>
        </div>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Ce qu'un client peut faire seul</legend>
            @foreach ([
                'allow_plan_change' => 'Souscrire, changer d\'offre et renouveler',
                'allow_self_cancel' => 'Résilier à la fin de la période (et reprendre le renouvellement)',
            ] as $field => $label)
                @php $current = $field === 'allow_plan_change' ? $settings->allowPlanChange : $settings->allowSelfCancel; @endphp
                <label class="flex items-center gap-2 text-sm text-slate-800">
                    <input type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $current))
                           class="h-4 w-4 rounded border-slate-300">
                    {{ $label }}
                </label>
            @endforeach
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-slate-700">Connexion directe par code <span class="font-normal text-slate-500">(désactivée par défaut)</span></legend>
            <p class="text-xs text-slate-500">
                Pour les clients qui arrivent sans passer par votre application : ils saisissent leur numéro sur
                <code>/portal/{{ $projectId }}/login</code> et reçoivent un code à 6 chiffres par WhatsApp (valable 10 minutes,
                5 essais). Cela n'ouvre que le portail, jamais votre application.
            </p>
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input type="checkbox" name="direct_login_enabled" value="1" @checked(old('direct_login_enabled', $settings->directLoginEnabled))
                       class="h-4 w-4 rounded border-slate-300">
                Proposer la connexion par code
            </label>
            <div>
                <label for="direct_login_session" class="block text-sm font-medium text-slate-700">Session WhatsApp d'envoi</label>
                <input id="direct_login_session" name="direct_login_session" maxlength="64" pattern="[A-Za-z0-9_\-]+" value="{{ old('direct_login_session', $settings->directLoginSession) }}"
                       class="mt-1 w-64 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono" placeholder="ecole">
                <p class="mt-1 text-xs text-slate-500">Le nom d'une session de ce projet (« Sessions WhatsApp ») ; obligatoire pour activer.</p>
            </div>
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Restreindre les moyens de paiement <span class="font-normal text-slate-500">(facultatif)</span></legend>
            <p class="text-xs text-slate-500">Rien de coché : le portail propose tous les canaux que la plateforme sait traiter pour votre projet.</p>
            @foreach (['mtn_momo' => 'MTN Mobile Money', 'orange_money' => 'Orange Money', 'card' => 'Carte bancaire'] as $channel => $label)
                <label class="flex items-center gap-2 text-sm text-slate-800">
                    <input type="checkbox" name="payment_channels[]" value="{{ $channel }}" @checked(in_array($channel, (array) old('payment_channels', $settings->paymentChannels), true))
                           class="h-4 w-4 rounded border-slate-300">
                    {{ $label }}
                </label>
            @endforeach
        </fieldset>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Enregistrer</button>
            <a href="{{ route('admin.projects.show', $projectId) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">Retour au projet</a>
        </div>
    </form>
</div>
@endsection
