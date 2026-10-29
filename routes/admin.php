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

    // The invitee's door: an activation token becomes an account (ADR-0069 §4). Public — the token
    // is the credential — and throttled by the dedicated limiter of the JSON acceptance routes.
    Route::middleware(['throttle:invitation-accept'])->group(function (): void {
        Route::get('invitation', [App\Http\Controllers\Admin\InvitationController::class, 'show'])->name('invitation.show');
        Route::post('invitation', [App\Http\Controllers\Admin\InvitationController::class, 'accept'])->name('invitation.accept');
    });

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

        // ADR-0069 §1/§4 — the members of an organization: list, invite,
        // revoke, change role. Kept outside the `organizations` prefix
        // group above so the URL reads `/admin/organizations/{id}/members`
        // without a doubled prefix.
        // Named `organizations.members.*` — `packages/admin/routes/api.php`
        // owns the JSON plane's `admin.organizations.members` (the GET
        // listing) and `admin.organizations.members.revoke`/`.update`
        // (the mutations); the web plane's own names end in `.index`,
        // `.invite`, `.store`, `.revoke.web` and `.role.web` so the two
        // planes never share a final name — the same collision-avoidance
        // convention the rest of this file follows.
        Route::prefix('organizations/{organizationId}/members')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\OrganizationMembersController::class, 'index'])
                ->whereUuid('organizationId')
                ->name('organizations.members.index');
            Route::get('invite', [App\Http\Controllers\Admin\OrganizationMembersController::class, 'create'])
                ->whereUuid('organizationId')
                ->name('organizations.members.invite');
            Route::post('/', [App\Http\Controllers\Admin\OrganizationMembersController::class, 'store'])
                ->whereUuid('organizationId')
                ->name('organizations.members.store');
            Route::post('{operatorId}/revoke', [App\Http\Controllers\Admin\OrganizationMembersController::class, 'revoke'])
                ->whereUuid('organizationId')
                ->whereUuid('operatorId')
                ->name('organizations.members.revoke.web');
            Route::put('{operatorId}/role', [App\Http\Controllers\Admin\OrganizationMembersController::class, 'updateRole'])
                ->whereUuid('organizationId')
                ->whereUuid('operatorId')
                ->name('organizations.members.role.web');
        });

        // ADR-0080 §1 — the portfolio: every project the operator may see, with its indicators.
        Route::get('portfolio', [App\Http\Controllers\Admin\PortfolioController::class, 'index'])->name('portfolio');

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
            // ADR-0062 — no JSON `admin.projects.notifications.index`
            // collision: the JSON plane's route is `admin.projects.notifications`.
            Route::get('{projectId}/notifications', [App\Http\Controllers\Admin\NotificationsController::class, 'index'])
                ->name('projects.notifications.index');
            Route::get('{projectId}/notifications/{notificationId}', [App\Http\Controllers\Admin\NotificationsController::class, 'show'])
                ->name('projects.notifications.show');
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
            Route::get('{projectId}/whatsapp-sessions/{sessionRef}/status', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'status'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.status');
            Route::post('{projectId}/whatsapp-sessions/{sessionRef}/pairing-code', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'pairingCode'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.pairing-code');
            Route::post('{projectId}/whatsapp-sessions/{sessionRef}/delete', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'destroy'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('projects.channel-sessions.delete');
            Route::get('{projectId}/provider-applications/create', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'create'])
                ->name('projects.provider-applications.create');
            Route::post('{projectId}/provider-applications', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'store'])
                ->name('projects.provider-applications.store');
            Route::get('{projectId}/provider-applications/{applicationId}/edit', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'edit'])
                ->name('projects.provider-applications.edit');
            Route::post('{projectId}/provider-applications/{applicationId}/delete', [App\Http\Controllers\Admin\ProviderApplicationsController::class, 'destroy'])
                ->name('projects.provider-applications.delete');
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
            // ADR-0082 §5 — secret rotation (double signature) and its early end.
            Route::post('{projectId}/webhook-endpoints/{endpointId}/rotate-secret', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'rotateSecret'])
                ->name('projects.webhook-endpoints.rotate-secret');
            Route::post('{projectId}/webhook-endpoints/{endpointId}/revoke-previous-secret', [App\Http\Controllers\Admin\WebhookEndpointsController::class, 'revokePreviousSecret'])
                ->name('projects.webhook-endpoints.revoke-previous-secret');
            // Review finding: API key issuance/revocation was genuinely
            // CLI-only (`identity:issue-api-key`/`identity:revoke-api-key`),
            // unlike organizations/projects/operators which already had
            // self-service — user-directed reversal. No JSON
            // `admin.projects.api-keys.*` route exists to collide with.
            Route::get('{projectId}/api-keys/create', [App\Http\Controllers\Admin\ApiKeysController::class, 'create'])
                ->name('projects.api-keys.create');
            Route::post('{projectId}/api-keys', [App\Http\Controllers\Admin\ApiKeysController::class, 'store'])
                ->name('projects.api-keys.store');
            Route::post('{projectId}/api-keys/{apiKeyId}/rotate', [App\Http\Controllers\Admin\ApiKeysController::class, 'rotate'])
                ->name('projects.api-keys.rotate');
            Route::post('{projectId}/api-keys/{apiKeyId}/revoke', [App\Http\Controllers\Admin\ApiKeysController::class, 'revoke'])
                ->name('projects.api-keys.revoke');

            // ADR-0070 / ADR-0071 / ADR-0072 — the commerce of a Project:
            // the customers it declares, its plan catalogue and its
            // subscription contracts. The JSON plane owns
            // `customers.*` / `plans.*` / `subscriptions.*`
            // (`packages/billing/routes/api.php`, unprefixed); these are
            // the console's own names, under `admin.projects.`.
            // ADR-0097 / ADR-0074 — the commerce settings of a project: its
            // customer portal, its billing settings and what its customers consume.
            Route::get('{projectId}/portal', [App\Http\Controllers\Admin\ProjectCommerceController::class, 'portal'])->name('projects.portal');
            Route::post('{projectId}/portal', [App\Http\Controllers\Admin\ProjectCommerceController::class, 'savePortal'])->name('projects.portal.save');
            Route::get('{projectId}/commerce-settings', [App\Http\Controllers\Admin\ProjectCommerceController::class, 'settings'])->name('projects.commerce-settings');
            Route::post('{projectId}/commerce-settings', [App\Http\Controllers\Admin\ProjectCommerceController::class, 'saveSettings'])->name('projects.commerce-settings.save');
            Route::get('{projectId}/usage', [App\Http\Controllers\Admin\ProjectCommerceController::class, 'usage'])->name('projects.usage');
            Route::get('{projectId}/indicators', [App\Http\Controllers\Admin\ProjectIndicatorsController::class, 'show'])->name('projects.indicators');
            Route::get('{projectId}/ledger', [App\Http\Controllers\Admin\ProjectLedgerController::class, 'show'])->name('projects.ledger');

            // ADR-0088 §3 / ADR-0101 — « Export des données » of a project: ask for it,
            // list the past ones, download one through a signed link made at the click.
            Route::get('{projectId}/exports', [App\Http\Controllers\Admin\ProjectExportsController::class, 'index'])->name('projects.exports');
            Route::post('{projectId}/exports', [App\Http\Controllers\Admin\ProjectExportsController::class, 'store'])->name('projects.exports.store');
            Route::post('{projectId}/exports/{exportId}/download', [App\Http\Controllers\Admin\ProjectExportsController::class, 'download'])->name('projects.exports.download');

            Route::prefix('{projectId}/customers')->group(function (): void {
                // The reference is format-checked in the route (the
                // column carries the same CHECK constraint): a
                // reference that does not match it is a route that does
                // not exist, not a 500.
                Route::get('/', [App\Http\Controllers\Admin\BillingCustomersController::class, 'index'])
                    ->name('projects.customers.index');
                Route::get('create', [App\Http\Controllers\Admin\BillingCustomersController::class, 'create'])
                    ->name('projects.customers.new');
                Route::post('/', [App\Http\Controllers\Admin\BillingCustomersController::class, 'store'])
                    ->name('projects.customers.store');
                Route::get('{externalRef}', [App\Http\Controllers\Admin\CustomerOverviewController::class, 'show'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.show');
                Route::post('{externalRef}/subscribe', [App\Http\Controllers\Admin\CustomerOverviewController::class, 'subscribe'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.subscribe.web');
                Route::get('{externalRef}/edit', [App\Http\Controllers\Admin\BillingCustomersController::class, 'edit'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.edit');
                Route::post('{externalRef}', [App\Http\Controllers\Admin\BillingCustomersController::class, 'update'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.update.web');
                Route::post('{externalRef}/suspend', [App\Http\Controllers\Admin\BillingCustomersController::class, 'suspend'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.suspend.web');
                Route::post('{externalRef}/reactivate', [App\Http\Controllers\Admin\BillingCustomersController::class, 'reactivate'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.reactivate.web');
                Route::post('{externalRef}/erase', [App\Http\Controllers\Admin\BillingCustomersController::class, 'erase'])
                    ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
                    ->name('projects.customers.erase.web');
            });

            Route::prefix('{projectId}/plans')->group(function (): void {
                Route::get('/', [App\Http\Controllers\Admin\BillingPlansController::class, 'index'])
                    ->name('projects.plans.index');
                Route::get('create', [App\Http\Controllers\Admin\BillingPlansController::class, 'create'])
                    ->name('projects.plans.new');
                Route::post('/', [App\Http\Controllers\Admin\BillingPlansController::class, 'store'])
                    ->name('projects.plans.store');
                // The « add a version » form of the new-plan page names its plan in the
                // body: the plan code is a field there, not a path segment.
                Route::post('versions', [App\Http\Controllers\Admin\BillingPlansController::class, 'storeVersionOfPlan'])
                    ->name('projects.plans.versions.add');
                Route::post('{code}/versions', [App\Http\Controllers\Admin\BillingPlansController::class, 'storeVersion'])
                    ->name('projects.plans.versions.store');
                Route::post('{code}/versions/{version}/publish', [App\Http\Controllers\Admin\BillingPlansController::class, 'publish'])
                    ->whereNumber('version')
                    ->name('projects.plans.versions.publish.web');
                Route::post('{code}/archive', [App\Http\Controllers\Admin\BillingPlansController::class, 'archive'])
                    ->name('projects.plans.archive.web');
            });

            Route::prefix('{projectId}/subscriptions')->group(function (): void {
                Route::get('/', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'index'])
                    ->name('projects.subscriptions.index');
                Route::get('{subscriptionId}', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'show'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.show');
                Route::post('{subscriptionId}/pause', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'pause'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.pause.web');
                Route::post('{subscriptionId}/resume', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'resume'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.resume.web');
                Route::post('{subscriptionId}/cancel', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'cancel'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.cancel.web');
                Route::post('{subscriptionId}/change-plan', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'changePlan'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.change-plan.web');
                Route::post('{subscriptionId}/complimentary', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'complimentary'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.complimentary.web');
                Route::post('{subscriptionId}/pay', [App\Http\Controllers\Admin\BillingSubscriptionsController::class, 'pay'])
                    ->whereUuid('subscriptionId')
                    ->name('projects.subscriptions.pay.web');
            });

            // Les factures — ADR-0073. La console appelle les mêmes
            // contrats publiés que l'API : une facture qu'elle refuse
            // est une facture que l'API refuse aussi.
            Route::prefix('{projectId}/invoices')->group(function (): void {
                Route::get('/', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'index'])
                    ->name('projects.invoices.index');
                Route::get('create', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'create'])
                    ->name('projects.invoices.new');
                Route::post('/', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'store'])
                    ->name('projects.invoices.store');
                Route::get('{invoiceId}', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'show'])
                    ->whereUuid('invoiceId')
                    ->name('projects.invoices.show');
                Route::post('{invoiceId}/issue', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'issue'])
                    ->whereUuid('invoiceId')
                    ->name('projects.invoices.issue.web');
                Route::post('{invoiceId}/void', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'void'])
                    ->whereUuid('invoiceId')
                    ->name('projects.invoices.void.web');
                Route::post('{invoiceId}/uncollectible', [App\Http\Controllers\Admin\BillingInvoicesController::class, 'uncollectible'])
                    ->whereUuid('invoiceId')
                    ->name('projects.invoices.uncollectible.web');

                // L'encaissement hors ligne — ADR-0073 §4, ADR-0095.
                Route::post('{invoiceId}/receipts', [App\Http\Controllers\Admin\BillingInvoiceReceiptsController::class, 'store'])
                    ->whereUuid('invoiceId')
                    ->name('projects.invoices.receipts.store');
            });

            // Les encaissements hors ligne du projet — ADR-0073 §4.
            Route::get('{projectId}/receipts', [App\Http\Controllers\Admin\BillingInvoiceReceiptsController::class, 'index'])
                ->name('projects.receipts.index');
            Route::post('{projectId}/receipts/{receiptId}/approve', [App\Http\Controllers\Admin\BillingInvoiceReceiptsController::class, 'approve'])
                ->whereUuid('receiptId')
                ->name('projects.receipts.approve.web');
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
            Route::get('{delivery}', [App\Http\Controllers\Admin\WebhookDeliveriesController::class, 'show'])
                ->name('webhook-deliveries.show');
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

        // ADR-0077 — the money exceptions queue (console only, no JSON twin yet).
        Route::prefix('money-exceptions')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\MoneyExceptionsController::class, 'index'])
                ->name('money-exceptions.index');
            Route::get('{exception}', [App\Http\Controllers\Admin\MoneyExceptionsController::class, 'show'])
                ->whereUuid('exception')
                ->name('money-exceptions.show');
            Route::post('{exception}/resolve', [App\Http\Controllers\Admin\MoneyExceptionsController::class, 'resolve'])
                ->whereUuid('exception')
                ->name('money-exceptions.resolve');
            Route::post('{exception}/approve', [App\Http\Controllers\Admin\MoneyExceptionsController::class, 'approve'])
                ->whereUuid('exception')
                ->name('money-exceptions.approve');
            Route::post('{exception}/reject', [App\Http\Controllers\Admin\MoneyExceptionsController::class, 'reject'])
                ->whereUuid('exception')
                ->name('money-exceptions.reject');
        });

        // ADR-0076 §6 — the operating corrections of the ledger (console
        // only, no JSON twin yet): propose, then approve or refuse.
        Route::prefix('ledger-adjustments')->group(function (): void {
            Route::get('/', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'index'])
                ->name('ledger-adjustments.index');
            // Named `ledger-adjustments.new`, not `.create` — the JSON
            // plane owns no `admin.ledger-adjustments.create`, but the
            // same "no two planes share a final name" convention the rest
            // of this file follows.
            Route::get('create', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'create'])
                ->name('ledger-adjustments.new');
            Route::post('/', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'store'])
                ->name('ledger-adjustments.store');
            Route::get('{adjustment}', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'show'])
                ->whereUuid('adjustment')
                ->name('ledger-adjustments.show');
            Route::post('{adjustment}/approve', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'approve'])
                ->whereUuid('adjustment')
                ->name('ledger-adjustments.approve');
            Route::post('{adjustment}/reject', [App\Http\Controllers\Admin\LedgerAdjustmentsController::class, 'reject'])
                ->whereUuid('adjustment')
                ->name('ledger-adjustments.reject');
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
            Route::get('{sessionRef}/status', [App\Http\Controllers\Admin\ChannelSessionsController::class, 'status'])
                ->where('sessionRef', '[A-Za-z0-9_-]+')->name('channel-sessions.status');
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
