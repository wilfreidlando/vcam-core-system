<?php

declare(strict_types=1);

namespace Core\Portal\Application\Support;

use DateTimeImmutable;

/**
 * What a customer reads: amounts, dates and states in plain French.
 *
 * Amounts are minor units (G5); the currencies without a decimal part
 * (the CFA francs, the yen…) are shown whole, never as "5000.00".
 */
final class PortalFormat
{
    private const ZERO_DECIMAL = ['XAF', 'XOF', 'JPY', 'KRW', 'RWF', 'GNF', 'UGX', 'VND'];

    private const MONTHS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];

    private const SUBSCRIPTION = [
        'incomplete' => ['En attente de paiement', 'warn'],
        'trialing' => ['Période d\'essai', 'info'],
        'active' => ['Actif', 'ok'],
        'past_due' => ['Paiement en retard', 'danger'],
        'paused' => ['En pause', 'muted'],
        'canceled' => ['Résilié', 'muted'],
        'expired' => ['Expiré', 'danger'],
    ];

    private const INVOICE = [
        'open' => ['À payer', 'warn'],
        'paid' => ['Payée', 'ok'],
        'void' => ['Annulée', 'muted'],
        'uncollectible' => ['Irrécouvrable', 'danger'],
        'draft' => ['Brouillon', 'muted'],
    ];

    private const PAYMENT = [
        'pending' => ['En cours', 'warn'],
        'succeeded' => ['Réussi', 'ok'],
        'failed' => ['Échoué', 'danger'],
        'canceled' => ['Annulé', 'muted'],
    ];

    private const CHANNELS = [
        'mtn_momo' => 'MTN Mobile Money',
        'orange_money' => 'Orange Money',
        'card' => 'Carte bancaire',
    ];

    public static function money(int $minor, string $currency): string
    {
        $zero = in_array(strtoupper($currency), self::ZERO_DECIMAL, true);
        $value = $zero ? (string) abs($minor) : number_format(abs($minor) / 100, 2, ',', ' ');
        $grouped = $zero ? number_format(abs($minor), 0, ',', "\u{202F}") : $value;

        return ($minor < 0 ? '−' : '').$grouped."\u{00A0}".strtoupper($currency);
    }

    public static function date(?string $iso): string
    {
        if ($iso === null || $iso === '') {
            return '—';
        }

        $moment = date_create_immutable($iso);

        if (! $moment instanceof DateTimeImmutable) {
            return '—';
        }

        return $moment->format('j').' '.self::MONTHS[(int) $moment->format('n') - 1].' '.$moment->format('Y');
    }

    /**
     * @return array{0: string, 1: string} label, tone
     */
    public static function subscriptionStatus(string $status): array
    {
        return self::SUBSCRIPTION[$status] ?? [$status, 'muted'];
    }

    /**
     * @return array{0: string, 1: string} label, tone
     */
    public static function invoiceStatus(string $status): array
    {
        return self::INVOICE[$status] ?? [$status, 'muted'];
    }

    /**
     * @return array{0: string, 1: string} label, tone
     */
    public static function paymentStatus(string $status): array
    {
        return self::PAYMENT[$status] ?? [$status, 'muted'];
    }

    /**
     * « dans 12 jours », « aujourd'hui », « il y a 3 jours ».
     */
    public static function daysUntil(?string $iso): string
    {
        $moment = $iso === null ? false : date_create_immutable($iso);

        if (! $moment instanceof DateTimeImmutable) {
            return '';
        }

        $days = (int) floor(($moment->getTimestamp() - time()) / 86400);

        return match (true) {
            $days === 0 => 'Aujourd\'hui',
            $days === 1 => 'Demain',
            $days > 1 => 'Dans '.$days.' jours',
            $days === -1 => 'Hier',
            default => 'Il y a '.abs($days).' jours',
        };
    }

    public static function channel(string $channel): string
    {
        return self::CHANNELS[$channel] ?? ucfirst(str_replace('_', ' ', $channel));
    }

    public static function interval(string $unit, int $count): string
    {
        $labels = ['day' => ['jour', 'jours'], 'week' => ['semaine', 'semaines'], 'month' => ['mois', 'mois'], 'year' => ['an', 'ans']];
        $label = $labels[$unit] ?? [$unit, $unit];

        return $count === 1 ? 'par '.$label[0] : 'tous les '.$count.' '.$label[1];
    }

    /**
     * « par mois », « par semaine », « tous les 3 mois ».
     */
    public static function per(string $unit, int $count): string
    {
        return self::interval($unit, $count);
    }

    /**
     * 0–100, for a meter bar.
     */
    public static function percent(?int $used, ?int $limit): int
    {
        if ($limit === null || $limit <= 0 || $used === null) {
            return 0;
        }

        return (int) min(100, round($used * 100 / $limit));
    }
}
