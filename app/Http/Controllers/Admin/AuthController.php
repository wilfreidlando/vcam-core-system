<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

/**
 * The auth controller is a thin HTTP wrapper that uses Laravel's dynamic
 * Session facade and Request::validate() — both of which PHPStan cannot
 * infer return types for without a per-call @var cast on every access.
 * The integration tests (tests/Feature/Admin) cover the runtime behaviour
 * so the static-analysis suppression is bounded to this file.
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
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Login / MFA / logout flow for the admin web UI.
 *
 * Every step delegates to the same Identity use cases that the API
 * `LoginOperatorController` uses — there is exactly one place where
 * operator auth logic lives. The web layer's job is purely form
 * handling + Sanctum cookie wiring.
 *
 * Sanctum's "stateful" mode (configured in config/sanctum.php for the
 * `web` guard) makes Sanctum recognise the `web_at` cookie as a session
 * bearer. The middleware `ResolveOperatorSession` reads that cookie on
 * subsequent requests and resolves the operator via the existing
 * `ResolveOperatorFromBearerToken` use case — the only authentication
 * logic in the entire codebase.
 */
final class AuthController
{
    /** Cookie name set by Sanctum for the `web` guard. */
    private const COOKIE_NAME = 'web_at';

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
        $data = /* @phpstan-ignore-next-line */ $request->validate([
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
        $data = /* @phpstan-ignore-next-line */ $request->validate([
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
        $data = /* @phpstan-ignore-next-line */ $request->validate([
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

    public function logout(Request $request): RedirectResponse
    {
        $tokenValue = $request->cookie(self::COOKIE_NAME);
        if (is_string($tokenValue) && $tokenValue !== '') {
            $parts = explode('|', $tokenValue, 2);
            $plaintext = $parts[0] ?? '';
            try {
                ($this->logoutOperator)(new LogoutOperatorCommand(
                    bearerToken: $plaintext,
                ));
            } catch (Throwable) {
                // Logout must not throw — even if the revoke fails, we
                // want the cookie cleared and the user redirected home.
            }
        }

        Session::invalidate();
        Session::regenerateToken();
        cookie()->expire(self::COOKIE_NAME);

        return redirect()->route('admin.login.show')
            ->with('status', 'Signed out.');
    }

    /**
     * Persist the issued Sanctum token in the session cookie jar and
     * redirect the operator to the dashboard.
     */
    private function completeLogin(string $plaintext, string $message): RedirectResponse
    {
        $token = PersonalAccessToken::findToken($plaintext);
        if ($token instanceof PersonalAccessToken) {
            $cookieValue = $plaintext.'|'.$token->getKey();
            // Sanctum's web guard expects the cookie value in the form
            // "<plaintext>|<id>". It reads it from the request via
            // EnsureFrontendRequestsAreStateful.
            // @phpstan-ignore-next-line — dynamic property access on Laravel model; cast ensures DateTime.
            $expiresAt = $token->expires_at;
            $minutes = $expiresAt !== null && method_exists($expiresAt, 'diffInMinutes')
                ? max(1, (int) $expiresAt->diffInMinutes(now()))
                : 60 * 8;
            cookie()->queue(cookie()->make(self::COOKIE_NAME, $cookieValue, $minutes));
        }

        Session::forget(['admin.challenge_token', 'admin.challenge_purpose']);

        return redirect()->route('admin.dashboard')
            ->with('status', $message);
    }

    private function redirectToLogin(string $error): RedirectResponse
    {
        return redirect()->route('admin.login.show')->withErrors(['email' => $error]);
    }

    private function render(string $view, string $title, array $params = []): Response
    {
        /** @var array<string, mixed> $merge */
        $merge = array_merge(['title' => $title], $params);

        return response()->view($view, $merge);
    }
}
