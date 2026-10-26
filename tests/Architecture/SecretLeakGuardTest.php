<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\Dto\LogoutOperatorCommand;
use Core\Identity\Application\Dto\OperatorLoginChallenge;
use Core\Identity\Application\Dto\OperatorMfaEnrollment;
use Core\Identity\Application\Dto\OperatorSession;
use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Contracts\AcceptedOrganizationInvitation;
use Core\Identity\Contracts\AcceptOrganizationInvitationCommand;
use Core\Identity\Contracts\ApiKeySummaryView;
use Core\Identity\Contracts\CreatedOperatorView;
use Core\Identity\Contracts\CreatedOrganizationInvitation;
use Core\Identity\Contracts\CreateOperator;
use Core\Identity\Contracts\CreateOperatorCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Identity\Contracts\IssuedApiKeyView;
use Core\Identity\Contracts\ProjectContext;
use Core\Identity\Contracts\ProvisionedProjectView;
use Core\Identity\Contracts\RevokeApiKeyCommand;
use Core\Identity\Contracts\RotateApiKeyCommand;
use Core\Identity\Domain\Port\TotpProvider;
use Core\Identity\Infrastructure\Security\AlwaysValidTotpProvider;
use Core\Identity\OperatorRole;
use Core\Notification\Domain\Model\Notification;
use Core\Notification\Domain\Port\NotificationRepository;
use Core\Notification\NotificationStatus;
use Core\Payment\Contracts\PaymentView;
use Core\Payment\Contracts\RegisterProviderApplicationCommand;
use Core\Payment\Domain\Model\ProviderApplication;
use Core\Payment\Domain\Port\ProviderApplicationRepository;
use Core\Support\Money\Money;
use Core\Wallet\Domain\Model\Wallet;
use Core\Wallet\Domain\Model\WalletBalance;
use Core\Wallet\Domain\Port\WalletBalanceRepository;
use Core\Wallet\Domain\Port\WalletRepository;
use Core\Webhook\Contracts\RegisteredWebhookEndpointView;
use Core\Webhook\Contracts\RotatedWebhookSecretView;
use Core\Webhook\Contracts\WebhookEndpointView;
use DateTimeImmutable;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Architecture\SecretLeak\ApiDtoInventory;
use Tests\Architecture\SecretLeak\SecretLeakDetector;
use Tests\TestCase;

/**
 * G14 — no usable secret in an API response, except ONCE, in the response
 * that creates it (ADR-0081, S-07).
 *
 * Two halves, because "a field whose name looks like a secret" and "a
 * response that carries one" are different questions:
 *
 *  1. STATIC — every secret-looking public property of an API DTO
 *     (`packages/*\/src/Contracts/`, `Application/Dto/`) and every
 *     secret-looking `JsonResource` key must be accounted for in the lists
 *     below. Adding such a field to a DTO fails the build until a reviewer
 *     names it, with a reason.
 *
 *  2. DYNAMIC — the real application is driven over HTTP; every
 *     secret-looking key of every JSON response must be one the route that
 *     answered is allowed to return. This half sees the bodies controllers
 *     build by hand, which no DTO scan can.
 *
 * The lists cannot rot into blanket permissions: an entry for a field that
 * no longer exists, a "request-only" DTO that production code returns, a
 * route that no longer exists, or an allowed key that no exercised response
 * returns, all fail the build.
 */
