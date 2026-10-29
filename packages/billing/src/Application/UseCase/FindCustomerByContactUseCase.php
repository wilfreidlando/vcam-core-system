<?php

declare(strict_types=1);

namespace Core\Billing\Application\UseCase;

use Core\Billing\Application\Port\CustomerDirectory;
use Core\Billing\Application\Support\CustomerViews;
use Core\Billing\Contracts\CustomerView;
use Core\Billing\Contracts\FindCustomerByContact;
use Core\Billing\Contracts\FindCustomerByContactCommand;
use Core\Billing\Domain\Model\CustomerStatus;
use Core\Support\Access\AccessScope;
use Core\Support\Privacy\BlindIndex;
use Core\Support\Privacy\PersonalDataKind;

/**
 * ADR-0075 §2 — see the contract: blind-index lookup, never an erased
 * customer, an ambiguous contact finds nothing.
 */
final readonly class FindCustomerByContactUseCase implements FindCustomerByContact
{
    public function __construct(
        private CustomerDirectory $customers,
        private BlindIndex $blindIndex,
    ) {}

    public function find(FindCustomerByContactCommand $command, AccessScope $scope): ?CustomerView
    {
        if (! $scope->allowsProject($command->projectId)) {
            return null;
        }

        $kind = match ($command->kind) {
            FindCustomerByContactCommand::KIND_PHONE => PersonalDataKind::Phone,
            FindCustomerByContactCommand::KIND_EMAIL => PersonalDataKind::Email,
            default => null,
        };

        if ($kind === null) {
            return null;
        }

        $normalized = $kind->normalize($command->value);

        // Nothing to look up (an empty value would hash like any other).
        if ($normalized === '' || $normalized === '+') {
            return null;
        }

        $index = $this->blindIndex->of($normalized, $kind);

        // The email is unique per project; the phone is not: two rows
        // mean the contact is shared, hence nobody.
        if ($kind === PersonalDataKind::Email) {
            $byEmail = $this->customers->findByEmail($command->projectId, $index);
            $found = $byEmail === null ? [] : [$byEmail];
        } else {
            $found = $this->customers->findByPhoneIndex($command->projectId, $index, 2);
        }

        if (count($found) !== 1) {
            return null;
        }

        $customer = $found[0];

        if ($customer->projectId !== $command->projectId || $customer->erasedAt !== null || $customer->status === CustomerStatus::Erased) {
            return null;
        }

        return CustomerViews::from($customer);
    }
}
