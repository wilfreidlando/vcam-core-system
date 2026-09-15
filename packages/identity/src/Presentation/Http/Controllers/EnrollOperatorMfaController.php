<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Domain\Exception\MfaAlreadyEnrolled;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Core\Identity\Presentation\Http\Requests\OperatorChallengeRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class EnrollOperatorMfaController
{
    public function __construct(
        private readonly EnrollOperatorMfa $enrollOperatorMfa,
    ) {}

    /**
     * Start MFA enrollment (first login only).
     *
     * Generates a TOTP secret for the operator identified by the login
     * challenge, and returns it with a provisioning URI plus a fresh
     * challenge token for the confirmation step.
     */
    public function __invoke(OperatorChallengeRequest $request): JsonResponse|Response
    {
        /** @var string $challengeToken */
        $challengeToken = $request->validated('challenge_token');

        try {
            $enrollment = ($this->enrollOperatorMfa)(new EnrollOperatorMfaCommand($challengeToken));
        } catch (MfaChallengeInvalid|OperatorNotFound) {
            return ProblemDetails::unauthenticated('The login challenge is invalid or has expired.');
        } catch (MfaAlreadyEnrolled $e) {
            return ProblemDetails::make('mfa-already-enrolled', 'MFA is already enabled', 409, $e->getMessage());
        }

        return new JsonResponse([
            'secret' => $enrollment->secret,
            'otpauth_uri' => $enrollment->otpauthUri,
            'confirm_challenge_token' => $enrollment->confirmChallengeToken,
        ]);
    }
}
