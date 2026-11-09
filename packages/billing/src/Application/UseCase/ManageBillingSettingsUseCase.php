<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\PlanDirectory;
use Core\Billing\Application\Port\PlanVersionDirectory;
use Core\Billing\Contracts\BillingSettingsView;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Contracts\SupplementPackView;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Billing\Domain\Model\BillingSettings;
use Core\Billing\Domain\Model\SupplementPack;
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
 *
 * ADR-0119 §1 — the **catalogue of packs** lives here too, and follows the
 * same rule: the packs are carried over untouched when the command does
 * not carry them, so a settings save from the console never empties the
 * catalogue.
 */
final readonly class ManageBillingSettingsUseCase implements ManageBillingSettings
{
    public function __construct(
        private BillingSettingsRepository $settings,
        private PlanDirectory $plans,
        private PlanVersionDirectory $versions,
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

        // ADR-0119 §2 — les packs sont tarifés dans la devise du projet :
        // changer la devise renommerait leurs prix, et le Core refuserait
        // alors de les vendre. Le projet retire donc ses packs avant de
        // changer de devise — jamais de catalogue à moitié renommé.
        if (strtoupper($c->defaultCurrency) !== $before->defaultCurrency && $before->supplementPacks !== []) {
            throw InvalidBillingSettings::because('Ce projet propose des packs : retirez-les avant de changer de devise.');
        }

        // ADR-0119 §1 — les packs sont **reportés tels quels** quand la
        // commande ne les porte pas : un enregistrement des réglages ne
        // vide jamais le catalogue.
        $packs = $c->supplementPacks === null
            ? $before->supplementPacks
            : $this->packsOf($c->supplementPacks, $c->projectId, $c->meterMappings);

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
            supplementPacks: $packs,
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
            $this->packViews($s->supplementPacks),
        );
    }

    /**
     * ADR-0119 §2 — les packs déclarés, vérifiés.
     *
     * Chaque pack est validé par le domaine (`SupplementPack`), puis
     * l'offre est vérifiée **dans le projet** : un pack d'un compteur que
     * le projet ne compte pas ne serait jamais proposé à personne.
     *
     * @param list<array{code: string, meter: string, quantity: int, unit_price_minor: int, label: string}> $declared
     * @param array<string, string> $meterMappings the capability => meter declarations of the project
     * @return list<SupplementPack>
     */
    private function packsOf(array $declared, string $projectId, array $meterMappings): array
    {
        if (count($declared) > SupplementPack::MAXIMUM_PER_PROJECT) {
            throw InvalidBillingSettings::because('Un projet propose au plus '.SupplementPack::MAXIMUM_PER_PROJECT.' packs.');
        }

        $known = $this->metersOf($projectId, $meterMappings);
        $packs = [];
        $codes = [];

        foreach ($declared as $row) {
            $pack = SupplementPack::validated(
                $row['code'],
                $row['meter'],
                $row['quantity'],
                $row['unit_price_minor'],
                $row['label'],
            );

            if (isset($codes[$pack->code])) {
                throw InvalidBillingSettings::because('Deux packs ne peuvent pas porter le même code.');
            }

            if (! in_array($pack->meter, $known, true)) {
                throw InvalidBillingSettings::because('Ce compteur ne fait pas partie de votre offre : ce pack ne serait jamais proposé.');
            }

            $codes[$pack->code] = true;
            $packs[] = $pack;
        }

        return $packs;
    }

    /**
     * ADR-0119 §2 — les compteurs que le projet connaît.
     *
     * Un compteur est connu quand une version de plan **publiée** le
     * porte, ou quand le projet l'a déclaré dans ses correspondances
     * capacité → compteur (ADR-0074 §5).
     *
     * @param array<string, string> $meterMappings the capability => meter declarations of the project
     * @return list<string>
     */
    private function metersOf(string $projectId, array $meterMappings): array
    {
        $meters = [];

        foreach ($meterMappings as $meter) {
            $meters[$meter] = true;
        }

        foreach ($this->plans->listByProject($projectId, null, null, 100)['items'] as $plan) {
            foreach ($this->versions->listByPlan($plan->id) as $version) {
                if (! $version->isPublished()) {
                    continue;
                }

                foreach ($version->features->all() as $feature) {
                    if ($feature->meter !== null) {
                        $meters[$feature->meter] = true;
                    }
                }
            }
        }

        return array_keys($meters);
    }

    /**
     * ADR-0119 §2 — le catalogue, tel qu'il est lu.
     *
     * @param list<SupplementPack> $packs
     * @return list<SupplementPackView>
     */
    private function packViews(array $packs): array
    {
        $views = [];

        foreach ($packs as $pack) {
            $views[] = new SupplementPackView(
                $pack->code,
                $pack->meter,
                $pack->quantity,
                $pack->unitPriceMinor,
                $pack->totalMinor(),
                $pack->label,
            );
        }

        return $views;
    }
}
