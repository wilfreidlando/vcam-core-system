<?php

declare(strict_types=1);

namespace Tests\Support;

use Core\Identity\Domain\Model\Operator;
use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\OperatorRole;
use PragmaRX\Google2FA\Google2FA;

/**
 * Signs the test client in to the operator console the way a person does:
 * email + password, then the enrolment of an authenticator and its first code.
 *
 * Ten console tests each carry their own copy of this; new tests use the trait
 * instead of adding an eleventh. The account is created by an invitation
 * (ADR-0081 S-07) with the fixture password of `OperatorFactory`.
 */
trait SignsInToConsole
{
    /** Signs in as a fresh operator of the given role (platform admin by default) and returns its email. */
    protected function signInToConsole(OperatorRole $role = OperatorRole::PlatformAdmin): string
    {
        $created = OperatorFactory::create(uniqid('op-', true).'@example.com', $role);
        $operator = app(OperatorRepository::class)->find($created);
        assert($operator instanceof Operator);

        $this->post('/admin/login', ['email' => $operator->email, 'password' => OperatorFactory::PASSWORD])
            ->assertRedirect(route('admin.mfa.enroll.show'));

        // @phpstan-ignore-next-line — TestResponse::getContent() is a dynamic proxy to the underlying Symfony Response.
        $content = $this->get('/admin/mfa/enroll')->getContent();
        $html = is_string($content) ? $content : '';
        if (preg_match('/name="secret"\s+value="([A-Z2-7]+)"/', $html, $secret) !== 1) {
            preg_match('/<code[^>]*>([A-Z2-7]{16,32})<\/code>/', $html, $secret);
        }
        preg_match('/name="challenge_token"\s+value="([^"]+)"/', $html, $challenge);

        $this->post('/admin/mfa/enroll', [
            'challenge_token' => $challenge[1] ?? '',
            'code' => (new Google2FA)->getCurrentOtp($secret[1] ?? ''),
        ])->assertRedirect(route('admin.dashboard'));

        return $operator->email;
    }
}
