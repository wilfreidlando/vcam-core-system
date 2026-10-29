<?php

declare(strict_types=1);

use Core\Billing\Presentation\Http\Controllers\ArchivePlanController;
use Core\Billing\Presentation\Http\Controllers\CancelSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\ChangeSubscriptionPlanController;
use Core\Billing\Presentation\Http\Controllers\ConsumeUsageController;
use Core\Billing\Presentation\Http\Controllers\CreateInvoiceController;
use Core\Billing\Presentation\Http\Controllers\CreatePlanController;
use Core\Billing\Presentation\Http\Controllers\CreatePlanVersionController;
use Core\Billing\Presentation\Http\Controllers\CreateSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\EraseCustomerController;
use Core\Billing\Presentation\Http\Controllers\GetCustomerController;
use Core\Billing\Presentation\Http\Controllers\GetEntitlementsController;
use Core\Billing\Presentation\Http\Controllers\GetInvoiceController;
use Core\Billing\Presentation\Http\Controllers\GetInvoiceDocumentController;
use Core\Billing\Presentation\Http\Controllers\GetSubscriptionController;
use Core\Billing\Presentation\Http\Controllers\IssueInvoiceController;
use Core\Billing\Presentation\Http\Controllers\ListCustomersController;
use Core\Billing\Presentation\Http\Controllers\ListInvoicePaymentsController;
use Core\Billing\Presentation\Http\Controllers\ListInvoicesController;
use Core\Billing\Presentation\Http\Controllers\ListPlansController;
use Core\Billing\Presentation\Http\Controllers\ListSubscriptionsController;
use Core\Billing\Presentation\Http\Controllers\PayInvoiceController;
use Core\Billing\Presentation\Http\Controllers\PublishPlanVersionController;
use Core\Billing\Presentation\Http\Controllers\ReactivateCustomerController;
use Core\Billing\Presentation\Http\Controllers\SuspendCustomerController;
use Core\Billing\Presentation\Http\Controllers\UpsertCustomerController;
use Core\Billing\Presentation\Http\Controllers\VoidInvoiceController;
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

        // usage et droits — ADR-0074 §4
        //
        // Not a money route (G7): the idempotency of a metered call is its
        // own `idempotency_key`, part of the atomic admission.
        Route::post('/usage', ConsumeUsageController::class)->name('usage.consume');
        Route::get('/customers/{externalRef}/entitlements', GetEntitlementsController::class)
            ->where('externalRef', '[A-Za-z0-9._:-]{1,128}')
            ->name('customers.entitlements');

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

        // factures — ADR-0073 §6
        //
        // The three mutations carry the `idempotency` middleware (G7):
        // each of them **writes to the ledger** — the issuance books the
        // receivable, the cancellation counter-passes it — and a
        // replayed issuance would book a second receivable against the
        // same number.
        Route::get('/invoices', ListInvoicesController::class)->name('invoices.list');
        Route::post('/invoices', CreateInvoiceController::class)
            ->middleware('idempotency')
            ->name('invoices.create');
        Route::get('/invoices/{invoiceId}', GetInvoiceController::class)
            ->whereUuid('invoiceId')
            ->name('invoices.get');
        Route::get('/invoices/{invoiceId}/document', GetInvoiceDocumentController::class)
            ->whereUuid('invoiceId')
            ->name('invoices.document');
        Route::post('/invoices/{invoiceId}/issue', IssueInvoiceController::class)
            ->middleware('idempotency')
            ->whereUuid('invoiceId')
            ->name('invoices.issue');
        Route::post('/invoices/{invoiceId}/void', VoidInvoiceController::class)
            ->middleware('idempotency')
            ->whereUuid('invoiceId')
            ->name('invoices.void');

        // The collection of an invoice — ADR-0073 §2, §6.
        //
        // `POST /v1/invoices/{id}/pay` carries the `idempotency`
        // middleware (G7): the same key is handed to the `payment`
        // module, which never charges the customer twice for it.
        Route::get('/invoices/{invoiceId}/payments', ListInvoicePaymentsController::class)
            ->whereUuid('invoiceId')
            ->name('invoices.payments.list');
        Route::post('/invoices/{invoiceId}/pay', PayInvoiceController::class)
            ->middleware('idempotency')
            ->whereUuid('invoiceId')
            ->name('invoices.pay');
    });
