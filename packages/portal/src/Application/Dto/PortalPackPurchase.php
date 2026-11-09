<?php

declare(strict_types=1);

namespace Core\Portal\Application\Dto;

/**
 * ADR-0119 §5 — ce que l'achat d'un pack a produit.
 *
 * La facture est **la même** que les autres factures du client : elle
 * se règle dans « Factures », par le chemin de paiement qui existe déjà
 * (ADR-0075 §5). Rien n'est réécrit.
 */
final readonly class PortalPackPurchase
{
    public function __construct(
        public string $invoiceId,
        public string $invoiceNumber,
        public int $totalMinor,
        public string $currency,
    ) {}
}
