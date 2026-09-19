<?php

declare(strict_types=1);

namespace Core\Identity\Presentation\Console\Commands;

use Core\Identity\Domain\Port\OperatorRepository;
use Core\Identity\Domain\Port\TotpProvider;
use Illuminate\Console\Command as ConsoleCommand;

final class CurrentOperatorOtpCommand extends ConsoleCommand
{
    protected $signature = 'admin:current-otp
        {email : The operator\'s email address}';

    protected $description = 'Print the current 6-digit TOTP code for the operator. Dev-only — do not ship in production.';

    public function handle(OperatorRepository $operators, TotpProvider $totp): int
    {
        /** @var string $email */
        $email = $this->argument('email');

        $operator = $operators->findByEmail($email);

        if ($operator === null) {
            $this->error("Operator with email '{$email}' not found.");

            return self::FAILURE;
        }

        if (! $operator->mfaEnabled()) {
            $this->error("Operator '{$email}' has not enrolled MFA yet. Run 'identity:create-operator' first, then complete the enrolment flow in the admin UI.");

            return self::FAILURE;
        }

        $secret = $operator->mfaSecret();
        if ($secret === null) {
            $this->error("Operator '{$email}' has MFA enabled but no secret stored. Re-enrol MFA.");

            return self::FAILURE;
        }

        $code = $totp->currentCode($secret);
        $expiresIn = 30 - (time() % 30);

        $this->line(sprintf('<comment>%s</comment>', $code));
        $this->line(sprintf(
            '<info>  ↑ current TOTP code for <comment>%s</comment> · expires in %ds</info>',
            $email,
            $expiresIn,
        ));

        return self::SUCCESS;
    }
}