final class SecretLeakGuardTest extends TestCase
{
    /**
     * THE S-07 WHITELIST — the only responses allowed to carry a usable
     * secret, keyed by route name. Every one of them is the response that
     * CREATES the secret; no read route may ever appear here.
     *
     * `keys` are the JSON keys the route may return, `fields` the DTO
     * properties that feed them (their snake_case name must be one of the
     * keys).
     *
     * @var array<string, array{keys: list<string>, fields: list<string>, why: string}>
     */
    private const SHOWN_ONCE_AT_CREATION = [
        'admin.projects.create' => [
            'keys' => ['api_key', 'plaintext_token'],
            'fields' => [ProvisionedProjectView::class.'::$apiKey', IssuedApiKeyView::class.'::$plaintextToken'],
            'why' => 'API key issuance: the project is provisioned with its first key (ADR-0066), shown in clear '
                .'once; only its hash is stored (ADR-0082 §2). `api_key` is the object that wraps it.',
        ],
        'admin.organizations.invitations.create' => [
            'keys' => ['token'],
            'fields' => [CreatedOrganizationInvitation::class.'::$token'],
            'why' => 'Activation token of an invitation (ADR-0069 §4): the account is created by this token, '
                .'shown once to the inviter, stored hashed, usable once.',
        ],
        'admin.organization-invitations.accept' => [
            'keys' => ['challenge_token'],
            'fields' => [AcceptedOrganizationInvitation::class.'::$challengeToken'],
            'why' => 'Accepting the invitation creates the account and its single-use, 5-minute login challenge '
                .'(the one login would return): no session, no password chosen by the system.',
        ],
        'webhook-endpoints.register' => [
            'keys' => ['secret'],
            'fields' => [RegisteredWebhookEndpointView::class.'::$secret'],
            'why' => 'Signing secret of a webhook endpoint, at its registration (ADR-0040 §5). Never listed again.',
        ],
        'webhook-endpoints.rotate-secret' => [
            'keys' => ['secret'],
            'fields' => [RotatedWebhookSecretView::class.'::$secret'],
            'why' => 'The NEW signing secret, at the rotation that creates it (ADR-0082 §5).',
        ],
        'payments.start' => [
            'keys' => ['callback_secret'],
            'fields' => [PaymentView::class.'::$callbackSecret'],
            'why' => 'Callback secret of one payment, on the 201 that creates the payment (legacy route, G9).',
        ],
        'payments.start.payin' => [
            'keys' => ['callback_secret'],
            'fields' => [PaymentView::class.'::$callbackSecret'],
            'why' => 'Callback secret of one payment, on the 201 that creates it. Every other producer of '
                .'PaymentView passes null.',
        ],
        'payments.start.paylink' => [
            'keys' => ['callback_secret'],
            'fields' => [PaymentView::class.'::$callbackSecret'],
            'why' => 'Callback secret of one payment, on the 201 that creates it.',
        ],
        'operators.login' => [
            'keys' => ['challenge_token', 'access_token'],
            'fields' => [OperatorLoginChallenge::class.'::$challengeToken'],
            'why' => 'Login creates a single-use MFA challenge (or, with IDENTITY_DISABLE_MFA, the session '
                .'token itself — created by this very call).',
        ],
        'operators.mfa.enroll' => [
            'keys' => ['secret', 'otpauth_uri', 'confirm_challenge_token'],
            'fields' => [
                OperatorMfaEnrollment::class.'::$secret',
                OperatorMfaEnrollment::class.'::$otpauthUri',
                OperatorMfaEnrollment::class.'::$confirmChallengeToken',
            ],
            'why' => 'TOTP enrollment creates the shared secret (raw and as an otpauth:// URI, which embeds it) '
                .'and the single-use confirmation challenge (ADR-0044). Only an encrypted copy is kept.',
        ],
        'operators.mfa.confirm' => [
            'keys' => ['access_token'],
            'fields' => [OperatorSession::class.'::$accessToken'],
            'why' => 'The console session token, at the step that creates it (ADR-0044, S-05).',
        ],
        'operators.mfa.verify' => [
            'keys' => ['access_token'],
            'fields' => [OperatorSession::class.'::$accessToken'],
            'why' => 'The console session token, at the step that creates it (ADR-0044, S-05).',
        ],
    ];

    /**
     * KNOWN S-07 VIOLATIONS — tolerated so the guard can be active today,
     * listed apart so nobody mistakes them for legitimate "shown once"
     * secrets. This list may only shrink.
     *
     * @var array<string, array{keys: list<string>, fields: list<string>, why: string}>
     */
    private const KNOWN_S07_VIOLATIONS = [
        'admin.operators.create' => [
            'keys' => ['plaintext_password'],
            'fields' => [CreatedOperatorView::class.'::$plaintextPassword'],
            'why' => 'VIOLATES S-07 "aucun mot de passe choisi par le système ; comptes créés par jeton '
                .'d\'activation": the Core generates the operator\'s password and returns it (ADR-0050). '
                .'Remove this entry when operator creation moves to the activation-token flow invitations '
                .'already use (ADR-0069 §4).',
        ],
    ];

