<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuthController;
use App\Http\Controllers\Admin\DashboardController;
use Illuminate\Support\Facades\Route;

/*
 * Admin web UI routes.
 *
 * Mounted under the `web` middleware group (session + cookie auth, CSRF
 * protection). The `operator.session` middleware reads the Sanctum
 * cookie set by the login + MFA flow and binds `OperatorContext` for the
 * request.
 *
 * Routes that DON'T need authentication (login + MFA challenge + logout)
 * sit OUTSIDE the `operator.session` group. Everything else is
 * gated. Missing auth -> redirect to /admin/login (handled inside
 * each controller via a shared `EnsureAdminAuthenticated` helper that
 * throws a custom exception caught by the framework exception
 * handler).
 */

Route::middleware(['web'])->prefix('admin')->name('admin.')->group(function (): void {

    // Unauthenticated: login + MFA challenge + logout.
    // 'operator-auth' (AppServiceProvider::configureRateLimiting) is the
    // same tighter limiter the JSON API's equivalent routes carry — this
    // web plane used to have zero throttling at all (review finding,
    // Lot 6 follow-up).
    Route::middleware(['throttle:operator-auth'])->group(function (): void {
        Route::get('login', [AuthController::class, 'showLogin'])->name('login.show');
        Route::post('login', [AuthController::class, 'login'])->name('login');
        Route::get('mfa/enroll', [AuthController::class, 'showMfaEnroll'])->name('mfa.enroll.show');
        Route::post('mfa/enroll', [AuthController::class, 'confirmMfaEnrollment'])->name('mfa.confirm');
        Route::get('mfa/verify', [AuthController::class, 'showMfaVerify'])->name('mfa.verify.show');
        Route::post('mfa/verify', [AuthController::class, 'verifyMfa'])->name('mfa.verify');
    });
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    // Authenticated: the whole operator console.
    Route::middleware(['operator.session'])->group(function (): void {

        Route::get('/', [DashboardController::class, 'show'])->name('dashboard');

        Route::prefix('audit-log')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\AuditLogController::class, 'index'])
                ->name('audit-log.index');
        });

        Route::prefix('organizations')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OrganizationsController::class, 'index'])
                ->name('organizations.index');
            // Named `organizations.new`, not `.create` — `packages/admin/routes/api.php`
            // already owns `admin.organizations.create` for its JSON POST
            // endpoint (this GET-form route and that JSON route used to
            // collide under the same final name; see the `wallets.index`
            // comment above for how this class of bug was found).
            Route::get('create', [App\Http\Controllers\Admin\OrganizationsController::class, 'create'])
                ->name('organizations.new');
            Route::post('/', [App\Http\Controllers\Admin\OrganizationsController::class, 'store'])
                ->name('organizations.store');
            // No JSON route named `admin.organizations.edit`/`.update`
            // exists yet in `packages/admin/routes/api.php` (only
            // `.create` does), so no collision-avoidance suffix is
            // needed here, unlike `organizations.new` above.
            Route::get('{organizationId}/edit', [App\Http\Controllers\Admin\OrganizationsController::class, 'edit'])
                ->name('organizations.edit');
            Route::put('{organizationId}', [App\Http\Controllers\Admin\OrganizationsController::class, 'update'])
                ->name('organizations.update');
        });

        Route::prefix('projects')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\ProjectsController::class, 'index'])
                ->name('projects.index');
            // Must be registered BEFORE `GET projects/{projectId}` below —
            // Laravel matches route patterns in registration order, so a
            // parameterised route registered first would greedily capture
            // `/admin/projects/create` as `{projectId}='create'` and hit
            // `show()`, which then 422s on UUID validation.
            // Named `projects.new`, not `.create` — same collision as
            // `organizations.new` above, against `packages/admin/routes/api.php`'s
            // `admin.projects.create` JSON POST endpoint.
            Route::get('create', [App\Http\Controllers\Admin\ProjectsController::class, 'create'])
                ->name('projects.new');
            Route::post('/', [App\Http\Controllers\Admin\ProjectsController::class, 'store'])
                ->name('projects.store');
            Route::get('{projectId}', [App\Http\Controllers\Admin\ProjectsController::class, 'show'])
                ->name('projects.show');
            // Same reasoning as `organizations.edit`/`.update` above — no
            // JSON `admin.projects.edit`/`.update` route exists to collide
            // with.
            Route::get('{projectId}/edit', [App\Http\Controllers\Admin\ProjectsController::class, 'edit'])
                ->name('projects.edit');
            Route::put('{projectId}', [App\Http\Controllers\Admin\ProjectsController::class, 'update'])
                ->name('projects.update');
            // Named `projects.wallets.index`, not `projects.wallets` —
            // `packages/admin/routes/api.php` already owns the route name
            // `admin.projects.wallets` for its pre-existing JSON endpoint
            // (ADR-0045, `GET /v1/admin/projects/{id}/wallets`). Both
            // routes shared the same final name before this fix
            // (`admin.` + `projects.wallets`), and Laravel's `route()`
            // helper silently resolved to whichever was registered last —
            // the JSON one — so every "Wallets" link in the console sent
            // a signed-in operator's browser to a Bearer-token-only API
            // route, which correctly 401'd (bug found via live report:
            // clicking through the console hit `/v1/admin/...` with no
            // Authorization header).
            Route::get('{projectId}/wallets', [App\Http\Controllers\Admin\WalletsController::class, 'index'])
                ->name('projects.wallets.index');
            // No JSON `admin.projects.wallets.ledger` route exists to
            // collide with — the JSON plane has no ledger drill-down yet.
            Route::get('{projectId}/wallets/{walletId}/ledger', [App\Http\Controllers\Admin\WalletsController::class, 'ledger'])
                ->name('projects.wallets.ledger');
            // WhatsApp sessions owned by ONE Project: creation (which also
            // starts the session at the provider), QR, screenshot, pairing.
            Route::get('{projectId}/whatsapp-sessions', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'index'])
                ->name('projects.channel-sessions.index');
            Route::post('{projectId}/whatsapp-sessions', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'store'])
                ->name('projects.channel-sessions.store');
            Route::get('{projectId}/whatsapp-sessions/{sessionRef}/qr', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'qr'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.qr');
            Route::get('{projectId}/whatsapp-sessions/{sessionRef}/screenshot', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'screenshot'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.screenshot');
            Route::post('{projectId}/whatsapp-sessions/{sessionRef}/pairing-code', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'pairingCode'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.pairing-code');
            Route::post('{projectId}/whatsapp-sessions/{sessionRef}/delete', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'destroy'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.delete');
            Route::get('{projectId}/provider-applications/create', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'create'])
                ->name('projects.provider-applications.create');
            Route::post('{projectId}/provider-applications', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'store'])
                ->name('projects.provider-applications.store');
            // No collision risk with `packages/webhook/routes/api.php`'s
            // `webhook-endpoints.register`/`.list` — those are unprefixed
            // (the Project/API-key plane), these are `admin.projects.*`
            // (the operator console plane).
            Route::get('{projectId}/webhook-endpoints/create', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'create'])
                ->name('projects.webhook-endpoints.create');
            Route::post('{projectId}/webhook-endpoints', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'store'])
                ->name('projects.webhook-endpoints.store');
            Route::put('{projectId}/webhook-endpoints/{endpointId}', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'update'])
                ->name('projects.webhook-endpoints.update');
            Route::post('{projectId}/webhook-endpoints/{endpointId}/activate', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'activate'])
                ->name('projects.webhook-endpoints.activate');
            Route::post('{projectId}/webhook-endpoints/{endpointId}/deactivate', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'deactivate'])
                ->name('projects.webhook-endpoints.deactivate');
            // Review finding: API key issuance/revocation was genuinely
            // CLI-only (`identity:issue-api-key`/`identity:revoke-api-key`),
            // unlike organizations/projects/operators which already had
            // self-service — user-directed reversal. No JSON
            // `admin.projects.api-keys.*` route exists to collide with.
            Route::get('{projectId}/api-keys/create', [App\Http\Controllers\Admin\ApiKeysController::class, 'create'])
                ->name('projects.api-keys.create');
            Route::post('{projectId}/api-keys', [App\Http\Controllers\Admin\ApiKeysController::class, 'store'])
                ->name('projects.api-keys.store');
            Route::post('{projectId}/api-keys/{apiKeyId}/revoke', [App\Http\Controllers\Admin\ApiKeysController::class, 'revoke'])
                ->name('projects.api-keys.revoke');
        });

        Route::prefix('payments')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\PaymentsController::class, 'index'])
                ->name('payments.index');
            Route::get('{payment}', [App\Http\Controllers\Admin\PaymentsController::class, 'show'])
                ->name('payments.show');
        });

        Route::prefix('webhook-deliveries')->group(function (): void {
            // Named `webhook-deliveries.dead.web`, not `.dead` —
            // `packages/admin/routes/api.php` already owns
            // `admin.webhook-deliveries.dead` for its JSON GET endpoint;
            // same collision class as the routes above.
            Route::get('dead', [App\Http\Controllers\Admin\WebhookDeliveriesController::class, 'index'])
                ->name('webhook-deliveries.dead.web');
            // Named `.replay.web` — `packages/admin/routes/api.php` also
            // owns `admin.webhook-deliveries.replay` for its JSON POST
            // endpoint.
            Route::post('{delivery}/replay', [App\Http\Controllers\Admin\WebhookDeliveriesController::class, 'replay'])
                ->name('webhook-deliveries.replay.web');
        });

        // Static reference documentation — no JSON equivalent, so no
        // collision-avoidance suffix needed.
        Route::get('webhook-events', [App\Http\Controllers\Admin\WebhookEventsController::class, 'index'])
            ->name('webhook-events.index');

        Route::prefix('reconciliation')->group(function (): void {
            Route::get('new', [App\Http\Controllers\Admin\ReconciliationController::class, 'new'])
                ->name('reconciliation.new');
            Route::post('/', [App\Http\Controllers\Admin\ReconciliationController::class, 'run'])
                ->name('reconciliation.run');
            Route::post('{payment}', [App\Http\Controllers\Admin\ReconciliationController::class, 'runOne'])
                ->name('reconciliation.run-one');
        });

        // ADR-0050: operator self-service — mirrors packages/admin's JSON
        // /v1/admin/operators endpoints for the console web plane.
        // WhatsApp sessions of the PLATFORM (no owner Project): usable by
        // every Project. Same actions as the per-Project ones above.
        Route::prefix('whatsapp-sessions')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'index'])
                ->name('channel-sessions.index');
            Route::post('/', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'store'])
                ->name('channel-sessions.store');
            Route::get('{sessionRef}/qr', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'qr'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('channel-sessions.qr');
            Route::get('{sessionRef}/screenshot', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'screenshot'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('channel-sessions.screenshot');
            Route::post('{sessionRef}/pairing-code', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'pairingCode'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('channel-sessions.pairing-code');
            Route::post('{sessionRef}/delete', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'destroy'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('channel-sessions.delete');
        });

        Route::prefix('operators')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OperatorsController::class, 'index'])
                ->name('operators.index');
            // Named `operators.new`, not `.create` — `packages/admin/routes/api.php`
            // owns `admin.operators.create` (JSON POST). Same collision
            // class as `organizations.new`/`projects.new` above.
            Route::get('create', [App\Http\Controllers\Admin\OperatorsController::class, 'create'])
                ->name('operators.new');
            Route::post('/', [App\Http\Controllers\Admin\OperatorsController::class, 'store'])
                ->name('operators.store');
            // `.revoke.web` — JSON owns `admin.operators.revoke` (also POST).
            Route::post('{operatorId}/revoke', [App\Http\Controllers\Admin\OperatorsController::class, 'revoke'])
                ->name('operators.revoke.web');
            // Literal segments (`create`, `{operatorId}/edit`) always win
            // over a parameter route for an exact match in Laravel, so
            // this doesn't collide with `operators/create` above.
            Route::get('{operatorId}/edit', [App\Http\Controllers\Admin\OperatorsController::class, 'edit'])
                ->name('operators.edit');
            // `.update.web` — JSON owns `admin.operators.update` (also PUT).
            Route::put('{operatorId}', [App\Http\Controllers\Admin\OperatorsController::class, 'update'])
                ->name('operators.update.web');
        });
    });
});
