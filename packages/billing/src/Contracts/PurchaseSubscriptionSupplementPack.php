<?php

declare(strict_types=1);

namespace Core\Billing\Contracts;

use RuntimeException;

/**
 * ADR-0119 §3 — l'achat d'un **pack** du catalogue du projet.
 *
 * Le contrat prend le **code** d'un pack, jamais une quantité ni un prix :
 * le client choisit dans un catalogue que le projet a déclaré, il ne
 * dicte pas son tarif. C'est ce qui distingue l'achat du portail de la
 * commande d'opérateur (`PurchaseSubscriptionSupplement`, ADR-0117 §4, qui
 * déclare une quantité et un prix).
 *
 * L'achat produit le même objet qu'ADR-0117 : un supplément `pending` et
 * la facture qui le porte. L'allocation n'est accordée qu'au règlement de
 * cette facture (ADR-0117 §2).
 */
interface PurchaseSubscriptionSupplementPack
{
    /**
     * @throws RuntimeException si l'achat ne peut pas être fait — un pack
     *                          qui n'existe plus, un contrat d'un autre
     *                          projet, un compteur que le plan ne porte
     *                          pas, un contrat dans une autre devise que
     *                          celle du pack. Le type concret de l'exception
     *                          reste dans la couche Domain ; Contracts ne
     *                          la référence pas (G3c).
     */
    public function purchase(PurchaseSubscriptionSupplementPackCommand $command): SubscriptionSupplementPurchaseView;
}