    /**
     * DTO properties whose name looks like a secret but whose value is not
     * a usable one: identifiers, prefixes, dates.
     *
     * @var array<string, string>
     */
    private const NOT_A_SECRET_FIELDS = [
        ApiKeySummaryView::class.'::$replacedByApiKeyId' => 'UUID of the replacing key (ADR-0082 §4).',
        IssuedApiKeyView::class.'::$apiKeyId' => 'UUID of the issued key, not the key.',
        ProjectContext::class.'::$apiKeyId' => 'UUID of the authenticating key (audit, metrics).',
        ProjectContext::class.'::$apiKeyPrefix' => 'Visible prefix of a key: identifies it, does not authenticate.',
        RotatedWebhookSecretView::class.'::$previousSecretExpiresAt' => 'When the previous secret stops signing.',
        WebhookEndpointView::class.'::$previous_secret_expires_at' => 'Same date, in the endpoint read model.',
    ];

    /**
     * JSON keys whose name looks like a secret but whose value is not one,
     * wherever they appear.
     *
     * @var array<string, string>
     */
    private const NOT_A_SECRET_KEYS = [
        'previous_secret_expires_at' => 'A date: when the previous signing secret stops signing (ADR-0082 §5).',
        'token_type' => 'The literal "Bearer".',
    ];

    /**
     * Secret-named properties of DTOs that travel IN, never OUT. Checked:
     * no production method may return their class.
     *
     * @var array<string, string>
     */
    private const REQUEST_ONLY_FIELDS = [
        LoginOperatorCommand::class.'::$password' => 'The operator\'s own password, checked against its hash.',
        LogoutOperatorCommand::class.'::$bearerToken' => 'The session token being revoked.',
        EnrollOperatorMfaCommand::class.'::$challengeToken' => 'Challenge received at login, sent back.',
        ConfirmOperatorMfaEnrollmentCommand::class.'::$challengeToken' => 'Challenge received at enrollment, sent back.',
        VerifyOperatorMfaCommand::class.'::$challengeToken' => 'Challenge received at login, sent back.',
        AcceptOrganizationInvitationCommand::class.'::$token' => 'The activation token, consumed.',
        AcceptOrganizationInvitationCommand::class.'::$password' => 'The password the invitee chooses.',
        RevokeApiKeyCommand::class.'::$apiKeyId' => 'UUID of the key to revoke.',
        RotateApiKeyCommand::class.'::$apiKeyId' => 'UUID of the key to rotate.',
        RegisterProviderApplicationCommand::class.'::$privateKey' => 'Write-only provider key (ADR-0060).',
    ];

    #[Test]
    #[Group('arch')]
    public function no_api_dto_may_carry_a_secret_outside_the_inventory(): void
    {
        $accounted = array_merge(
            self::allowedFields(),
            array_keys(self::NOT_A_SECRET_FIELDS),
            array_keys(self::REQUEST_ONLY_FIELDS),
        );

        $offenders = [];

        foreach (ApiDtoInventory::secretLookingFields(base_path()) as $field) {
            if (! in_array($field, $accounted, true)) {
                $offenders[] = sprintf('  %s (matched "%s")', $field, implode('", "', SecretLeakDetector::matchedTerms(self::propertyOf($field))));
            }
        }

        foreach (ApiDtoInventory::jsonResourceSecretKeys(base_path()) as $resourceKey) {
            $offenders[] = '  JsonResource key '.$resourceKey;
        }

        self::assertSame(
            [],
            $offenders,
            "G14 — secret-looking API DTO fields not accounted for (ADR-0081 S-07: no usable secret in an API\n"
            ."response, except once at its creation). Name each one in SecretLeakGuardTest: SHOWN_ONCE_AT_CREATION\n"
            ."(with the creation route), NOT_A_SECRET_FIELDS or REQUEST_ONLY_FIELDS, with the reason:\n"
            .implode("\n", $offenders),
        );
    }

    #[Test]
    #[Group('arch')]
    public function the_inventory_describes_the_code_as_it_is(): void
    {
        $existing = ApiDtoInventory::secretLookingFields(base_path());
        $returned = ApiDtoInventory::returnedTypes(base_path());

        $routes = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $routes[(string) $route->getName()] = true;
        }

        $problems = [];

        foreach (array_merge(self::allowedFields(), array_keys(self::NOT_A_SECRET_FIELDS), array_keys(self::REQUEST_ONLY_FIELDS)) as $field) {
            if (! in_array($field, $existing, true)) {
                $problems[] = sprintf('%s is listed, but no such secret-looking DTO field exists any more.', $field);
            }
        }

