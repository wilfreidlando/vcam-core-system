<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin\Concerns;

use Core\Billing\Domain\Exception\CustomerDeclaredAttributeRefused;
use Core\Billing\Domain\Exception\CustomerEmailAlreadyTaken;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Billing\Domain\Exception\CustomerVersionConflict;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Billing\Domain\Exception\InvalidCustomerTransition;
use Core\Billing\Domain\Exception\InvalidInvoiceTransition;
use Core\Billing\Domain\Exception\InvalidPlan;
use Core\Billing\Domain\Exception\InvalidPlanFeature;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\InvoiceNotFound;
use Core\Billing\Domain\Exception\InvoiceNotPayable;
use Core\Billing\Domain\Exception\ManualReceiptApprovalRefused;
use Core\Billing\Domain\Exception\ManualReceiptNotFound;
use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionConflict;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Exception\TenantSchemaNotFound;
use Core\Billing\Domain\Exception\TenantSchemaNotPending;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * ADR-0092 §4 — a refusal of the billing domain is the console's answer,
 * never a crash.
 *
 * The console's gestures are **mutations**: a redirect that carries the
 * refusal back to the page the operator came from keeps them in place
 * (the same idiom as the rotation of an API key, ADR-0069 §2).
 *
 * A resource that does not exist — or that belongs to another
 * organization — is answered like a missing one: the message says
 * "introuvable" and nothing else. It never answers 403, which would
 * confirm that the resource exists somewhere (ADR-0081 S-04).
 *
 * The console speaks the reader's language. Every refusal is said in
 * French, in one sentence that tells the operator **what to do next** —
 * never the wording of the domain, which is English and carries the
 * internal references. That wording belongs to the log, where it helps
 * the team; on the screen it would make the software look complicated.
 */
trait HandlesBillingRefusals
{
    private function refused(RuntimeException $exception): RedirectResponse
    {
        return back()->withErrors(['resource' => $this->refusalMessage($exception)]);
    }

    /**
     * The refusal, said to the operator.
     */
    private function refusalMessage(RuntimeException $exception): string
    {
        return match (true) {
            // A resource that does not exist — or that belongs to another
            // organization: the message says nothing more (ADR-0081 S-04).
            $exception instanceof CustomerNotFound
                || $exception instanceof PlanNotFound
                || $exception instanceof PlanVersionNotFound
                || $exception instanceof SubscriptionNotFound
                || $exception instanceof InvoiceNotFound
                || $exception instanceof ManualReceiptNotFound
                || $exception instanceof TenantSchemaNotFound => 'Ressource introuvable.',

            $exception instanceof CustomerEmailAlreadyTaken => 'Un autre client de ce projet porte déjà cette adresse.',

            $exception instanceof CustomerVersionConflict => 'Ce client a été modifié entre-temps : rechargez la page, puis recommencez.',

            $exception instanceof InvalidCustomerTransition => "Ce geste n'est pas possible sur un client dans cet état.",

            $exception instanceof CustomerDeclaredAttributeRefused => 'Cette valeur est refusée : elle ne correspond pas à la forme déclarée en vigueur.',

            $exception instanceof InvalidPlan => 'Cette offre est incomplète : vérifiez son nom et sa devise.',

            $exception instanceof PlanCodeAlreadyTaken => "Ce code d'offre est déjà pris : choisissez-en un autre.",

            $exception instanceof PlanVersionNotEditable => 'Une version publiée est figée : publiez une nouvelle version pour changer ses conditions.',

            $exception instanceof PlanVersionConflict => $this->versionConflictMessage($exception),

            $exception instanceof InvalidPlanFeature => sprintf(
                'La fonctionnalité « %s » n\'est pas acceptée : son nom s\'écrit en minuscules, sans espace ni accent.',
                $exception->feature,
            ),

            $exception instanceof InvalidBillingInterval => "Cette période n'est pas possible : indiquez une durée d'un jour, d'une semaine, d'un mois ou d'un an.",

            $exception instanceof UnsupportedCurrency => "Cette devise n'est pas prise en charge.",

            $exception instanceof InvalidSubscriptionTransition => "Ce geste n'est pas possible sur cet abonnement dans son état actuel.",

            $exception instanceof InvalidSubscriptionSupplement => "Ce supplément n'est pas possible ici.",

            $exception instanceof InvalidInvoiceTransition => "Ce geste n'est pas possible sur cette facture dans son état actuel.",

            $exception instanceof InvoiceNotPayable => "Cette facture n'est pas payable en l'état.",

            $exception instanceof ManualReceiptApprovalRefused => 'Ce reçu ne peut pas être approuvé.',

            $exception instanceof TenantSchemaNotPending => 'Cette déclaration a déjà été tranchée : elle ne se re-tranche pas.',

            $exception instanceof InvalidBillingSettings => 'Les réglages du commerce sont refusés : vérifiez les compteurs et les packs.',

            default => $this->untranslatedRefusal($exception),
        };
    }

    /**
     * Two rules of the catalogue refuse a write, and they ask two
     * different corrections: an offer that already holds a draft is
     * **corrected**, a rank already taken is simply not written again.
     */
    private function versionConflictMessage(PlanVersionConflict $exception): string
    {
        if ($exception->reason === PlanVersionConflict::DRAFT_EXISTS) {
            return sprintf(
                'Cette offre a déjà une version en préparation (version %d) : corrigez-la plutôt que d\'en ajouter une autre.',
                $exception->version,
            );
        }

        return sprintf('La version %d existe déjà.', $exception->version);
    }

    /**
     * A refusal this trait does not know: the operator gets a plain
     * sentence, and the technical wording goes to the log.
     */
    private function untranslatedRefusal(RuntimeException $exception): string
    {
        Log::warning('Refus du commerce non traduit pour la console', [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);

        return "L'opération n'a pas pu aboutir.";
    }
}
