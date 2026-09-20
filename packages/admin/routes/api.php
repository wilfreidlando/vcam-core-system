<?php

declare(strict_types=1);

use Core\Admin\Presentation\Http\Controllers\CreateOperatorController;
use Core\Admin\Presentation\Http\Controllers\CreateOrganizationController;
use Core\Admin\Presentation\Http\Controllers\CreateProjectController;
use Core\Admin\Presentation\Http\Controllers\ListDeadWebhookDeliveriesController;
use Core\Admin\Presentation\Http\Controllers\ListOperatorsController;
use Core\Admin\Presentation\Http\Controllers\ListProjectNotificationsController;
use Core\Admin\Presentation\Http\Controllers\ListProjectPaymentsController;
use Core\Admin\Presentation\Http\Controllers\ListProjectsController;
use Core\Admin\Presentation\Http\Controllers\ListProjectWalletsController;
use Core\Admin\Presentation\Http\Controllers\ListStuckPaymentsPreviewController;
use Core\Admin\Presentation\Http\Controllers\RegisterProviderApplicationController;
use Core\Admin\Presentation\Http\Controllers\ReplayDeadWebhookDeliveryController;
use Core\Admin\Presentation\Http\Controllers\RevokeOperatorController;
use Core\Admin\Presentation\Http\Controllers\SearchAuditLogController;
use Core\Admin\Presentation\Http\Controllers\TriggerPaymentReconciliationController;
use Core\Admin\Presentation\Http\Controllers\UpdateOperatorController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Admin routes — registered by AdminServiceProvider (ADR-0018, ADR-0044)
|--------------------------------------------------------------------------
|
| Every route is gated by 'operator.auth' (bootstrap/app.php), never
| 'project.auth' — this is the platform operator plane, not the
| machine-identity one. None of these routes carry the 'idempotency'
| middleware: RequireIdempotencyKey's scope is wired to ProjectContext,
| never bound on an operator-authenticated request, and none of these
| four capabilities take a caller-supplied monetary amount in the G7
| sense — they trigger or read already-idempotent internal operations.
| The reconciliation route name deliberately avoids the bare "payments"
| substring IdempotencyTest guards against.
|
| EncryptCookies + StartSession are pulled in so a browser-authenticated
| operator can hit `/v1/admin/*` URLs (Scramble docs UI, links shared in
| chat, manual address-bar navigation) — the `operator.auth` middleware
| falls back to the encrypted operator session when no `Authorization:
| Bearer …` header is present. API clients still authenticate via Bearer
| only; the session lookup is a no-op when there's no session cookie.
| VerifyCsrfToken is intentionally NOT included: API clients don't carry
| CSRF tokens, and the admin web UI doesn't hit these endpoints via
| state-changing AJAX — every mutation here is a synchronous form POST
| handled by `routes/admin.php`.
*/

Route::middleware([
    Illuminate\Cookie\Middleware\EncryptCookies::class,
    Illuminate\Session\Middleware\StartSession::class,
    'operator.auth',
])
    ->prefix('v1/admin')
    ->group(function (): void {
        Route::get('/audit-log', SearchAuditLogController::class)->name('admin.audit-log');
        Route::get('/webhook-deliveries/dead', ListDeadWebhookDeliveriesController::class)->name('admin.webhook-deliveries.dead');
        Route::post('/webhook-deliveries/{deliveryId}/replay', ReplayDeadWebhookDeliveryController::class)->name('admin.webhook-deliveries.replay');
        Route::post('/payment-reconciliation-runs', TriggerPaymentReconciliationController::class)->name('admin.payment-reconciliation-runs');

        Route::get('/projects', ListProjectsController::class)->name('admin.projects');
        Route::post('/projects', CreateProjectController::class)->name('admin.projects.create');
        Route::get('/projects/{projectId}/payments', ListProjectPaymentsController::class)->name('admin.projects.payments');
        Route::get('/projects/{projectId}/wallets', ListProjectWalletsController::class)->name('admin.projects.wallets');
        Route::get('/projects/{projectId}/notifications', ListProjectNotificationsController::class)->name('admin.projects.notifications');

        Route::post('/organizations', CreateOrganizationController::class)->name('admin.organizations.create');

        // ADR-0050: operator self-service — create/list/revoke over HTTP,
        // the last CLI-only gap in the RBAC foundation (ADR-0044).
        Route::get('/operators', ListOperatorsController::class)->name('admin.operators');
        Route::post('/operators', CreateOperatorController::class)->name('admin.operators.create');
        Route::put('/operators/{operatorId}', UpdateOperatorController::class)->name('admin.operators.update');
        Route::post('/operators/{operatorId}/revoke', RevokeOperatorController::class)->name('admin.operators.revoke');

        Route::post('/payment-provider-applications', RegisterProviderApplicationController::class)->name('admin.payment-provider-applications.create');
        Route::get('/payment-reconciliation-preview', ListStuckPaymentsPreviewController::class)->name('admin.payment-reconciliation-preview');
    });