        foreach (self::allowances() as $route => $allowance) {
            if (! isset($routes[$route])) {
                $problems[] = sprintf('"%s" is whitelisted, but there is no route with that name any more.', $route);
            }

            foreach ($allowance['fields'] as $field) {
                if (! isset($returned[self::classOf($field)])) {
                    $problems[] = sprintf('%s is "shown once", but no production code returns %s.', $field, self::classOf($field));
                }

                if (! in_array(Str::snake(self::propertyOf($field)), $allowance['keys'], true)) {
                    $problems[] = sprintf('%s is declared for "%s", which may only return: %s.', $field, $route, implode(', ', $allowance['keys']));
                }
            }
        }

        foreach (array_keys(self::REQUEST_ONLY_FIELDS) as $field) {
            if (isset($returned[self::classOf($field)])) {
                $problems[] = sprintf('%s is declared request-only, but production code returns %s.', $field, self::classOf($field));
            }
        }

        self::assertSame([], $problems, "G14 — the secret inventory is out of date:\n".implode("\n", $problems));
    }

    #[Test]
    public function no_api_response_carries_a_secret_outside_its_creation(): void
    {
        DB::beginTransaction();

        try {
            $allowances = self::allowances();
            $offenders = [];
            $seen = [];

            foreach ($this->exerciseTheApi() as $response) {
                $request = $response->baseRequest;
                self::assertNotNull($request);
                $route = $request->route();
                $routeName = $route instanceof RoutingRoute ? (string) $route->getName() : '';
                $label = sprintf('%s %s [%s]', $request->getMethod(), $request->getPathInfo(), $routeName);

                $status = $response->baseResponse->getStatusCode();
                self::assertContains($status, [200, 201, 202], sprintf(
                    '%s answered %d — a failing probe proves nothing about a successful response.', $label, $status,
                ));

                $payload = $response->json();
                self::assertIsArray($payload, $label.' did not answer JSON.');

                foreach (SecretLeakDetector::secretishKeysIn($payload) as $finding) {
                    $key = $finding['key'];
                    $seen[$routeName][$key] = true;

                    if (isset(self::NOT_A_SECRET_KEYS[$key]) || in_array($key, $allowances[$routeName]['keys'] ?? [], true)) {
                        continue;
                    }

                    $offenders[] = sprintf('  %s → %s (matched "%s")', $label, $finding['path'], implode('", "', $finding['terms']));
                }
            }

            self::assertSame(
                [],
                $offenders,
                "G14 — API responses carry a secret-looking key outside the response that creates it (ADR-0081 S-07).\n"
                ."A usable secret is shown once, at its creation, and never again. If this IS a creation response,\n"
                ."add the route to SHOWN_ONCE_AT_CREATION in SecretLeakGuardTest, with the reason:\n"
                .implode("\n", $offenders),
            );

            // An allowed key nobody returns is a hole with a sign on it.
            $stale = [];
            foreach ($allowances as $route => $allowance) {
                foreach ($allowance['keys'] as $key) {
                    if (! isset($seen[$route][$key])) {
                        $stale[] = sprintf('  %s may return "%s", but no exercised response did.', $route, $key);
                    }
                }
            }

            self::assertSame([], $stale, "G14 — whitelist entries no probe confirms (probe them or remove them):\n".implode("\n", $stale));
        } finally {
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
    }

    /**
     * @return array<string, array{keys: list<string>, fields: list<string>, why: string}>
     */
    private static function allowances(): array
    {
        return self::SHOWN_ONCE_AT_CREATION + self::KNOWN_S07_VIOLATIONS;
    }

    /**
     * @return list<string>
     */
    private static function allowedFields(): array
    {
        $fields = [];
        foreach (self::allowances() as $allowance) {
            $fields = [...$fields, ...$allowance['fields']];
        }

        return array_values(array_unique($fields));
    }

    private static function classOf(string $field): string
    {
        return Str::before($field, '::$');
    }

    private static function propertyOf(string $field): string
    {
        return Str::after($field, '::$');
    }

    /**
     * Drives the real application through every JSON route a fixture can
     * reach, creation routes included, and returns the responses.
     *
     * @return list<TestResponse<Response>>
     */
    private function exerciseTheApi(): array
    {
        // The MFA steps need a code the test can produce without the wall
        // clock; the shape of the responses is unchanged.
        $this->app->bind(TotpProvider::class, AlwaysValidTotpProvider::class);

        $this->fakeProviders();

        $organization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('G14 Org'), actor: 'service:test', correlationId: null);
        $secondOrganization = app(CreateOrganization::class)->create(new CreateOrganizationCommand('G14 Second Org'), actor: 'service:test', correlationId: null);
        $provisioned = app(CreateProject::class)->create(new CreateProjectCommand($organization->id, 'G14 Project'), actor: 'service:test', correlationId: null);
        $secondProject = app(CreateProject::class)->create(new CreateProjectCommand($secondOrganization->id, 'G14 Second Project'), actor: 'service:test', correlationId: null);

        $projectId = $provisioned->project->id;
        $project = ['Authorization' => 'Bearer '.$provisioned->apiKey->plaintextToken];

        [$partyId, $walletId, $notificationId] = $this->seedReadModels($projectId);

        $responses = [];

        // ── the operator plane: login → enroll → confirm, then login → verify
        $email = Str::uuid().'@example.com';
        $password = app(CreateOperator::class)->create(
            new CreateOperatorCommand($email, 'G14 Operator', OperatorRole::PlatformAdmin->value),
            actor: 'service:test',
            correlationId: null,
        )->plaintextPassword;

        $responses[] = $login = $this->postJson('/v1/operators/login', ['email' => $email, 'password' => $password]);
        $responses[] = $enroll = $this->postJson('/v1/operators/mfa/enroll', ['challenge_token' => self::jsonString($login, 'challenge_token')]);
        $responses[] = $confirm = $this->postJson('/v1/operators/mfa/confirm', ['challenge_token' => self::jsonString($enroll, 'confirm_challenge_token'), 'code' => '000000']);
        $responses[] = $secondLogin = $this->postJson('/v1/operators/login', ['email' => $email, 'password' => $password]);
        $responses[] = $this->postJson('/v1/operators/mfa/verify', ['challenge_token' => self::jsonString($secondLogin, 'challenge_token'), 'code' => '000000']);

        config(['identity.disable_mfa' => true]);
        $responses[] = $this->postJson('/v1/operators/login', ['email' => $email, 'password' => $password]);
        config(['identity.disable_mfa' => false]);

        $operator = ['Authorization' => 'Bearer '.self::jsonString($confirm, 'access_token')];

        // ── invitation: created by an owner, accepted by the invitee
        $responses[] = $invitation = $this->postJson('/v1/admin/organizations/'.$organization->id.'/invitations', [
            'email' => Str::uuid().'@example.com',
            'role' => 'org_viewer',
        ], $operator);
        $responses[] = $this->postJson('/v1/admin/organization-invitations/accept', [
            'token' => self::jsonString($invitation, 'token'),
            'name' => 'G14 Invitee',
            'password' => 'a-long-enough-passphrase-g14',
        ]);

        // ── the public API, creation side
        $responses[] = $endpoint = $this->postJson('/v1/webhook-endpoints', ['url' => 'https://example.com/hook', 'events' => ['payment.status_changed']], $project);
        $endpointId = self::jsonString($endpoint, 'id');
        $responses[] = $this->postJson('/v1/webhook-endpoints/'.$endpointId.'/rotate-secret', [], $project);
        $responses[] = $this->postJson('/v1/webhook-endpoints/'.$endpointId.'/test', [], $project);

        $paymentId = '';
        foreach (['/v1/payments/payin', '/v1/payments/paylink', '/v1/payments'] as $uri) {
            $responses[] = $payment = $this->postJson($uri, [
                'channel' => 'mtn_momo', 'amount' => 1_000, 'currency' => 'XAF', 'reason' => 'G14 '.$uri,
                'callback_url' => 'https://example.com/callbacks/payments',
            ], $project + ['Idempotency-Key' => (string) Str::uuid()]);
            $paymentId = self::jsonString($payment, 'id');
        }

        // ── the public API, read side
        foreach ([
            '/v1/whoami',
            '/v1/webhook-endpoints',
            '/v1/payments/'.$paymentId,
            '/v1/notifications/'.$notificationId,
            '/v1/wallets/'.$walletId.'/balance',
            '/v1/wallets/'.$walletId.'/transactions',
            '/v1/parties/'.$partyId.'/wallets',
        ] as $uri) {
            $responses[] = $this->getJson($uri, $project);
        }

        // ── the admin JSON API
        foreach ([
            '/v1/admin/audit-log',
            '/v1/admin/operators',
            '/v1/admin/projects',
            '/v1/admin/projects/'.$projectId.'/payments',
            '/v1/admin/projects/'.$projectId.'/wallets',
            '/v1/admin/projects/'.$projectId.'/notifications',
            '/v1/admin/organizations/'.$organization->id.'/members',
            '/v1/admin/webhook-deliveries/dead',
            '/v1/admin/payment-reconciliation-preview',
            '/v1/operators/me',
        ] as $uri) {
            $responses[] = $this->getJson($uri, $operator);
        }

        $responses[] = $this->postJson('/v1/admin/organizations', ['name' => 'G14 Organization'], $operator);
        $responses[] = $this->postJson('/v1/admin/operators', [
            'email' => Str::uuid().'@example.com', 'name' => 'G14 Second Operator', 'role' => OperatorRole::ReadOnlyAuditor->value,
        ], $operator);
        $responses[] = $this->postJson('/v1/admin/projects', ['organization_id' => $organization->id, 'name' => 'G14 Admin Project'], $operator);
        $responses[] = $this->postJson('/v1/admin/payment-provider-applications', [
            'project_id' => $secondProject->project->id, 'provider' => 'my-coolpay', 'public_key' => 'pub-g14-admin', 'private_key' => 'private-g14-admin',
        ], $operator);

        return $responses;
    }

    /**
     * One wallet with a balance, one notification, one provider
     * application and one audit entry, so the read routes display a row.
     *
     * @return array{string, string, string}
     */
    private function seedReadModels(string $projectId): array
    {
        $partyId = (string) Str::uuid();
        $walletId = (string) Str::uuid();
        app(WalletRepository::class)->save(new Wallet($walletId, $projectId, $partyId, 'XAF', 'active'));
        app(WalletBalanceRepository::class)->initialize($walletId, 'XAF');
        app(WalletBalanceRepository::class)->save(new WalletBalance($walletId, Money::ofMinor(2_500, 'XAF'), Money::zero('XAF'), 1));

        $notificationId = (string) Str::uuid();
        app(NotificationRepository::class)->create(new Notification(
            id: $notificationId,
            projectId: $projectId,
            channel: 'whatsapp',
            toPartyRef: $partyId,
            contentType: 'text',
            idempotencyKey: (string) Str::uuid(),
            status: NotificationStatus::Delivered,
            providerCode: 'whatsapp-waha',
            providerMessageId: null,
            errorMessage: null,
        ));

        app(ProviderApplicationRepository::class)->save(new ProviderApplication(
            projectId: $projectId,
            providerCode: 'my-coolpay',
            publicKey: 'pub-g14',
            privateKey: 'private-g14',
        ));

        app(AuditLogger::class)->record(new AuditEntry(
            actor: 'service:test',
            action: 'g14.probe',
            resource: 'project:'.$projectId,
            before: null,
            after: null,
            context: [],
            correlationId: null,
            occurredAt: new DateTimeImmutable,
        ));

        return [$partyId, $walletId, $notificationId];
    }

    private function fakeProviders(): void
    {
        // A fresh transaction reference per call: the provider reference is
        // unique per payment.
        Http::fake([
            'my-coolpay.com/*' => static function (ClientRequest $request) {
                $reference = 'tx-'.substr(hash('sha256', $request->url().'|'.$request->body()), 0, 16);

                return Http::response(str_contains($request->url(), '/paylink')
                    ? ['status' => 'success', 'transaction_ref' => $reference, 'payment_url' => 'https://my-coolpay.com/pay/'.$reference]
                    : ['status' => 'success', 'transaction_ref' => $reference, 'action' => 'PENDING'], 201);
            },
            'example.com/*' => Http::response(['ok' => true], 200),
        ]);
    }

    /**
     * @param TestResponse<Response> $response
     */
    private static function jsonString(TestResponse $response, string $key): string
    {
        $value = $response->json($key);
        self::assertIsString($value, sprintf('Expected "%s" in the response, got: %s', $key, (string) json_encode($response->json())));

        return $value;
    }
}
