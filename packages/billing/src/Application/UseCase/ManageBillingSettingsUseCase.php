<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Contracts\BillingSettingsView;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Support\Access\AccessScope;

/**
 * The commerce settings of a project, written from the console.
 *
 * The schema holds no rule (the owner's choice), so **every** value is
 * checked here before it is stored: a wrong prefix or a negative lead is
 * refused with a sentence, never written. The two settings the console does
 * not edit (`retain_invoice_identity`, `document_branding`) are carried over
 * untouched. The audit of the gesture is the console's (`billing` may not
 * write to `audit`, ADR-0002).
 */
final readonly class ManageBillingSettingsUseCase implements ManageBillingSettings
{
    public function __construct(
        private BillingSettingsRepository $settings,
    ) {}

    public function get(string $projectId, AccessScope $scope): ?BillingSettingsView
    {
        return $scope->allowsProject($projectId) ? $this->view($this->settings->find($projectId)) : null;
    }

    public function save(SaveBillingSettingsCommand $c, AccessScope $scope): ?BillingSettingsView
    {
        if (! $scope->allowsProject($c->projectId)) {
            return null;
        }

        $this->assertValid($c);

        $before = $this->settings->find($c->projectId);

        $next = new BillingSettings(
            projectId: $c->projectId,
            timezone: $c->timezone,
            defaultCurrency: strtoupper($c->defaultCurrency),
            renewalLeadDays: $c->renewalLeadDays,
            incompleteExpiryHours: $c->incompleteExpiryHours,
            invoicePrefix: $c->invoicePrefix,
            defaultTaxRateBps: $c->defaultTaxRateBps,
            manualReceiptApprovalThresholdMinor: $c->manualReceiptApprovalThresholdMinor,
            retainInvoiceIdentity: $before->retainInvoiceIdentity,
            documentBranding: $before->documentBranding,
            meterMappings: $c->meterMappings,
            refundFailedUsage: $c->refundFailedUsage,
            requireCustomerRef: $c->requireCustomerRef,
        );

        $next->zone(); // an unknown timezone is refused here, not at the first renewal

        $this->settings->save($next);

        return $this->view($next);
    }

    private function assertValid(SaveBillingSettingsCommand $c): void
    {
        $rules = [
            [preg_match('/^[A-Z]{3}$/', strtoupper($c->defaultCurrency)) === 1, 'La devise doit comporter trois lettres (XAF, EUR…).'],
            [preg_match('/^[A-Z0-9]{2,12}$/', $c->invoicePrefix) === 1, 'Le préfixe de facture doit comporter 2 à 12 lettres majuscules ou chiffres.'],
            [$c->renewalLeadDays >= 0 && $c->renewalLeadDays <= 90, 'L\'avance de renouvellement va de 0 à 90 jours.'],
            [$c->incompleteExpiryHours >= 1 && $c->incompleteExpiryHours <= 720, 'Le délai de première facture va de 1 à 720 heures.'],
            [$c->defaultTaxRateBps >= 0 && $c->defaultTaxRateBps <= 10000, 'Le taux de taxe va de 0 à 100 %.'],
            [$c->manualReceiptApprovalThresholdMinor >= 0, 'Le seuil de validation ne peut pas être négatif.'],
            [count($c->meterMappings) <= 50, 'Au plus 50 correspondances capacité → compteur.'],
        ];

        foreach ($rules as [$ok, $message]) {
            if (! $ok) {
                throw InvalidBillingSettings::because($message);
            }
        }

        foreach ($c->meterMappings as $capability => $meter) {
            if (preg_match('/^[a-z0-9_.]{1,64}$/', $capability) !== 1 || preg_match('/^[a-z0-9_.]{1,64}$/', $meter) !== 1) {
                throw InvalidBillingSettings::because('Capacité et compteur : minuscules, chiffres, « _ » et « . » (64 caractères au plus).');
            }
        }
    }

    private function view(BillingSettings $s): BillingSettingsView
    {
        return new BillingSettingsView(
            $s->timezone,
            $s->defaultCurrency,
            $s->renewalLeadDays,
            $s->incompleteExpiryHours,
            $s->invoicePrefix,
            $s->defaultTaxRateBps,
            $s->manualReceiptApprovalThresholdMinor,
            $s->meterMappings,
            $s->refundFailedUsage,
            $s->requireCustomerRef,
        );
    }
}
