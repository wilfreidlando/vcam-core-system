<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/**
 * The auth controller is a thin HTTP wrapper that uses Laravel's dynamic
 * Session facade and Request::validate() — both of which PHPStan cannot
 * infer return types for. Each dynamic call carries its own targeted
 * ignore comment rather than excluding this file wholesale (review
 * finding, Lot 6 follow-up: a blanket exclusion previously hid a call to
 * a nonexistent method in this same file).
 */

use Core\Identity\Application\Dto\ConfirmOperatorMfaEnrollmentCommand;
use Core\Identity\Application\Dto\EnrollOperatorMfaCommand;
use Core\Identity\Application\Dto\LoginOperatorCommand;
use Core\Identity\Application\Dto\LogoutOperatorCommand;
use Core\Identity\Application\Dto\VerifyOperatorMfaCommand;
use Core\Identity\Application\UseCase\ConfirmOperatorMfaEnrollment;
use Core\Identity\Application\UseCase\EnrollOperatorMfa;
use Core\Identity\Application\UseCase\LoginOperator;
use Core\Identity\Application\UseCase\LogoutOperator;
use Core\Identity\Application\UseCase\VerifyOperatorMfa;
use Core\Identity\Domain\Exception\InvalidMfaCode;
use Core\Identity\Domain\Exception\InvalidOperatorCredentials;
use Core\Identity\Domain\Exception\MfaAlreadyEnrolled;
use Core\Identity\Domain\Exception\MfaChallengeInvalid;
use Core\Identity\Domain\Exception\MfaNotEnrolled;
use Core\Identity\Domain\Exception\OperatorNotFound;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Login / MFA / logout flow for the admin web UI.
 *
 * Every step delegates to the same Identity use cases that the API
 * `LoginOperatorController` uses — there is exactly one place where
 * operator auth logic lives. The web layer's job is purely form
 * handling + session wiring.
 *
 * Session-based, not Sanctum stateful cookies (review finding, Lot 6
 * follow-up): Sanctum never re-exposes a token's plaintext after
 * issuance (only its hash is persisted), so "read the bearer token back
 * out of a resolved cookie session" has no solution on Sanctum's side —
 * an earlier version of this file tried exactly that and could not work
 * even in principle (wrong guard model, missing stateful middleware,
 * reversed cookie format). Instead: the plaintext bearer token is put in
 * the server-side session (encrypted, httponly, never sent to the
 * client) once, right after a successful login/MFA step, and
 * `ResolveOperatorSession` reads it back and feeds it to the exact same
 * `ResolveOperatorFromBearerToken` use case the API's bearer-token path
 * uses — zero duplicated auth logic, the goal the original design
 * intended but didn't achieve.
 */
final class AuthController
{
    /** Session key holding the operator's plaintext bearer token. */
    private const SESSION_KEY = 'operator_bearer_token';

    public function __construct(
        private readonly LoginOperator $loginOperator,
        private readonly EnrollOperatorMfa $enrollMfa,
        private readonly ConfirmOperatorMfaEnrollment $confirmMfaEnrollment,
        private readonly VerifyOperatorMfa $verifyMfa,
        private readonly LogoutOperator $logoutOperator,
    ) {}

    public function showLogin(): Response
    {
        return $this->render('admin.auth.login', 'Sign in');
    }

    public function login(Request $request): Response
    {
        /** @var array{email: string, password: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string', 'max:1024'],
        ]);

        try {
            $challenge = ($this->loginOperator)(new LoginOperatorCommand(
                email: $data['email'],
                password: $data['password'],
            ));
        } catch (InvalidOperatorCredentials|OperatorNotFound) {
            /** @var array<string, mixed> $only */
            $only = $request->only('email');

            return back()
                ->withInput($only)
                ->withErrors(['email' => 'Invalid credentials.']);
        }

        // Persist the challenge token in the session so the next request
        // (enrol or verify) can present it without re-authenticating.
        Session::regenerate();
        Session::put('admin.challenge_token', $challenge->challengeToken);
        Session::put('admin.challenge_purpose', $challenge->purpose);
        Session::put('admin.email', $data['email']);

        $purpose = $challenge->purpose;
        $redirect = match ($purpose) {
            'mfa_enrollment' => 'admin.mfa.enroll.show',
            'mfa' => 'admin.mfa.verify.show',
            default => null,
        };
        if ($redirect === null) {
            return back()->withErrors(['email' => 'Unsupported MFA challenge purpose.']);
        }

