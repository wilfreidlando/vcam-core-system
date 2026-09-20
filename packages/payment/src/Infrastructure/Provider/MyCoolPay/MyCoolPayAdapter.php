<?php

declare(strict_types=1);

namespace Core\Payment\Infrastructure\Provider\MyCoolPay;

use Core\Payment\Contracts\InitiatePaymentCommand;
use Core\Payment\Contracts\PaymentAction;
use Core\Payment\Contracts\PaymentGateway;
use Core\Payment\Contracts\PaymentReceipt;
use Core\Payment\Domain\Exception\ProviderApplicationNotFound;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Payment\PaymentFlow;
use Core\Payment\PaymentStatus;
use Core\Support\Integration\Configuration\ProviderConfig;
use Core\Support\Integration\ExternalApiCaller;
use Core\Support\Integration\ExternalRequest;
use Core\Support\Integration\HttpMethod;
use Core\Support\Integration\Provider\ProviderCode;
use RuntimeException;

/**
 * MyCoolPay payment provider adapter.
 *
 * Maps four MyCoolPay endpoints onto the canonical `PaymentGateway`
 * surface:
 *   - `initiate(command)`    → POST /payin OR POST /paylink (dispatch by command.flow)
 *   - `submitAction(action)` → POST /payin/authorize (OTP submit)
 *   - `checkStatus(ref)`     → GET /checkStatus/{ref}
 *
 * Plus a 5th: the webhook handler in `Presentation/Http/Controllers/MyCoolPayWebhookController`,
 * which is the inbound counterpart to `initiate()` — the provider's async
 * confirmation of a payment the adapter has already started.
 *
 * # Anti-corruption boundary
 *
 * This is the ONLY class that knows MyCoolPay's URL scheme, header
 * names and response field names. The vocabularies do not leak out:
 *
 *   - MyCoolPay's `SUCCESS/CANCELED/FAILED/PENDING/REQUIRE_OTP/USSD_OK`
 *     strings are translated to the canonical `PaymentStatus` enum here.
 *   - MyCoolPay's `transaction_ref` and `app_transaction_ref` pass through
 *     under their canonical names (`transactionRef`, `appTransactionRef`).
 *   - MyCoolPay's `payment_url` (Paylink) and `action` (Payin) collapse
 *     into the canonical `paymentUrl` and `requiresAction` flags.
 *
 * # Auth and credentials
 *
 * MyCoolPay uses a public key in the URL path and a private key in
 * the `X-PRIVATE-KEY` header for sensitive operations (`payout`,
 * `balance`). The four operations implemented here do NOT need the
 * private key — they go through with `requiresAuth: false` on the
 * ExternalRequest, and the foundation skips the AuthProvider pipeline.
 *
 * When the Lot 3 work adds `payout` and `balance`, both will set
 * `requiresAuth: true` and the bearer auth provider will attach the
 * `X-PRIVATE-KEY` header.
 *
 * **Multi-tenant**: each Project has its own MyCoolPay application (its
 * own public/private key pair), registered via
 * `payment:register-provider-application` and resolved here per call
 * through `ProviderApplicationRepository` — never a single platform-wide
 * key pair (security review finding, user-directed expansion: "chaque
 * application a ses clés"). `$config` still supplies infra-level
 * settings shared across every application (base URL, timeouts,
 * retries, breaker), just not the key pair itself.
 */
