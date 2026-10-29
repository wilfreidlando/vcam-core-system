@extends('layouts.admin')

@section('title', 'Réglages du commerce')

@section('content')
<div class="max-w-3xl space-y-6">
    <form method="POST" action="{{ route('admin.projects.commerce-settings.save', $projectId) }}" class="space-y-6 rounded-lg border border-slate-200 bg-white p-6">
        @csrf

        <h2 class="text-base font-semibold text-slate-900">Facturation</h2>
        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label for="default_currency" class="block text-sm font-medium text-slate-700">Devise par défaut</label>
                <input id="default_currency" name="default_currency" required maxlength="3" value="{{ old('default_currency', $settings->defaultCurrency) }}"
                       class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono uppercase">
            </div>
            <div>
                <label for="timezone" class="block text-sm font-medium text-slate-700">Fuseau horaire des périodes</label>
                <input id="timezone" name="timezone" required value="{{ old('timezone', $settings->timezone) }}"
                       class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
            </div>
            <div>
                <label for="invoice_prefix" class="block text-sm font-medium text-slate-700">Préfixe des factures</label>
                <input id="invoice_prefix" name="invoice_prefix" required maxlength="12" pattern="[A-Z0-9]{2,12}" value="{{ old('invoice_prefix', $settings->invoicePrefix) }}"
                       class="mt-1 w-40 rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">
                <p class="mt-1 text-xs text-slate-500">Numérotation sans trou : <code>{{ $settings->invoicePrefix }}-2026-000001</code>.</p>
            </div>
            <div>
                <label for="default_tax_rate_percent" class="block text-sm font-medium text-slate-700">Taxe par défaut (%)</label>
                <input id="default_tax_rate_percent" name="default_tax_rate_percent" type="number" step="0.01" min="0" max="100"
                       value="{{ old('default_tax_rate_percent', $settings->defaultTaxRateBps / 100) }}"
                       class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="renewal_lead_days" class="block text-sm font-medium text-slate-700">Facture du renouvellement émise (jours avant)</label>
                <input id="renewal_lead_days" name="renewal_lead_days" type="number" min="0" max="90" required value="{{ old('renewal_lead_days', $settings->renewalLeadDays) }}"
                       class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label for="incomplete_expiry_hours" class="block text-sm font-medium text-slate-700">Délai pour payer la première facture (heures)</label>
                <input id="incomplete_expiry_hours" name="incomplete_expiry_hours" type="number" min="1" max="720" required value="{{ old('incomplete_expiry_hours', $settings->incompleteExpiryHours) }}"
                       class="mt-1 w-32 rounded-md border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="sm:col-span-2">
                <label for="manual_receipt_threshold" class="block text-sm font-medium text-slate-700">Seuil de validation d'un reçu hors ligne (unités mineures)</label>
                <input id="manual_receipt_threshold" name="manual_receipt_threshold" type="number" min="0" value="{{ old('manual_receipt_threshold', $settings->manualReceiptApprovalThresholdMinor) }}"
                       class="mt-1 w-48 rounded-md border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">Au-dessus, un reçu hors ligne attend une validation explicite. 0 = toujours.</p>
            </div>
        </div>

        <h2 class="text-base font-semibold text-slate-900 pt-2">Quotas</h2>
        <div>
            <label for="meter_mappings" class="block text-sm font-medium text-slate-700">Ce que la plateforme compte pour vous</label>
            <textarea id="meter_mappings" name="meter_mappings" rows="3" placeholder="notification.whatsapp = whatsapp_messages"
                      class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm font-mono">{{ old('meter_mappings', collect($settings->meterMappings)->map(fn ($meter, $capability) => $capability.' = '.$meter)->implode("\n")) }}</textarea>
            <p class="mt-1 text-xs text-slate-500">Une correspondance <code>capacité = compteur</code> par ligne. Sans correspondance, la capacité n'est jamais comptée.</p>
        </div>
        <div class="space-y-2">
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input type="checkbox" name="refund_failed_usage" value="1" @checked(old('refund_failed_usage', $settings->refundFailedUsage)) class="h-4 w-4 rounded border-slate-300">
                Rendre l'unité quand un message n'a atteint personne
            </label>
            <label class="flex items-center gap-2 text-sm text-slate-800">
                <input type="checkbox" name="require_customer_ref" value="1" @checked(old('require_customer_ref', $settings->requireCustomerRef)) class="h-4 w-4 rounded border-slate-300">
                Exiger <code>customer_ref</code> sur tout appel mesuré
            </label>
            <p class="text-xs text-slate-500">Conseillé dès qu'une offre publiée porte une limite : sans cela, un oubli de référence contourne le quota.</p>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-md bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Enregistrer</button>
            <a href="{{ route('admin.projects.show', $projectId) }}" class="text-sm font-medium text-slate-600 hover:text-slate-900">Retour au projet</a>
        </div>
    </form>
</div>
@endsection