        return redirect()->route($redirect);
    }

    public function showMfaEnroll(): Response
    {
        $token = Session::get('admin.challenge_token');
        if (! is_string($token)) {
            return $this->redirectToLogin('Please sign in again.');
        }
        $emailRaw = Session::get('admin.email', '');
        $email = is_string($emailRaw) ? $emailRaw : '';

        try {
            $enrolment = ($this->enrollMfa)(new EnrollOperatorMfaCommand(
                challengeToken: $token,
            ));
        } catch (MfaChallengeInvalid) {
            return $this->redirectToLogin('MFA challenge expired or invalid. Please sign in again.');
        } catch (MfaAlreadyEnrolled) {
            return redirect()->route('admin.mfa.verify.show')
                ->with('status', 'MFA already enrolled. Please verify.');
        }

        return $this->render('admin.auth.mfa-enroll', 'Set up MFA', [
            'email' => $email,
            'secret' => $enrolment->secret,
            'otpauthUri' => $enrolment->otpauthUri,
            'confirmChallengeToken' => $enrolment->confirmChallengeToken,
        ]);
    }

    public function confirmMfaEnrollment(Request $request): Response
    {
        /** @var array{challenge_token: string, code: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        try {
            $session = ($this->confirmMfaEnrollment)(new ConfirmOperatorMfaEnrollmentCommand(
                challengeToken: $data['challenge_token'],
                code: $data['code'],
            ));
        } catch (InvalidMfaCode|MfaChallengeInvalid) {
            return back()->withErrors(['code' => 'Invalid or expired code.']);
        }

        return $this->completeLogin($session->accessToken, 'MFA enrolled.');
    }

    public function showMfaVerify(): Response
    {
        if (Session::get('admin.challenge_token') === null) {
            return $this->redirectToLogin('Please sign in again.');
        }
        $emailRaw = Session::get('admin.email', '');
        $email = is_string($emailRaw) ? $emailRaw : '';

        return $this->render('admin.auth.mfa-verify', 'Verify MFA', ['email' => $email]);
    }

    public function verifyMfa(Request $request): Response
    {
        /** @var array{challenge_token: string, code: string} $data */
        // @phpstan-ignore-next-line — Laravel's Request::validate() return type is dynamic-array in stubs.
        $data = $request->validate([
            'challenge_token' => ['required', 'string'],
            'code' => ['required', 'string', 'min:6', 'max:8'],
        ]);

        try {
            $session = ($this->verifyMfa)(new VerifyOperatorMfaCommand(
                challengeToken: $data['challenge_token'],
                code: $data['code'],
            ));
        } catch (InvalidMfaCode|MfaChallengeInvalid|MfaNotEnrolled) {
            return back()->withErrors(['code' => 'Invalid code or MFA not enrolled.']);
        }

        return $this->completeLogin($session->accessToken, 'Welcome back.');
    }

    public function logout(): RedirectResponse
    {
        $tokenValue = Session::get(self::SESSION_KEY);
        if (is_string($tokenValue) && $tokenValue !== '') {
            try {
                ($this->logoutOperator)(new LogoutOperatorCommand(
                    bearerToken: $tokenValue,
                ));
            } catch (Throwable) {
                // Logout must not throw — even if the revoke fails, we
                // want the session cleared and the user redirected home.
            }
        }

        Session::invalidate();
        Session::regenerateToken();

        return redirect()->route('admin.login.show')
            ->with('status', 'Signed out.');
    }

    /**
     * Persist the issued bearer token's plaintext in the server-side
     * session and redirect the operator to the dashboard.
     */
    private function completeLogin(string $plaintext, string $message): RedirectResponse
    {
        Session::put(self::SESSION_KEY, $plaintext);
        Session::forget(['admin.challenge_token', 'admin.challenge_purpose']);
        Session::regenerate();

        return redirect()->route('admin.dashboard')
            ->with('status', $message);
    }

    private function redirectToLogin(string $error): RedirectResponse
    {
        return redirect()->route('admin.login.show')->withErrors(['email' => $error]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function render(string $view, string $title, array $params = []): Response
    {
        /** @var array<string, mixed> $merge */
        $merge = array_merge(['title' => $title], $params);

        return response()->view($view, $merge);
    }
}
