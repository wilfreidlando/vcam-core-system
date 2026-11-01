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
            <x-admin.field name="display_name" label="Nom affiché" required maxlength="80" :value="$settings->displayName" wrapper-class="sm:col-span-2" />
            @php $brandColor = (string) old('primary_color', $settings->primaryColor); @endphp
            <x-admin.field name="primary_color" label="Couleur de la marque" optional maxlength="7" pattern="#[0-9a-fA-F]{6}" :value="$settings->primaryColor" class="w-32 font-mono"
                           hint="Format #rrggbb.">
                <div class="flex items-center gap-3">
                    <input id="field-primary_color" name="primary_color" maxlength="7" pattern="#[0-9a-fA-F]{6}" value="{{ $brandColor }}"
                           aria-describedby="field-primary_color-hint{{ $errors->has('primary_color') ? ' field-primary_color-error' : '' }}"
                           @if ($errors->has('primary_color')) aria-invalid="true" @endif
                           class="w-32 rounded-md border {{ $errors->has('primary_color') ? 'border-rose-600' : 'border-slate-300' }} px-3 py-2 text-base font-mono">
                    <span class="inline-block h-8 w-8 rounded-md border border-slate-300" aria-hidden="true" style="background: {{ preg_match('/^#[0-9a-fA-F]{6}$/', $brandColor) ? $brandColor : '#2563eb' }}"></span>
                </div>
            </x-admin.field>
            <x-admin.field name="locale" label="Langue" optional maxlength="5" :value="$settings->locale" class="w-32 font-mono" />
            <x-admin.field name="logo_url" label="Logo (URL https)" optional maxlength="500" :value="$settings->logoUrl" wrapper-class="sm:col-span-2" />
            <x-admin.field name="support_url" label="Aide (URL https ou mailto:)" optional maxlength="500" :value="$settings->supportUrl" wrapper-class="sm:col-span-2" />
        </div>

        <x-admin.field name="allowed_return_urls" type="textarea" :rows="3" label="Adresses de retour autorisées" optional class="font-mono"
                       :value="implode(PHP_EOL, $settings->allowedReturnUrls)"
                       hint="Une par ligne : origine exacte, avec un préfixe de chemin facultatif. Toute autre adresse de retour est refusée." />

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Ce qu'un client peut faire seul</legend>
            @foreach ([
                'allow_plan_change' => 'Souscrire, changer d\'offre et renouveler',
                'allow_self_cancel' => 'Résilier à la fin de la période (et reprendre le renouvellement)',
            ] as $field => $label)
                @php $current = $field === 'allow_plan_change' ? $settings->allowPlanChange : $settings->allowSelfCancel; @endphp
                <div class="flex items-center gap-2">
                    <input id="{{ $field }}" type="checkbox" name="{{ $field }}" value="1" @checked(old($field, $current))
                           class="h-4 w-4 rounded border border-slate-400">
                    <label for="{{ $field }}" class="text-sm text-slate-800">{{ $label }}</label>
                </div>
            @endforeach
        </fieldset>

        <fieldset class="space-y-3">
            <legend class="text-sm font-medium text-slate-700">Connexion directe par code <span class="font-normal text-slate-600">(désactivée par défaut)</span></legend>
            <p class="text-sm text-slate-600">
                Pour les clients qui arrivent sans passer par votre application : ils saisissent leur numéro sur
                <code>/portal/{{ $projectId }}/login</code> et reçoivent un code à 6 chiffres par WhatsApp (valable 10 minutes,
                5 essais). Cela n'ouvre que le portail, jamais votre application.
            </p>
            <div class="flex items-center gap-2">
                <input id="direct_login_enabled" type="checkbox" name="direct_login_enabled" value="1" @checked(old('direct_login_enabled', $settings->directLoginEnabled))
                       class="h-4 w-4 rounded border border-slate-400">
                <label for="direct_login_enabled" class="text-sm text-slate-800">Proposer la connexion par code</label>
            </div>
            <x-admin.field name="direct_login_session" label="Session WhatsApp d'envoi" optional maxlength="64" pattern="[A-Za-z0-9_\-]+" :value="$settings->directLoginSession" class="w-64 font-mono" placeholder="ecole"
                           hint="Le nom d'une session de ce projet (« Sessions WhatsApp ») ; obligatoire pour activer." />
        </fieldset>

        <fieldset class="space-y-2">
            <legend class="text-sm font-medium text-slate-700">Restreindre les moyens de paiement <span class="font-normal text-slate-600">(facultatif)</span></legend>
            <p class="text-sm text-slate-600">Rien de coché : le portail propose tous les canaux que la plateforme sait traiter pour votre projet.</p>
            @foreach (['mtn_momo' => 'MTN Mobile Money', 'orange_money' => 'Orange Money', 'card' => 'Carte bancaire'] as $channel => $label)
                <div class="flex items-center gap-2">
                    <input id="payment_channel_{{ $channel }}" type="checkbox" name="payment_channels[]" value="{{ $channel }}" @checked(in_array($channel, (array) old('payment_channels', $settings->paymentChannels), true))
                           class="h-4 w-4 rounded border border-slate-400">
                    <label for="payment_channel_{{ $channel }}" class="text-sm text-slate-800">{{ $label }}</label>
                </div>
            @endforeach
        </fieldset>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Enregistrer</button>
            <a href="{{ route('admin.projects.show', $projectId) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Retour au projet</a>
        </div>
    </form>
</div>
@endsection
