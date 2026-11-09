<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Support\InvoiceViews;
use Core\Billing\Application\Support\SupplementViews;
use Core\Billing\Contracts\PurchaseSubscriptionSupplement;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementCommand;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPack;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPackCommand;
use Core\Billing\Contracts\SubscriptionSupplementPurchaseView;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Billing\Domain\Exception\SupplementAlreadyPurchased;
use Core\Billing\Domain\Model\Customer;
use Core\Billing\Domain\Model\SubscriptionSupplement;
use Core\Billing\Domain\Model\SupplementPack;
use Core\Billing\Domain\Port\BillingSettingsRepository;
use Core\Billing\Domain\Port\CustomerRepository;
use Core\Billing\Domain\Port\InvoiceLineRepository;
use Core\Billing\Domain\Port\InvoiceRepository;
use Core\Billing\Domain\Port\SubscriptionRepository;
use Core\Billing\Domain\Port\SubscriptionSupplementRepository;

/**
 * ADR-0119 §3 — l'achat d'un pack du catalogue, par le client final.
 *
 * Le client cite le **code** d'un pack ; le Core en tire le compteur, la
 * quantité et le prix, et les fige dans le supplément (ADR-0117 §1). Le
 * client ne dicte jamais son tarif.
 *
 * L'achat produit exactement ce que produit la route de l'opérateur
 * (ADR-0117 §4) : un supplément `pending` et la facture qui le porte.
 * L'allocation n'est accordée qu'au règlement de cette facture
 * (ADR-0117 §2).
 *
 * Le **rejeu** est absorbé ici (ADR-0119 §6) : la clé du geste est
 * unique par projet, donc un même geste ne peut acheter qu'une fois.
 */
final readonly class PurchaseSubscriptionSupplementPackUseCase implements PurchaseSubscriptionSupplementPack
{
    public function __construct(
        private BillingSettingsRepository $settings,
        private SubscriptionRepository $subscriptions,
        private SubscriptionSupplementRepository $supplements,
        private InvoiceRepository $invoices,
        private InvoiceLineRepository $lines,
        private CustomerRepository $customers,
        private PurchaseSubscriptionSupplement $purchase,
    ) {}

    public function purchase(PurchaseSubscriptionSupplementPackCommand $command): SubscriptionSupplementPurchaseView
    {
        // ADR-0119 §6 — le rejeu passe **avant** le catalogue : un geste
        // déjà fait rend son résultat, même si le pack a quitté le
        // catalogue depuis.
        $existing = $this->supplements->findByIdempotencyKey($command->projectId, $command->idempotencyKey);

        if ($existing !== null) {
            $this->assertSameGesture($existing, $command);

            return $this->viewOf($existing);
        }

        $settings = $this->settings->find($command->projectId);
        $pack = null;

        foreach ($settings->supplementPacks as $candidate) {
            if ($candidate->code === $command->packCode) {
                $pack = $candidate;

                break;
            }
        }

        if ($pack === null) {
            throw InvalidSubscriptionSupplement::becausePackNotFound($command->packCode);
        }

        $subscription = $this->subscriptions->findById($command->subscriptionId);

        // Un contrat d'un autre projet est **introuvable**, jamais
        // distingué d'un contrat qui n'existe pas (G15).
        if ($subscription === null || $subscription->projectId !== $command->projectId) {
            throw SubscriptionNotFound::becauseId($command->subscriptionId);
        }

        // ADR-0119 §2 — les packs du projet sont tarifés dans sa devise :
        // un contrat dans une autre devise n'achète pas de pack, le
        // montant n'est jamais converti dans le dos du client.
        if ($subscription->currency !== $settings->defaultCurrency) {
            throw InvalidSubscriptionSupplement::becausePackCurrencyMismatch(
                $settings->defaultCurrency,
                $subscription->currency,
            );
        }

        try {
            return $this->purchase->purchase(new PurchaseSubscriptionSupplementCommand(
                projectId: $command->projectId,
                subscriptionId: $command->subscriptionId,
                meter: $pack->meter,
                quantity: $pack->quantity,
                unitPriceMinor: $pack->unitPriceMinor,
                // Le libellé du pack est la ligne de la facture : le
                // client lit ce qu'il a acheté (ADR-0119 §5).
                description: $pack->label,
                actor: $command->actor,
                correlationId: $command->correlationId,
                idempotencyKey: $command->idempotencyKey,
            ));
        } catch (SupplementAlreadyPurchased) {
            // ADR-0119 §6 — un geste concurrent de même clé a gagné la
            // course : c'est son supplément que le client a acheté.
            $winner = $this->supplements->findByIdempotencyKey($command->projectId, $command->idempotencyKey);

            if ($winner === null) {
                throw InvalidSubscriptionSupplement::becausePurchaseUnreadable();
            }

            return $this->viewOf($winner);
        }
    }

    /**
     * Le résultat d'un achat déjà fait : le supplément et la facture qui
     * le porte.
     */
    /**
     * ADR-0121 — a replay hands back the result of the gesture the key
     * already carries. It must be **the same gesture**: another
     * contract, another pack or another quantity is another purchase,
     * and returning the first one would tell the caller that a pack was
     * bought that never was.
     */
    private function assertSameGesture(SubscriptionSupplement $existing, PurchaseSubscriptionSupplementPackCommand $command): void
    {
        if ($existing->subscriptionId !== $command->subscriptionId) {
            throw InvalidSubscriptionSupplement::becauseKeyCarriesAnotherPurchase();
        }

        $pack = $this->packOf($command);

        // The pack left the catalogue: the gesture cannot be compared
        // any more, and the contract is the only thing left to check
        // (ADR-0119 §6 — the replay comes before the catalogue, so a
        // gesture already made still renders even if its pack is gone).
        if ($pack === null) {
            return;
        }

        if ($existing->meter !== $pack->meter || $existing->quantity !== $pack->quantity) {
            throw InvalidSubscriptionSupplement::becauseKeyCarriesAnotherPurchase();
        }
    }

    /**
     * The pack of the command, or null when the catalogue of the project
     * does not carry it any more.
     */
    private function packOf(PurchaseSubscriptionSupplementPackCommand $command): ?SupplementPack
    {
        $settings = $this->settings->find($command->projectId);

        foreach ($settings->supplementPacks as $candidate) {
            if ($candidate->code === $command->packCode) {
                return $candidate;
            }
        }

        return null;
    }

    private function viewOf(SubscriptionSupplement $supplement): SubscriptionSupplementPurchaseView
    {
        $customer = $this->customers->findById($supplement->customerId);
        $customerRef = $customer instanceof Customer ? $customer->externalRef : '';

        $invoice = $supplement->invoiceId === null
            ? null
            : $this->invoices->findById($supplement->invoiceId);

        if ($invoice === null) {
            throw InvalidSubscriptionSupplement::becausePurchaseUnreadable();
        }

        return new SubscriptionSupplementPurchaseView(
            SupplementViews::from($supplement, $customerRef, $invoice->number),
            InvoiceViews::from($invoice, $customerRef, $this->lines->listByInvoice($invoice->id)),
        );
    }
}
