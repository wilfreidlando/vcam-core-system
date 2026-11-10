<?php

declare(strict_types=1);

namespace Core\Billing\Domain\Model;

use Core\Billing\Domain\Exception\InvalidBillingSettings;

/**
 * ADR-0119 §2 — un pack : une quantité achetée, proposée au client.
 *
 * Le pack est une **déclaration du projet** (ADR-0119 §1) : il vit dans
 * les réglages du projet, au même titre que les correspondances
 * capacité → compteur (ADR-0074 §5).
 *
 * Il porte quatre choses — le compteur étendu, la quantité, le prix
 * unitaire et le libellé lu par le client — plus le `code` sous lequel le
 * projet range son offre. Le client final ne voit jamais le code.
 *
 * Les règles vivent ici, dans le code : le schéma ne porte pas de règle
 * métier (ADR-0074 §1).
 */
final readonly class SupplementPack
{
    /** Au plus 20 packs par projet (ADR-0119 §2). */
    public const MAXIMUM_PER_PROJECT = 20;

    /**
     * Les bornes du catalogue — la multiplication quantité × prix doit
     * rester dans l'entier 64 bits, et un pack au-delà de ces bornes
     * n'est de toute façon pas un pack.
     */
    public const MAXIMUM_QUANTITY = 1_000_000;

    public const MAXIMUM_UNIT_PRICE_MINOR = 100_000_000;

    private function __construct(
        /** L'identifiant stable du pack dans son projet (« pack-50 »). */
        public string $code,
        /** Le compteur du projet que le pack étend (ADR-0074 §3). */
        public string $meter,
        /** Le nombre d'unités ajoutées à la limite. */
        public int $quantity,
        /** Le prix d'une unité, en unités mineures (G5). */
        public int $unitPriceMinor,
        /** Le libellé lu par le client (« Pack de 50 messages »). */
        public string $label,
    ) {}

    /**
     * Un pack déclaré, vérifié.
     *
     * Le refus est une **validation applicative** : la ligne de réglages
     * est refusée entière, jamais corrigée en silence (ADR-0119 §2).
     *
     * @throws InvalidBillingSettings quand le pack n'est pas une offre valide
     */
    public static function validated(
        string $code,
        string $meter,
        int $quantity,
        int $unitPriceMinor,
        string $label,
    ): self {
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $code) !== 1) {
            throw InvalidBillingSettings::because('Un pack porte un code en minuscules, chiffres, « _ » et « - » (64 caractères au plus).');
        }

        if (preg_match('/^[a-z0-9_.]{1,64}$/', $meter) !== 1) {
            throw InvalidBillingSettings::because('Un pack porte un compteur en minuscules, chiffres, « _ » et « . » (64 caractères au plus).');
        }

        if ($quantity < 1) {
            throw InvalidBillingSettings::because('Un pack porte au moins une unité.');
        }

        if ($quantity > self::MAXIMUM_QUANTITY) {
            throw InvalidBillingSettings::because('Un pack porte au plus '.self::MAXIMUM_QUANTITY.' unités.');
        }

        if ($unitPriceMinor < 0) {
            throw InvalidBillingSettings::because('Le prix d\'un pack ne peut pas être négatif.');
        }

        // ADR-0121 — un pack à prix nul ne peut pas être vendu : le grand
        // livre refuse une ligne à zéro (ADR-0073 §1), et l'achat d'un
        // supplément à prix nul est refusé avant toute écriture
        // (ADR-0117 §4). Un pack offert serait donc une offre que le
        // portail proposerait et que personne ne pourrait acheter.
        if ($unitPriceMinor === 0) {
            throw InvalidBillingSettings::because(
                'Un pack porte un prix strictement positif : le Core ne peut pas facturer un montant nul.'
            );
        }

        if ($unitPriceMinor > self::MAXIMUM_UNIT_PRICE_MINOR) {
            throw InvalidBillingSettings::because('Le prix d\'un pack est plafonné : au-delà, la multiplication déborderait.');
        }

        $label = trim($label);

        if ($label === '') {
            throw InvalidBillingSettings::because('Un pack porte un libellé : c\'est ce que le client lit.');
        }

        if (mb_strlen($label) > 120) {
            throw InvalidBillingSettings::because('Le libellé d\'un pack ne dépasse pas 120 caractères.');
        }

        return new self($code, $meter, $quantity, $unitPriceMinor, $label);
    }

    /**
     * Le pack lu dans la colonne des réglages.
     *
     * Une ligne illisible est **ignorée** plutôt que fatale : un réglage
     * corrompu ne doit pas rendre le portail illisible.
     *
     * @param array<array-key, mixed> $data
     */
    public static function fromArray(array $data): ?self
    {
        $code = $data['code'] ?? null;
        $meter = $data['meter'] ?? null;
        $quantity = $data['quantity'] ?? null;
        $price = $data['unit_price_minor'] ?? null;
        $label = $data['label'] ?? null;

        if (! is_string($code) || ! is_string($meter) || ! is_string($label)) {
            return null;
        }

        if (! is_int($quantity) || ! is_int($price)) {
            return null;
        }

        try {
            return self::validated($code, $meter, $quantity, $price, $label);
        } catch (InvalidBillingSettings) {
            return null;
        }
    }

    /**
     * Le pack tel qu'il est rangé dans les réglages.
     *
     * @return array{code: string, meter: string, quantity: int, unit_price_minor: int, label: string}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'meter' => $this->meter,
            'quantity' => $this->quantity,
            'unit_price_minor' => $this->unitPriceMinor,
            'label' => $this->label,
        ];
    }

    /**
     * Ce que coûte le pack, en unités mineures (G5).
     */
    public function totalMinor(): int
    {
        return $this->quantity * $this->unitPriceMinor;
    }
}
