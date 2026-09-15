<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Presentation\Http\Requests\OperatorMfaCodeRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class ConfirmOperatorMfaController
{
    public function __construct(
        private readonly ConfirmOperatorMfaEnrollment $confirmOperatorMfaEnrollment,
    ) {}

    /**
     * Confirm MFA enrollment and issue the access token.
     *
     * Verifies the TOTP code against the secret `mfa/enroll` generated. On
     * success, MFA becomes enabled and a Sanctum access token is issued.
     */
    public function __invoke(OperatorMfaCodeRequest $request): JsonResponse|Response
    {
        /** @var string $challengeToken */
        $challengeToken = $request->validated('challenge_token');
        /** @var string $code */
        $code = $request->validated('code');

        try {
            $session = ($this->confirmOperatorMfaEnrollment)(
                new ConfirmOperatorMfaEnrollmentCommand($challengeToken, $code),
            );
        } catch (MfaChallengeInvalid|OperatorNotFound|MfaNotEnrolled) {
            return ProblemDetails::unauthenticated('The login challenge is invalid or has expired.');
        } catch (InvalidMfaCode $e) {
            return ProblemDetails::unauthenticated($e->getMessage());
        }

        return new JsonResponse([
            'operator_id' => $session->operatorId,
            'access_token' => $session->accessToken,
        ], 201);
    }
}
