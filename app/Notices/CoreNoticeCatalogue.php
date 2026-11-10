<?php

declare(strict_types=1);

namespace App\Notices;

/**
 * ADR-0118 §3 — the notices the Core can write, and the labels the console
 * shows for them.
 *
 * This catalogue lives in the application shell, not in a module: the events
 * it names belong to the commerce (`billing`), and the module `notification`
 * must not learn their vocabulary (the governance gate « générique »).
 *
 * The list is closed: an event that is not here is never notified, whatever
 * a project declared.
 */
final class CoreNoticeCatalogue
{
    /**
     * The only channel a notice can take today (ADR-0099 §4 — WhatsApp is
     * the only routed adapter).
     */
    public const CHANNEL = 'whatsapp';

    /**
     * The event type => the label the console shows.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        'subscription.renewal_due' => 'Rappel de renouvellement d\'abonnement',
        'subscription.trial_will_end' => 'Fin de la période d\'essai',
        'subscription.past_due' => 'Abonnement impayé',
        'subscription.expired' => 'Abonnement arrivé à échéance',
        'usage.threshold_reached' => 'Seuil de quota atteint',
        'usage.limit_reached' => 'Quota atteint',
        'invoice.issued' => 'Facture émise',
        'invoice.payment_failed' => 'Facture impayée',
    ];

    /**
     * @return list<string>
     */
    public static function events(): array
    {
        return array_keys(self::LABELS);
    }

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return self::LABELS;
    }

    /**
     * Whether this event is one the Core knows how to notify.
     */
    public static function isNotifiable(string $eventType): bool
    {
        return isset(self::LABELS[$eventType]);
    }

    /**
     * The window a notice belongs to: **one notice per event, per customer,
     * per window** (ADR-0118 §6, amended by ADR-0120).
     *
     * The window names the **resource** the event is about, and the period
     * that resource lives in:
     *
     * - an invoice names itself;
     * - a subscription names itself and the period that ends;
     * - a usage names the **counter** it is about, the window of that
     *   counter and the limit that was reached.
     *
     * The counter matters: a plan can carry several metered features, and
     * two of them can be exhausted in the very same period. The period
     * alone would make the second one a replay of the first — and the
     * customer would never be told that his second quota is spent. The
     * limit matters too: a pack bought in the middle of the period raises
     * the limit, and reaching *that* limit is another fact, worth another
     * message.
     *
     * @param array<string, mixed> $payload
     */
    public static function windowFor(string $eventType, array $payload): string
    {
        $invoiceId = $payload['invoice_id'] ?? null;
        if (is_string($invoiceId) && $invoiceId !== '') {
            return 'invoice|'.$invoiceId;
        }

        $subscriptionId = $payload['subscription_id'] ?? null;
        if (is_string($subscriptionId) && $subscriptionId !== '') {
            $period = $payload['current_period'] ?? null;
            $endsAt = is_array($period) ? ($period['ends_at'] ?? null) : null;

            return 'subscription|'.$subscriptionId
                .(is_string($endsAt) && $endsAt !== '' ? '|'.$endsAt : '');
        }

        $meter = $payload['meter'] ?? null;
        if (is_string($meter) && $meter !== '') {
            $parts = ['meter', $meter];

            foreach (['window_start', 'window_end'] as $key) {
                $value = $payload[$key] ?? null;
                $parts[] = is_string($value) && $value !== '' ? $value : 'none';
            }

            // ADR-0120 — la fenêtre nomme la quantité **dont le message
            // parle** : la limite atteinte (« 100/100 ») ou le seuil
            // franchi (« 80 % »). Nommer la limite dans les deux cas
            // ferait du seuil re-déclenché par un pack un message de plus
            // — mais un message identique mot pour mot au premier.
            if ($eventType === 'usage.limit_reached') {
                $limit = $payload['limit'] ?? null;
                $parts[] = 'limit|'.(is_int($limit) ? (string) $limit : 'none');
            } elseif ($eventType === 'usage.threshold_reached') {
                $bps = $payload['threshold_bps'] ?? null;
                $parts[] = 'threshold|'.(is_int($bps) ? (string) $bps : 'none');
            }

            return implode('|', $parts);
        }

        return 'none';
    }
}
