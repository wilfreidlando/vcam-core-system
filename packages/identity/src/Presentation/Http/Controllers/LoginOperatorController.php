<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Http\Controllers;

use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\OperatorRevoked;
use Core\Identity\Presentation\Http\Requests\LoginOperatorRequest;
use Core\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

final class LoginOperatorController
{
    public function __construct(
        private readonly LoginOperator $loginOperator,
    ) {}

    /**
     * Step 1 of operator login: verify email and password.
     *
     * Returns a short-lived challenge token and a `purpose` telling the
     * caller which endpoint to call next — `mfa_enrollment` on first login,
     * `mfa` once MFA is already enabled.
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

        return new JsonResponse([
            'challenge_token' => $challenge->challengeToken,
            'purpose' => $challenge->purpose,
        ]);
    }
}
