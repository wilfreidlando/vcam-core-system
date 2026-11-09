<?php

declare(strict_types=1);

namespace App\Notices;

use Core\Support\Money\Money;
use DateTimeImmutable;
use Throwable;

/**
 * ADR-0118 §3 — the words of the Core's notices.
 *
 * A message a person reads: French, one sentence or two, naming the
 * project, the object concerned (the subscription, the invoice, the quota)
 * and the customer. The facts come from the event's payload — the public
 * payload of the commerce's events (ADR-0072 §9, ADR-0074 §2), which
 * carries no personal data: the customer is named by the reference the
 * project knows it by, and the person is named by the name the project
 * declared.
 */
final class CoreNoticeRenderer
{
    /**
     * The message for this event — null when the event is not one the Core
     * knows how to notify.
     *
     * @param array<string, mixed> $payload
     */
    public function render(string $eventType, array $payload, string $projectName, string $customerName): ?string
    {
        $greeting = $this->greeting($customerName);
        $project = $projectName !== '' ? $projectName : 'votre abonnement';
        $meter = $this->meter($payload);

        return match ($eventType) {
            'subscription.renewal_due' => $greeting.'votre abonnement '.$project.' se renouvelle'
                .$this->on($this->date($this->string($payload, 'current_period.ends_at'))).'.'
                .' La facture du renouvellement a été émise.',

            'subscription.trial_will_end' => $greeting.'votre période d\'essai sur '.$project.' se termine'
                .$this->on($this->date($this->string($payload, 'current_period.ends_at'))).'.',

            'subscription.past_due' => $greeting.'le règlement de votre abonnement '.$project
                .' est en attente. La facture correspondante est disponible.',

            'subscription.expired' => $greeting.'votre abonnement '.$project.' est arrivé à échéance'
                .$this->on($this->date($this->string($payload, 'current_period.ends_at'))).'.',

            'usage.threshold_reached' => $greeting.$this->threshold($payload, $meter, $project),

            'usage.limit_reached' => $greeting.'vous avez atteint la limite « '.$meter.' » sur '
                .$project.$this->counts($payload).'.',

            'invoice.issued' => $greeting.'la facture '.$this->invoiceNumber($payload).' pour '.$project
                .$this->amount($payload).' est disponible.',

            'invoice.payment_failed' => $greeting.'le règlement de la facture '.$this->invoiceNumber($payload)
                .' pour '.$project.$this->amount($payload).' n\'a pas abouti.',

            default => null,
        };
    }

    private function greeting(string $customerName): string
    {
        $name = trim($customerName);

        return $name === '' ? 'Bonjour, ' : 'Bonjour '.$name.', ';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function meter(array $payload): string
    {
        return $this->string($payload, 'meter') ?? 'votre quota';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function invoiceNumber(array $payload): string
    {
        return $this->string($payload, 'invoice_number') ?? 'sans numéro';
    }

    /**
     * « vous avez atteint 80 % de votre limite » — or, when the payload
     * carries no threshold, the honest « vous approchez de votre limite ».
     *
     * @param array<string, mixed> $payload
     */
    private function threshold(array $payload, string $meter, string $project): string
    {
        $bps = $payload['threshold_bps'] ?? null;

        if (! is_int($bps)) {
            return 'vous approchez de votre limite « '.$meter.' » sur '.$project.'.';
        }

        // The threshold is in hundredths of a percent: a plan may declare
        // any value between 1 and 10000 bps, so « 0.5 % » is a real
        // threshold and must not be written « 0 % ».
        $percent = rtrim(rtrim(number_format($bps / 100, 2, '.', ''), '0'), '.');
        $percent = str_replace('.', ',', $percent);

        return 'vous avez atteint '.$percent.' % de votre limite « '.$meter.' » sur '.$project.'.';
    }

    /**
     * « (12/100) » — what was used, out of what was allowed.
     *
     * @param array<string, mixed> $payload
     */
    private function counts(array $payload): string
    {
        $used = $payload['used'] ?? null;
        $limit = $payload['limit'] ?? null;

        if (! is_int($used) || ! is_int($limit)) {
            return '';
        }

        return ' ('.$used.'/'.$limit.')';
    }

    /**
     * « (85 000 XOF) » — the amount of the invoice.
     *
     * @param array<string, mixed> $payload
     */
    private function amount(array $payload): string
    {
        $total = $payload['total_minor'] ?? null;
        $currency = $payload['currency'] ?? null;

        if (! is_int($total) || ! is_string($currency) || $currency === '') {
            return '';
        }

        return ' ('.Money::ofMinor($total, $currency)->groupedAmount().' '.$currency.')';
    }

    /**
     * The date, as the reader writes it: `12/03/2026`.
     */
    private function date(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($iso))->format('d/m/Y');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * « le 12/03/2026 » — empty when the payload carries no date.
     */
    private function on(?string $date): string
    {
        return $date === null ? '' : ' le '.$date;
    }

    /**
     * A value of the payload, read through a dotted path.
     *
     * @param array<string, mixed> $payload
     */
    private function string(array $payload, string $path): ?string
    {
        $value = $payload;

        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return null;
            }

            $value = $value[$segment];
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
