<?php

declare(strict_types=1);

namespace Core\Tooling\PhpStan\Rules;

use PhpParser\Node;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Fitness function 6 — anti-corruption layer: a provider's raw vocabulary never
 * escapes its adapter.
 *
 * Every provider names its outcomes differently: MTN says SUCCESSFUL, Orange
 * says SUCCESS, WhatsApp reports `read`. If those strings spread into services,
 * controllers or consuming products, swapping a provider stops being a config
 * change and becomes a migration across the whole codebase — exactly the
 * coupling the Ports & Adapters design exists to prevent.
 *
 * The adapter translates raw statuses into the canonical set once, at the
 * boundary. The raw value is still persisted (provider_transactions.raw_status)
 * for audit, but only the adapter may speak that language.
 *
 * String literals are invisible to Deptrac and PHPat, hence a dedicated rule.
 *
 * @implements Rule<String_>
 */
final class ForbiddenProviderStatusLiteralRule implements Rule
{
    /**
     * Raw status vocabulary → the only namespace prefix(es) allowed to
     * mention it. Multiple providers may share a raw status string
     * (e.g. MyCoolPay's `SUCCESS` and Orange's `SUCCESS` are independent
     * vocabularies — a future refund-cancellation might map one of them
     * differently). When that happens, list every namespace allowed to
     * mention the string.
     *
     * @var array<string, list<string>>
     */
    private const PROVIDER_VOCABULARY = [
        // MTN MoMo collection/disbursement statuses
        'SUCCESSFUL' => ['Core\Payment\Infrastructure\Provider\Mtn'],
        'PAYER_NOT_FOUND' => ['Core\Payment\Infrastructure\Provider\Mtn'],
        'PAYEE_NOT_ALLOWED_TO_RECEIVE' => ['Core\Payment\Infrastructure\Provider\Mtn'],
        'NOT_ENOUGH_FUNDS' => ['Core\Payment\Infrastructure\Provider\Mtn'],
        'PAYER_LIMIT_REACHED' => ['Core\Payment\Infrastructure\Provider\Mtn'],
        // Orange Money statuses
        'INITIATED' => ['Core\Payment\Infrastructure\Provider\Orange'],
        'SUCCESS' => ['Core\Payment\Infrastructure\Provider\Orange', 'Core\Payment\Infrastructure\Provider\MyCoolPay'],
        'EXPIRED' => ['Core\Payment\Infrastructure\Provider\Orange', 'Core\Payment\Infrastructure\Provider\MyCoolPay'],
        // MyCoolPay statuses
        'CANCELED' => ['Core\Payment\Infrastructure\Provider\MyCoolPay'],
        'FAILED' => ['Core\Payment\Infrastructure\Provider\MyCoolPay'],
        'PENDING' => ['Core\Payment\Infrastructure\Provider\MyCoolPay'],
        'REQUIRE_OTP' => ['Core\Payment\Infrastructure\Provider\MyCoolPay'],
        'USSD_OK' => ['Core\Payment\Infrastructure\Provider\MyCoolPay'],
        // WhatsApp delivery receipts
        'accepted' => ['Core\Notification\Infrastructure\Provider\WhatsApp'],
    ];

    public function getNodeType(): string
    {
        return String_::class;
    }

    public function processNode(Node $node, Scope $scope): array
    {
        $value = $node->value;

        if (! isset(self::PROVIDER_VOCABULARY[$value])) {
            return [];
        }

        $allowedNamespaces = self::PROVIDER_VOCABULARY[$value];
        $currentNamespace = $scope->getNamespace() ?? '';

        foreach ($allowedNamespaces as $allowedNamespace) {
            if (str_starts_with($currentNamespace, $allowedNamespace)) {
                return [];
            }
        }

        // Tests legitimately reference provider status literals as test
        // fixtures — they verify the mapping, they don't perform real
        // provider calls. The /tests/ directories are exempt.
        if (str_contains($currentNamespace, '\\Tests\\')
            || str_starts_with($currentNamespace, 'Tests\\')) {
            return [];
        }

        // Only guard the Core itself. The tooling namespace necessarily names
        // these statuses — it is the rule that defines them.
        if (! str_starts_with($currentNamespace, 'Core\\')
            || str_starts_with($currentNamespace, 'Core\Tooling')) {
            return [];
        }

        $allowedList = implode(' or ', $allowedNamespaces);

        return [
            RuleErrorBuilder::message(sprintf(
                'Raw provider status %s appears outside %s. Map it to a canonical status '.
                'inside the adapter; the core must only handle canonical values.',
                var_export($value, true),
                $allowedList,
            ))
                ->identifier('core.providerStatusLeak')
                ->tip('Canonical payment statuses: pending, processing, succeeded, failed, expired, refunded. Canonical notification statuses: queued, sent, delivered, read, failed.')
                ->build(),
        ];
    }
}
