<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Guardrail G3c — a module reaches another module only through its Contracts/.
 *
 * Neither Deptrac config can express this on its own. deptrac-modules.yaml says
 * Wallet may use Ledger; deptrac-layers.yaml says Application may use Domain.
 * Because each layer spans every package, the combination silently allowed
 * `Core\Wallet\Application` to use `Core\Ledger\Domain` — another module's
 * internals. The architecture review of 2026-09-14 exposed the hole; a probe
 * confirmed both configs reported zero violations.
 *
 * Why it matters: a module's Domain, Application and Infrastructure are free to
 * change. Its Contracts are the promise. The day Wallet depends on a Ledger
 * entity, a ledger refactor breaks the wallet, and extracting either module
 * into a service stops being possible without a rewrite.
 *
 * One rule per module, because the target pattern must exclude the subject's
 * own namespace. ComposerGraphTest fails if a module in ModuleGraph has no rule
 * here, so a new module cannot slip past this guardrail.
 *
 * @see docs/adr/0027-read-models-et-acces-inter-modules.md
 */
final class ModulesMeetOnlyThroughContracts
{
    public function test_identity_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Identity');
    }

    public function test_parties_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Parties');
    }

    public function test_ledger_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Ledger');
    }

    public function test_wallet_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Wallet');
    }

    public function test_payment_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Payment');
    }

    public function test_fees_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Fees');
    }

    public function test_events_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Events');
    }

    public function test_webhook_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Webhook');
    }

    public function test_notification_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Notification');
    }

    public function test_audit_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Audit');
    }

    public function test_billing_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Billing');
    }

    public function test_storage_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Storage');
    }

    public function test_developer_platform_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('DeveloperPlatform');
    }

    /**
     * The operator console reads across every capability — through their
     * published read models, never their tables or entities (review §3.2).
     */
    public function test_admin_uses_other_modules_through_contracts_only(): Rule
    {
        return $this->contractsOnly('Admin');
    }

    private function contractsOnly(string $module): Rule
    {
        // Any Core class that belongs to ANOTHER module (not this one, not the
        // shared kernel, not tooling) and does not live under that module's
        // Contracts\ namespace.
        $otherModulesInternals = sprintf(
            '/^Core\\\\(?!%s\\\\|Support\\\\|Tooling\\\\)[A-Za-z]+\\\\(?!Contracts\\\\)/',
            $module,
        );

        return PHPat::rule()
            ->classes(Selector::inNamespace('Core\\'.$module))
            ->shouldNotDependOn()
            ->classes(Selector::classname($otherModulesInternals, true))
            ->because(
                'A module may use another module only through its Contracts\\ namespace. '.
                'Domain, Application and Infrastructure are internal and free to change; '.
                'depending on them couples the two modules and forbids extracting either one.'
            );
    }
}
