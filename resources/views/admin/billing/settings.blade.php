@extends('layouts.admin')

@section('title', 'Réglages du commerce')

@section('content')
<div class="max-w-3xl space-y-6">
    <form method="POST" action="{{ route('admin.projects.commerce-settings.save', $projectId) }}" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <h2 class="text-base font-semibold text-slate-900">Facturation</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <x-admin.field name="default_currency" label="Devise par défaut" required minlength="3" maxlength="3" :value="$settings->defaultCurrency" class="w-32 font-mono uppercase" />
            <x-admin.field name="timezone" label="Fuseau horaire des périodes" required :value="$settings->timezone" class="font-mono" />
            <x-admin.field name="invoice_prefix" label="Préfixe des factures" required maxlength="12" pattern="[A-Z0-9]{2,12}" :value="$settings->invoicePrefix" class="w-40 font-mono"
                           :hint="'Numérotation sans trou : '.$settings->invoicePrefix.'-2026-000001.'" />
            <x-admin.field name="default_tax_rate_percent" type="number" label="Taxe par défaut (%)" step="0.01" min="0" max="100" :value="$settings->defaultTaxRateBps / 100" class="w-32" />
            <x-admin.field name="renewal_lead_days" type="number" label="Facture du renouvellement émise (jours avant)" min="0" max="90" required :value="$settings->renewalLeadDays" class="w-32" />
            <x-admin.field name="incomplete_expiry_hours" type="number" label="Délai pour payer la première facture (heures)" min="1" max="720" required :value="$settings->incompleteExpiryHours" class="w-32" />
            <x-admin.field name="manual_receipt_threshold" type="number" label="Seuil de validation d'un reçu hors ligne (unités mineures)" min="0" :value="$settings->manualReceiptApprovalThresholdMinor" class="w-48"
                           hint="Au-dessus, un reçu hors ligne attend une validation explicite. 0 = toujours." wrapper-class="sm:col-span-2" />
        </div>

        <h2 class="text-base font-semibold text-slate-900 pt-2">Limites d'usage</h2>
        {{-- Les correspondances se déclarent bloc par bloc : jamais de texte à parser.
             Un bloc incomplet (une seule des deux cases) est ignoré. --}}
        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold text-slate-900">Ce que la plateforme compte pour vous</legend>
            <p class="text-sm text-slate-600">
                Une correspondance par bloc : ce que vos clients consomment, et le compteur que la
                plateforme compte. Sans correspondance, une capacité n'est jamais comptée.
            </p>

            @for ($index = 0; $index < max(3, count($settings->meterMappings)); $index++)
                <div class="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-2">
                    <x-admin.field :name="'meter_mappings['.$index.'][capability]'" label="Capacité" maxlength="64" class="font-mono"
                                   :value="array_keys($settings->meterMappings)[$index] ?? ''"
                                   hint="Ce que le client consomme, par exemple notification.whatsapp." />
                    <x-admin.field :name="'meter_mappings['.$index.'][meter]'" label="Compteur" maxlength="64" class="font-mono"
                                   :value="array_values($settings->meterMappings)[$index] ?? ''"
                                   hint="Ce que la plateforme compte, par exemple whatsapp_messages." />
                </div>
            @endfor
        </fieldset>
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <input id="refund_failed_usage" type="checkbox" name="refund_failed_usage" value="1" @checked(old('refund_failed_usage', $settings->refundFailedUsage)) class="h-4 w-4 rounded border border-slate-400">
                <label for="refund_failed_usage" class="text-sm text-slate-800">Rendre l'unité quand un message n'a atteint personne</label>
            </div>
            <div class="flex items-center gap-2">
                <input id="require_customer_ref" type="checkbox" name="require_customer_ref" value="1" aria-describedby="require_customer_ref-hint" @checked(old('require_customer_ref', $settings->requireCustomerRef)) class="h-4 w-4 rounded border border-slate-400">
                <label for="require_customer_ref" class="text-sm text-slate-800">Exiger <code>customer_ref</code> sur tout appel mesuré</label>
            </div>
            <p id="require_customer_ref-hint" class="text-sm text-slate-600">Conseillé dès qu'une offre publiée porte une limite : sans cela, un oubli de référence contourne la limite.</p>
        </div>

        <h2 class="text-base font-semibold text-slate-900 pt-2">Packs de rechargement</h2>
        <p class="text-sm text-slate-600">
            Ce que vos clients peuvent acheter <strong>en plus</strong> de leur limite d'usage, sans renouveler
            leur abonnement. Un pack par bloc : un code, le compteur concerné, une quantité, un prix et un libellé.
        </p>
        {{-- Un bloc de cinq champs par pack ; un bloc entièrement vide est ignoré. --}}
        <fieldset class="space-y-3">
            <legend class="text-sm font-semibold text-slate-900">Catalogue de packs</legend>

            @for ($index = 0; $index < max(3, count($settings->supplementPacks)); $index++)
                <div class="grid gap-3 rounded-md border border-slate-200 p-3 sm:grid-cols-2 lg:grid-cols-3">
                    <x-admin.field :name="'supplement_packs['.$index.'][code]'" label="Code" maxlength="64" class="font-mono"
                                   :value="$settings->supplementPacks[$index]->code ?? ''"
                                   hint="Minuscules, chiffres, « _ » et « - » (64 caractères au plus)." />
                    <x-admin.field :name="'supplement_packs['.$index.'][meter]'" label="Compteur" maxlength="64" class="font-mono"
                                   :value="$settings->supplementPacks[$index]->meter ?? ''"
                                   hint="L'un des compteurs déclarés plus haut." />
                    <x-admin.field :name="'supplement_packs['.$index.'][quantity]'" label="Quantité" type="number" min="1"
                                   :value="$settings->supplementPacks[$index]->quantity ?? ''" />
                    <x-admin.field :name="'supplement_packs['.$index.'][unit_price_minor]'" label="Prix (unités mineures)" type="number" min="0"
                                   :value="$settings->supplementPacks[$index]->unitPriceMinor ?? ''"
                                   hint="En unités mineures de la devise du projet (pour XAF : des francs, sans décimales)." />
                    <x-admin.field :name="'supplement_packs['.$index.'][label]'" label="Libellé" maxlength="120"
                                   :value="$settings->supplementPacks[$index]->label ?? ''"
                                   wrapper-class="sm:col-span-2 lg:col-span-3" />
                </div>
            @endfor
        </fieldset>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Enregistrer</button>
            <a href="{{ route('admin.projects.show', $projectId) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Retour au projet</a>
        </div>
    </form>

    <form method="POST" action="{{ route('admin.projects.system-notifications.save', $projectId) }}" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <h2 class="text-base font-semibold text-slate-900">Notifications système</h2>
        <p class="text-sm text-slate-600">
            Le Core prévient <strong>vos clients</strong> — par WhatsApp, depuis la session de la plateforme,
            et en son propre nom — quand un événement de commerce se produit. Sans déclaration ci-dessous,
            rien n'est envoyé : un même événement n'est notifié qu'une fois par client et par période.
        </p>
        <div class="space-y-2">
            @foreach ($noticeEvents as $notice)
                @php($noticeId = 'notice-'.str_replace('.', '-', $notice['event']))
                <div class="flex items-center gap-2">
                    <input id="{{ $noticeId }}" type="checkbox" name="events[]" value="{{ $notice['event'] }}" @checked($notice['checked']) class="h-4 w-4 rounded border-slate-400">
                    <label for="{{ $noticeId }}" class="text-sm text-slate-800">{{ $notice['label'] }}</label>
                </div>
            @endforeach
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Enregistrer</button>
        </div>
    </form>
</div>
@endsection
