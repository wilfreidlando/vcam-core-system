<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0080 §1 (« Client ») — everything about one customer on one page:
 * its contracts, its invoices, what it may do and how much is left.
 *
 * The page is the operator's answer to « what is going on with this
 * customer? ». It also carries the one gesture the console lacked: signing a
 * contract for a customer (`CreateSubscription`, ADR-0072 §7) — the same
 * operation the public API exposes, with its own permission and its audit.
 *
 * Every read goes through a port that requires the operator's scope (G16);
 * a customer of another project is a 404, never a 403 (G15).
 */
final class CustomerOverviewController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetCustomer $customers,
        private readonly ListSubscriptions $subscriptions,
        private readonly ListInvoices $invoices,
        private readonly GetEntitlements $entitlements,
        private readonly ListPlans $plans,
        private readonly CreateSubscription $createSubscription,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function show(OperatorContext $operator, string $projectId, string $externalRef): Response
    {
        $scope = $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        $customer = $this->customers->get(new GetCustomerCommand($projectId, $externalRef), $scope);

        if ($customer === null) {
            abort(404, 'Client introuvable.');
        }

        $canSubscribe = $operator->scopeFor('subscriptions:write')->allowsProject($projectId);

        return response()->view('admin.billing.customers.show', [
            'title' => $customer->displayName,
            'projectId' => $projectId,
            'customer' => $customer,
            'subscriptions' => $operator->scopeFor('subscriptions:read')->allowsProject($projectId)
                ? $this->subscriptions->list(new ListSubscriptionsCommand($projectId, $externalRef, null, null, 50), $scope)->items
                : [],
            'invoices' => $operator->scopeFor('invoices:read')->allowsProject($projectId)
                ? $this->invoices->list(new ListInvoicesCommand($projectId, $externalRef, null, null, 20), $scope)->items
                : [],
            'rights' => $operator->scopeFor('usage:read')->allowsProject($projectId)
                ? $this->entitlements->get(new GetEntitlementsCommand($projectId, $externalRef), $scope)
                : null,
            'plans' => $canSubscribe ? $this->plans->list(new ListPlansCommand($projectId, 'active', null, 100), $scope)->items : [],
            'canSubscribe' => $canSubscribe,
        ]);
    }

    public function subscribe(Request $request, OperatorContext $operator, string $projectId, string $externalRef): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        if ($this->customers->get(new GetCustomerCommand($projectId, $externalRef), $scope) === null) {
            return redirect()->route('admin.projects.customers.index', $projectId)->withErrors(['resource' => 'Ressource introuvable.']);
        }

        /** @var array{plan_code: string, trial_days?: int|null} $data */
        $data = Validator::make($request->all(), [
            'plan_code' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9_-]{0,63}$/'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
        ])->validate();

        try {
            $subscription = $this->createSubscription->create(new CreateSubscriptionCommand(
                projectId: $projectId,
                customerRef: $externalRef,
                planCode: $data['plan_code'],
                trialDays: $data['trial_days'] ?? null,
                startAt: null,
                idempotencyKey: 'console:'.(string) Str::uuid(),
                actor: 'operator:'.$operator->operatorId,
                correlationId: null,
            ));
        } catch (RuntimeException $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'billing.subscription.created',
            resource: 'subscription:'.$subscription->id,
            before: null,
            after: ['plan_code' => $data['plan_code'], 'status' => $subscription->status],
            context: ['project_id' => $projectId, 'customer_ref' => $externalRef],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.customers.show', [$projectId, $externalRef])
            ->with('status', sprintf('Abonnement %s créé.', $data['plan_code']));
    }
}