final readonly class MyCoolPayAdapter implements PaymentGateway
{
    public function __construct(
        private ExternalApiCaller $caller,
        private ProviderConfig $config,
        private MyCoolPayStatusMapper $statusMapper,
        private ProviderApplicationRepository $applications,
    ) {}

    public function providerCode(): ProviderCode
    {
        return $this->config->code;
    }

    public function config(): ProviderConfig
    {
        return $this->config;
    }

    public function initiate(InitiatePaymentCommand $command): PaymentReceipt
    {
        $publicKey = $this->publicKeyForProject($command->projectId);

        // Build the request body once — shared shape between /payin and /paylink.
        $body = [
            'transaction_amount' => $command->amount,
            'transaction_currency' => $command->currency,
            'transaction_reason' => $command->reason,
            'app_transaction_ref' => $command->appTransactionRef,
        ];
        if ($command->customerPhoneNumber !== null) {
            $body['customer_phone_number'] = $command->customerPhoneNumber;
        }
        if ($command->customerName !== null) {
            $body['customer_name'] = $command->customerName;
        }
        if ($command->customerEmail !== null) {
            $body['customer_email'] = $command->customerEmail;
        }
        if ($command->customerLang !== null) {
            $body['customer_lang'] = $command->customerLang;
        }

        // Dispatch by flow. MyCoolPay exposes the two endpoints as
        // distinct resources; the operation name on the request makes
        // the wire path explicit in the foundation logs.
        $path = match ($command->flow) {
            PaymentFlow::Payin => 'payin',
            PaymentFlow::Paylink => 'paylink',
        };

        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/api/'.$publicKey.'/'.$path,
            providerCode: $this->config->code,
            body: $body,
            idempotencyKey: $command->appTransactionRef,
            operation: 'my-coolpay.'.$command->flow->value,
            requiresAuth: false, // /payin and /paylink don't need the private key
        );

        $response = $this->caller->send($this->config, $request);

        return $this->buildReceiptFromInitResponse($response->body, $command->flow);
    }

    public function submitAction(PaymentAction $action): PaymentReceipt
    {
        $publicKey = $this->publicKeyForProject($action->projectId);

        $request = new ExternalRequest(
            method: HttpMethod::POST,
            path: '/api/'.$publicKey.'/payin/authorize',
            providerCode: $this->config->code,
            body: [
                'transaction_ref' => $action->transactionRef,
                'code' => $action->code,
            ],
            idempotencyKey: $action->transactionRef,
            operation: 'my-coolpay.otp-submit',
            requiresAuth: false,
        );

        $response = $this->caller->send($this->config, $request);

        return $this->buildReceiptFromInitResponse($response->body, PaymentFlow::Payin);
    }

    public function checkStatus(string $transactionRef, string $projectId): PaymentReceipt
    {
        $publicKey = $this->publicKeyForProject($projectId);

        $request = new ExternalRequest(
            method: HttpMethod::GET,
            path: '/api/'.$publicKey.'/checkStatus/'.$transactionRef,
            providerCode: $this->config->code,
            requiresAuth: false,
            operation: 'my-coolpay.check-status',
        );

        $response = $this->caller->send($this->config, $request);

        // The check-status response shape is identical to the initiate
        // response — same fields, same dispatch.
        return $this->buildReceiptFromInitResponse($response->body, PaymentFlow::Payin);
    }

    /**
     * Resolve this Project's own MyCoolPay application public key — never
     * a single platform-wide one. Throws rather than falling back to any
     * default: a Project without its own registered application cannot
     * pay through MyCoolPay, full stop (security review finding).
     */
    private function publicKeyForProject(string $projectId): string
    {
        $application = $this->applications->findByProjectAndProvider($projectId, $this->config->code->toString())
            ?? throw ProviderApplicationNotFound::forProject($projectId, $this->config->code->toString());

        return $application->publicKey;
    }

    /**
     * Shared response → `PaymentReceipt` mapping. MyCoolPay's /payin,
     * /paylink and /checkStatus responses all share the same envelope:
     *
     *   {
     *     "status": "success" | "failed",
     *     "transaction_ref": "...",
     *     "payment_url": "..." (paylink only),
     *     "action": "PENDING" | "REQUIRE_OTP" | "USSD_OK" | ... (payin/check-status)
     *   }
     *
     * We extract `payment_url` when present, and map `action` to the
     * canonical status via the StatusMapper (which is the single place
     * that knows MyCoolPay's status vocabulary).
     *
     * @param array<string, mixed>|null $body
     */
    private function buildReceiptFromInitResponse(?array $body, PaymentFlow $flow): PaymentReceipt
    {
        if (! is_array($body)) {
            throw new RuntimeException(sprintf(
                'MyCoolPay %s returned a non-object response body.',
                $flow->value,
            ));
        }

        $transactionRef = self::str($body, 'transaction_ref');
        if ($transactionRef === '') {
            throw new RuntimeException(sprintf(
                'MyCoolPay %s response is missing transaction_ref.',
                $flow->value,
            ));
        }

        $paymentUrl = self::optStr($body, 'payment_url');

        // Status mapping: "SUCCESS/CANCELED/FAILED/PENDING/REQUIRE_OTP/USSD_OK"
        // → canonical PaymentStatus.
        $rawStatus = self::optStr($body, 'transaction_status')
            ?? self::optStr($body, 'action')
            ?? '';
        $canonical = $rawStatus === '' ? PaymentStatus::Pending : $this->statusMapper->map($rawStatus);

        // `requiresAction` is true when the provider is waiting for an
        // out-of-band step from the customer. Payin returns an `action`
        // string; we set the flag whenever the canonical status is
        // Pending and a non-terminal action was reported.
        $requiresAction = $canonical === PaymentStatus::Pending
            && $rawStatus !== ''
            && $rawStatus !== 'PENDING';

        $errorMessage = self::optStr($body, 'transaction_message');

        return new PaymentReceipt(
            transactionRef: $transactionRef,
            status: $canonical,
            paymentUrl: $paymentUrl,
            requiresAction: $requiresAction,
            actionHint: $rawStatus === '' ? null : $rawStatus,
            errorMessage: $canonical === PaymentStatus::Failed ? $errorMessage : null,
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function str(array $body, string $key): string
    {
        $v = $body[$key] ?? '';

        return is_string($v) ? $v : '';
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function optStr(array $body, string $key): ?string
    {
        $v = $body[$key] ?? null;

        return is_string($v) ? $v : null;
    }
}
