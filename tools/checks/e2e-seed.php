<?php

declare(strict_types=1);

// Seed for the browser end-to-end session (development database only).
//   make e2e-seed
// Prints one `KEY=value` line per fact the browser needs: the console login, the
// project, an API key, and a fresh one-time link to the customer portal.

use Core\Identity\Contracts\AcceptOperatorInvitation;
use Core\Identity\Contracts\AcceptOperatorInvitationCommand;
use Core\Identity\Contracts\ApiKeyScopes;
use Core\Identity\Contracts\InviteOperator;
use Core\Identity\Contracts\InviteOperatorCommand;
use Core\Identity\Contracts\IssueApiKey;
use Core\Identity\Contracts\IssueApiKeyCommand;
use Core\Identity\OperatorRole;

$project = require 'tools/checks/portal-demo.php';   // prints LINK= and returns the project id
assert(is_string($project));

$email = 'e2e+'.substr(bin2hex(random_bytes(3)), 0, 6).'@example.com';
$password = 'e2e passphrase '.bin2hex(random_bytes(4));

$invitation = app(InviteOperator::class)->invite(new InviteOperatorCommand($email, OperatorRole::PlatformAdmin->value), actor: 'service:e2e', correlationId: null);
app(AcceptOperatorInvitation::class)->accept(new AcceptOperatorInvitationCommand(token: (string) $invitation->token, name: 'E2E Admin', password: $password));

$key = app(IssueApiKey::class)->issue(new IssueApiKeyCommand($project, ApiKeyScopes::ALL), actor: 'service:e2e', correlationId: null)->plaintextToken;

echo "E2E_EMAIL={$email}\n";
echo "E2E_PASSWORD={$password}\n";
echo "E2E_PROJECT={$project}\n";
echo "E2E_API_KEY={$key}\n";
