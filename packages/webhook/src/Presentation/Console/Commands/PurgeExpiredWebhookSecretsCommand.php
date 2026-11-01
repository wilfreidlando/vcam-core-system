<?php

declare(strict_types=1);

namespace Core\Webhook\Presentation\Console\Commands;

use Core\Webhook\Contracts\PurgeExpiredWebhookSecrets;
use Illuminate\Console\Command;

/** ADR-0082 §5 — erase previous signing secrets once their overlap ended. */
final class PurgeExpiredWebhookSecretsCommand extends Command
{
    protected $signature = 'webhooks:purge-expired-secrets';

    protected $description = 'Erase the previous signing secret of endpoints whose rotation overlap has ended.';

    public function handle(PurgeExpiredWebhookSecrets $purge): int
    {
        $this->info(sprintf('%d previous secret(s) erased.', $purge->purge()));

        return self::SUCCESS;
    }
}
