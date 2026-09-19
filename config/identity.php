<?php

declare(strict_types=1);

/**
 * Local-only developer conveniences. Both flags exist to remove real
 * friction while testing the operator console by hand (scanning a TOTP
 * secret into an authenticator app on every environment rebuild); neither
 * has any effect outside `APP_ENV=local` — see
 * `IdentityServiceProvider::register()`, which checks the environment in
 * code, not just this flag, before ever binding the permissive provider.
 */
return [
    'disable_totp_in_local' => (bool) env('IDENTITY_DISABLE_TOTP_IN_LOCAL', false),
];
