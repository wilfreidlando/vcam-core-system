<?php

declare(strict_types=1);

namespace App\Http\View;

/**
 * The words the console uses for a customer.
 *
 * The list of customers and the customer's sheet show the **same** status:
 * one says « Actif », the other said `active`. Two screens, one fact, one
 * word — otherwise the operator wonders whether they are looking at the
 * same customer.
 */
final class CustomerLabels
{
    /**
     * The status of a customer, in French.
     *
     * An unknown status is returned as it is: a new status must be given a
     * word before it is shown, and hiding it would hide the problem too.
     */
    public static function status(string $status): string
    {
        return match ($status) {
            'active' => 'Actif',
            'suspended' => 'Suspendu',
            'erased' => 'Effacé',
            default => $status,
        };
    }
}
