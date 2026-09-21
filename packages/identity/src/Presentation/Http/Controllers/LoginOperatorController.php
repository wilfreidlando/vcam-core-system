<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Domain\Port\OperatorTokenIssuer;
use Core\Identity\Presentation\Http\Requests\LoginOperatorRequest;
use Core\Support\Clock;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class LoginOperatorController
{
    public function __construct(
        private readonly LoginOperator $loginOperator,
        private readonly OperatorTokenIssuer $tokenIssuer,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Step 1 of operator login: verify email and password.
     *
     * Returns a short-lived challenge token and a `purpose` telling the
     * caller which endpoint to call next — `mfa_enrollment` on first login,
     * `mfa` once MFA is already enabled. When `IDENTITY_DISABLE_MFA=true`
     * the MFA step is skipped entirely and the response carries the
     * issued bearer token directly. That flag is NOT env-gated — every
     * runtime where it's set behaves this way, including production if
     * someone misconfigures it. The expected use is development / pilot.
     */
    public function __invoke(LoginOperatorRequest $request): JsonResponse|Response
    {
        /** @var string $email */
        $email = $request->validated('email');
        /** @var string $password */
        $password = $request->validated('password');

        try {
            $challenge = ($this->loginOperator)(new LoginOperatorCommand($email, $password));
        } catch (InvalidOperatorCredentials) {
            return ProblemDetails::unauthenticated('The provided credentials are invalid.');
        } catch (OperatorRevoked) {
            return ProblemDetails::forbidden('This operator has been revoked.');
        }

        if ((bool) config('identity.disable_mfa', false)) {
            $accessToken = $this->tokenIssuer->issue(
                $challenge->operatorId,
                'api:'.now()->getTimestamp().':mfa-disabled',
            );

            $this->audit->record(new AuditEntry(
                actor: 'operator:'.$challenge->operatorId,
                action: 'operator.mfa_skipped',
                resource: 'operator:'.$challenge->operatorId,
                before: null,
                after: ['reason' => 'IDENTITY_DISABLE_MFA', 'plane' => 'api'],
                context: [],
                correlationId: null,
                occurredAt: $this->clock->now(),
            ));

            return new JsonResponse([
                'access_token' => $accessToken,
                'token_type' => 'Bearer',
                'mfa' => false,
            ], 200);
        }

        return new JsonResponse([
            'challenge_token' => $challenge->challengeToken,
            'purpose' => $challenge->purpose,
        ]);
    }
}
