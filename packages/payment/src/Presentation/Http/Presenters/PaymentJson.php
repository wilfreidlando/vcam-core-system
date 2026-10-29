<?php

declare(strict_types=1);

namespace Core\Payment\Presentation\Http\Presenters;

use Core\Payment\Contracts\PaymentView;

/**
 * The public JSON shape of a payment (ADR-0061, ADR-0088 §2), in one place
 * so that `GET /v1/payments/{id}`, `GET /v1/payments` and the project
 * export cannot drift apart.
 */
final class PaymentJson
{
    /**
     * @return array<string, mixed>
     */
    public static function status(PaymentView $view): array
    {
        return [
            'id' => $view->id,
            'status' => $view->status,
            'is_final' => $view->isFinal,
            'amount' => $view->amount,
            'currency' => $view->currency,
            'channel' => $view->channel,
            'created_at' => $view->createdAt,
            'updated_at' => $view->updatedAt,
            ...($view->paymentUrl !== null && ! $view->isFinal ? ['payment_url' => $view->paymentUrl] : []),
            ...($view->actionHint !== null && ! $view->isFinal ? ['action_hint' => $view->actionHint] : []),
            ...($view->isFinal && $view->status !== 'succeeded' && $view->errorMessage !== null ? ['failure_reason' => $view->errorMessage] : []),
        ];
    }
}
