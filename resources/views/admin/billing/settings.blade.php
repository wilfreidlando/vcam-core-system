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

        <h2 class="text-base font-semibold text-slate-900 pt-2">Quotas</h2>
        <x-admin.field name="meter_mappings" type="textarea" :rows="3" label="Ce que la plateforme compte pour vous"
                       :value="collect($settings->meterMappings)->map(fn ($meter, $capability) => $capability.' = '.$meter)->implode(PHP_EOL)"
                       placeholder="notification.whatsapp = whatsapp_messages" class="font-mono"
                       hint="Une correspondance « capacité = compteur » par ligne. Sans correspondance, la capacité n'est jamais comptée." />
        <div class="space-y-2">
            <div class="flex items-center gap-2">
                <input id="refund_failed_usage" type="checkbox" name="refund_failed_usage" value="1" @checked(old('refund_failed_usage', $settings->refundFailedUsage)) class="h-4 w-4 rounded border border-slate-400">
                <label for="refund_failed_usage" class="text-sm text-slate-800">Rendre l'unité quand un message n'a atteint personne</label>
            </div>
            <div class="flex items-center gap-2">
                <input id="require_customer_ref" type="checkbox" name="require_customer_ref" value="1" aria-describedby="require_customer_ref-hint" @checked(old('require_customer_ref', $settings->requireCustomerRef)) class="h-4 w-4 rounded border border-slate-400">
                <label for="require_customer_ref" class="text-sm text-slate-800">Exiger <code>customer_ref</code> sur tout appel mesuré</label>
            </div>
            <p id="require_customer_ref-hint" class="text-sm text-slate-600">Conseillé dès qu'une offre publiée porte une limite : sans cela, un oubli de référence contourne le quota.</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-indigo-700 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-800">Enregistrer</button>
            <a href="{{ route('admin.projects.show', $projectId) }}" class="inline-block px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-900">Retour au projet</a>
        </div>
    </form>
</div>
@endsection
