<?php

declare(strict_types=1);

use Core\Billing\Presentation\Http\Controllers\ArchivePlanController;
use Core\Billing\Presentation\Http\Controllers\CancelSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\ChangeSubscriptionPlanController;
use Core\Billing\Presentation\Http\Controllers\CreatePlanController;
use Core\Billing\Presentation\Http\Controllers\CreatePlanVersionController;
use Core\Billing\Presentation\Http\Controllers\CreateSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\EraseCustomerController;
use Core\Billing\Presentation\Http\Controllers\GetCustomerController;
use Core\Billing\Presentation\Http\Controllers\GetSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\ListCustomersController;
use Core\Billing\Presentation\Http\Controllers\ListPlansController;
use Core\Billing\Presentation\Http\Controllers\ListSubscriptionsController;
use Core\Billing\Presentation\Http\Controllers\PublishPlanVersionController;
use Core\Billing\Presentation\Http\Controllers\ReactivateCustomerController;
use Core\Billing\Presentation\Http\Controllers\SuspendCustomerController;
use Core\Billing\Presentation\Http\Controllers\UpsertCustomerController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Billing routes — registered by BillingServiceProvider (ADR-0018)
|--------------------------------------------------------------------------
|
| The public surface of the commerce lot: the customers a project
| declares (ADR-0070), its versioned plan catalogue (ADR-0071) and the
| subscription contracts with their periods (ADR-0072).
|
| `POST /v1/subscriptions` carries the `idempotency` middleware (G7):
| signing a contract twice on a replayed request is a real harm, not a
| duplicate row. The other mutations are naturally idempotent — an
| upsert, a status change.
*/

Route::middleware(['project.auth'])
    ->prefix('v1')
    ->group(function (): void {
        // customers — ADR-0070 §2
        //
        // The reference is format-checked **in the route**: the column
        // carries the same CHECK constraint, and a reference that does
        // not match it would come back as a 500 instead of a 404.
        Route::put('/customers/{externalRef}', UpsertCustomerController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.upsert');
        Route::get('/customers/{externalRef}', GetCustomerController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.get');
        Route::get('/customers', ListCustomersController::class)->name('customers.list');
        Route::post('/customers/{externalRef}/suspend', SuspendCustomerController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.suspend');
        Route::post('/customers/{externalRef}/reactivate', ReactivateCustomerController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.reactivate');
        Route::post('/customers/{externalRef}/erase', EraseCustomerController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.erase');

        // catalogue — ADR-0071 §5
        Route::get('/plans', ListPlansController::class)->name('plans.list');
        Route::post('/plans', CreatePlanController::class)->name('plans.create');
        Route::post('/plans/{code}/versions', CreatePlanVersionController::class)->name('plans.versions.create');
        Route::post('/plans/{code}/versions/{version}/publish', PublishPlanVersionController::class)->name('plans.versions.publish');
        Route::post('/plans/{code}/archive', ArchivePlanController::class)->name('plans.archive');

        // abonnements — ADR-0072 §7
        Route::post('/subscriptions', CreateSubscriptionController::class)->middleware('idempotency')->name('subscriptions.create');
        Route::get('/subscriptions/{subscriptionId}', GetSubscriptionController::class)->whereUuid('subscriptionId')->name('subscriptions.get');
        Route::get('/subscriptions', ListSubscriptionsController::class)->name('subscriptions.list');
        Route::post('/subscriptions/{subscriptionId}/cancel', CancelSubscriptionController::class)->whereUuid('subscriptionId')->name('subscriptions.cancel');
        // An **immediate** change of plan closes a period and opens
        // another one: replayed, it would do it twice. The route
        // therefore carries the `idempotency` middleware too (G7) — the
        // other mutations of this module are naturally idempotent (an
        // upsert, a status change), this one is not.
        Route::post('/subscriptions/{subscriptionId}/change-plan', ChangeSubscriptionPlanController::class)
            ->middleware('idempotency')
            ->whereUuid('subscriptionId')
            ->name('subscriptions.change-plan');
    });
