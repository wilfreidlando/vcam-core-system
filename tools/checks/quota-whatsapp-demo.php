<?php

declare(strict_types=1);

use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Support\Access\AccessScope;

/*
 * Déclare la correspondance « capacité → compteur » du projet de
 * démonstration, pour que les messages WhatsApp soient comptés.
 *
 * ADR-0074 §5 : le projet déclare quelle capacité du Core alimente quel
 * compteur. Sans cette ligne, `notification.whatsapp` n'est pas mesuré :
 * le quota du plan n'est pas appliqué et l'application cliente ne peut pas
 * lire le nombre de messages restants.
 *
 * C'est une donnée de projet, pas une donnée de code : le même geste se
 * fait à la main dans la console (Réglages du commerce → « Ce que la
 * plateforme compte pour vous »). Ce script ne fait que ce geste en ligne
 * de commande, sur le projet qui porte le client de démonstration.
 *
 *   docker compose exec app php artisan tinker --execute="require 'tools/checks/quota-whatsapp-demo.php';"
 */

$projet = Illuminate\Support\Facades\DB::table('customers')
    ->where('external_ref', 'ae0000')
    ->orderByDesc('created_at')
    ->value('project_id');

if (! is_string($projet)) {
    echo "Aucun client « ae0000 » : rien à faire.\n";

    return null;
}

$reglages = app(ManageBillingSettings::class)->get($projet, AccessScope::platform());

if ($reglages === null) {
    throw new RuntimeException('Les réglages du projet sont introuvables.');
}

$correspondances = array_merge(
    $reglages->meterMappings,
    ['notification.whatsapp' => 'whatsapp_messages'],
);

app(ManageBillingSettings::class)->save(new SaveBillingSettingsCommand(
    projectId: $projet,
    timezone: $reglages->timezone,
    defaultCurrency: $reglages->defaultCurrency,
    renewalLeadDays: $reglages->renewalLeadDays,
    incompleteExpiryHours: $reglages->incompleteExpiryHours,
    invoicePrefix: $reglages->invoicePrefix,
    defaultTaxRateBps: $reglages->defaultTaxRateBps,
    manualReceiptApprovalThresholdMinor: $reglages->manualReceiptApprovalThresholdMinor,
    meterMappings: $correspondances,
    refundFailedUsage: $reglages->refundFailedUsage,
    requireCustomerRef: $reglages->requireCustomerRef,
    actor: 'demo',
), AccessScope::platform());

echo "Projet {$projet} : notification.whatsapp = whatsapp_messages déclaré.\n";

return $projet;
