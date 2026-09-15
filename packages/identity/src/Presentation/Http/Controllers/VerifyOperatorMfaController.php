<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Application\UseCase\VerifyOperatorMfa;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Presentation\Http\Requests\OperatorMfaCodeRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class VerifyOperatorMfaController
{
    public function __construct(
        private readonly VerifyOperatorMfa $verifyOperatorMfa,
    ) {}

    /**
     * Step 2 of a returning operator's login: verify the TOTP code.
     *
     * Used once MFA is already enabled (`purpose: "mfa"` from the login
     * step). On success, issues a Sanctum access token.
     */
    public function __invoke(OperatorMfaCodeRequest $request): JsonResponse|Response
    {
        /** @var string $challengeToken */
        $challengeToken = $request->validated('challenge_token');
        /** @var string $code */
        $code = $request->validated('code');

        try {
            $session = ($this->verifyOperatorMfa)(new VerifyOperatorMfaCommand($challengeToken, $code));
        } catch (MfaChallengeInvalid|OperatorNotFound|MfaNotEnrolled) {
            return ProblemDetails::unauthenticated('The login challenge is invalid or has expired.');
        } catch (OperatorRevoked $e) {
            return ProblemDetails::forbidden($e->getMessage());
        } catch (InvalidMfaCode $e) {
            return ProblemDetails::unauthenticated($e->getMessage());
        }

        return new JsonResponse([
            'operator_id' => $session->operatorId,
            'access_token' => $session->accessToken,
        ], 201);
    }
}
