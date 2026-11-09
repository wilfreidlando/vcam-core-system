<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\CancelSubscriptionCommand;
use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ChangeSubscriptionPlanCommand;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPayment;
use Core\Billing\Contracts\ConfirmSubscriptionPeriodPaymentCommand;
use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GetSubscriptionCommand;
use Core\Billing\Contracts\GrantComplimentaryPeriod;
use Core\Billing\Contracts\GrantComplimentaryPeriodCommand;
use Core\Billing\Contracts\ListSubscriptionPeriods;
use Core\Billing\Contracts\ListSubscriptionPeriodsCommand;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\ListSubscriptionSupplements;
use Core\Billing\Contracts\ListSubscriptionSupplementsCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PauseSubscription;
use Core\Billing\Contracts\PauseSubscriptionCommand;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPack;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPackCommand;
use Core\Billing\Contracts\ResumeSubscription;
use Core\Billing\Contracts\ResumeSubscriptionCommand;
use Core\Billing\Domain\Exception\InvalidSubscriptionSupplement;
use Core\Billing\Domain\Exception\InvalidSubscriptionTransition;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\SubscriptionNotFound;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The subscription contracts of a project (ADR-0072) — the console mirror
 * of the public API.
 *
 * Every gesture of the operator is an **audited act** (ADR-0072 §6): the
 * pause, the resume, the cancellation, the plan change, the free period
 * and the payment declaration all carry a reason, and the reason is kept
 * in the audit entry.
 *
 * The payment declaration is the seam of ADR-0092 §2: it records that a
 * period was settled and **moves no money**. The invoice — and with it
 * the collection in the ledger — arrives with Lot 11 (ADR-0073).
 */
final class BillingSubscriptionsController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListSubscriptions $listSubscriptions,
        private readonly GetSubscription $getSubscription,
        private readonly ListSubscriptionPeriods $listPeriods,
        private readonly ListSubscriptionSupplements $listSupplements,
        private readonly ManageBillingSettings $billing,
        private readonly PurchaseSubscriptionSupplementPack $purchasePack,
        private readonly PauseSubscription $pauseSubscription,
        private readonly ResumeSubscription $resumeSubscription,
        private readonly CancelSubscription $cancelSubscription,
        private readonly ChangeSubscriptionPlan $changeSubscriptionPlan,
        private readonly GrantComplimentaryPeriod $grantComplimentaryPeriod,
        private readonly ConfirmSubscriptionPeriodPayment $confirmPayment,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'subscriptions:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:read');

        $page = $this->listSubscriptions->list(new ListSubscriptionsCommand(
            projectId: $projectId,
            customerRef: $this->query($request, 'customer_ref'),
            status: $this->query($request, 'status'),
            cursor: $this->query($request, 'cursor'),
            limit: min(100, max(1, (int) $request->query('limit', 50))),
        ), $scope);

        return response()->view('admin.billing.subscriptions.index', [
            'title' => 'Abonnements',
            'projectId' => $projectId,
            'subscriptions' => $page->items,
            'nextCursor' => $page->nextCursor,
        ]);
    }

    public function show(OperatorContext $operator, string $projectId, string $subscriptionId): Response
    {
        $scope = $this->permittedScope($operator, 'subscriptions:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:read');

        $subscription = $this->getSubscription->get(
            new GetSubscriptionCommand($projectId, $subscriptionId),
            $scope,
        );

        if ($subscription === null) {
            abort(404, 'Abonnement introuvable.');
        }

        $periods = $this->listPeriods->list(
            new ListSubscriptionPeriodsCommand($projectId, $subscriptionId),
            $scope,
        );

        // ADR-0119 §5 — les packs que le projet propose et les
        // suppléments déjà accordés à cet abonnement.
        $reglages = $this->billing->get($projectId, $scope);
        $supplements = $this->listSupplements->list(
            new ListSubscriptionSupplementsCommand($projectId, $subscriptionId),
            $scope,
        );

        return response()->view('admin.billing.subscriptions.show', [
            'title' => sprintf('Abonnement %s', $subscription->customerRef),
            'projectId' => $projectId,
            'subscription' => $subscription,
            'periods' => $periods->items,
            'packs' => $reglages === null ? [] : $reglages->supplementPacks,
            'supplements' => $supplements,
        ]);
    }

    /**
     * ADR-0119 §5 — l'opérateur accorde un pack de quota à un
     * abonnement.
     *
     * Le prix vient du **catalogue du projet**, jamais du formulaire ; la
     * clé d'idempotence est dérivée côté serveur (ADR-0119 §6) : un
     * double envoi du formulaire n'achète qu'une fois.
     *
     * L'allocation n'est pas accordée ici : elle le sera quand la
     * facture qu'il vient d'émettre sera réglée (ADR-0117 §2).
     */
    public function grantSupplement(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        /** @var array{pack_code: string, reason?: string|null, nonce?: string|null} $data */
        $data = Validator::make($request->all(), [
            'pack_code' => ['required', 'string', 'max:64'],
            'reason' => ['nullable', 'string', 'max:500'],
            'nonce' => ['nullable', 'uuid'],
        ])->validate();

        $reglages = $this->billing->get($projectId, $scope);
        $pack = null;

        foreach ($reglages === null ? [] : $reglages->supplementPacks as $offre) {
            if ($offre->code === $data['pack_code']) {
                $pack = $offre;

                break;
            }
        }

        if ($pack === null) {
            return back()->withErrors(['resource' => 'Ce pack n\'existe pas dans le catalogue du projet.']);
        }

        try {
            $purchase = $this->purchasePack->purchase(new PurchaseSubscriptionSupplementPackCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                packCode: $pack->code,
                // ADR-0121 — la clé dit **quel** geste : le contrat, le
                // pack et le nonce du formulaire. Un nonce seul ne le
                // disait pas : le navigateur qui restitue la page après
                // un « retour » rejouait la même clé, et le pack choisi
                // entre-temps était remplacé par l'achat déjà fait — le
                // console annonçait un pack accordé qui ne l'était pas.
                // La clé est **hachée** : la colonne en tire 128
                // caractères au plus, et le portail procède de même.
                idempotencyKey: 'console-pack:'.hash(
                    'sha256',
                    $projectId.'|'.$subscriptionId.'|'.$pack->code.'|'.($data['nonce'] ?? (string) Str::uuid()),
                ),
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound $exception) {
            return $this->refused($exception);
        } catch (InvalidSubscriptionSupplement|RuntimeException $exception) {
            Log::warning('Supplément de quota refusé', [
                'project_id' => $projectId,
                'subscription_id' => $subscriptionId,
                'pack_code' => $pack->code,
                'reason' => $exception->getMessage(),
            ]);

            return back()->withErrors(['resource' => 'Le supplément n\'a pas pu être accordé à cet abonnement.']);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.subscription.supplement_granted',
            resource: 'subscription:'.$subscriptionId,
            before: null,
            after: [
                'changed_fields' => ['supplement'],
                'pack_code' => $pack->code,
                'meter' => $purchase->supplement->meter,
                'quantity' => $purchase->supplement->quantity,
                // The **frozen** amount of the purchase, not the price the
                // catalogue carries today: the project may have re-declared
                // the pack since, and the journal must not contradict the
                // invoice it points at.
                'total_minor' => $purchase->supplement->totalMinor,
                'supplement_id' => $purchase->supplement->id,
                'invoice_id' => $purchase->invoice->id,
                'invoice_number' => $purchase->invoice->number,
            ],
            context: array_filter([
                'project_id' => $projectId,
                'reason' => $data['reason'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.subscriptions.show', [$projectId, $subscriptionId])
            ->with('status', sprintf(
                'Supplément de %d unité(s) accordé — facture %s à encaisser.',
                $pack->quantity,
                $purchase->invoice->number ?? $purchase->invoice->id,
            ));
    }

    public function pause(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        $reason = $this->reason($request);

        try {
            $this->pauseSubscription->pause(new PauseSubscriptionCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                reason: $reason,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.paused',
            $reason,
            'Abonnement suspendu.',
        );
    }

    public function resume(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        try {
            $this->resumeSubscription->resume(new ResumeSubscriptionCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.resumed',
            null,
            'Abonnement repris.',
        );
    }

    public function cancel(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        $atPeriodEnd = $request->boolean('at_period_end', true);
        $reason = $this->optionalReason($request);

        try {
            $this->cancelSubscription->cancel(new CancelSubscriptionCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                atPeriodEnd: $atPeriodEnd,
                reason: $reason,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.canceled',
            $reason,
            $atPeriodEnd ? 'Annulation enregistrée à l\'échéance.' : 'Abonnement annulé.',
        );
    }

    public function changePlan(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        /** @var array{plan_code: string, effective: string} $data */
        $data = Validator::make($request->all(), [
            'plan_code' => ['required', 'string', 'max:64'],
            'effective' => ['required', 'string', 'in:period_end,immediate'],
        ])->validate();

        try {
            $this->changeSubscriptionPlan->changePlan(new ChangeSubscriptionPlanCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                planCode: $data['plan_code'],
                effective: $data['effective'],
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition|PlanNotFound $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.plan_changed',
            null,
            sprintf(
                'Changement de plan vers %s (%s).',
                $data['plan_code'],
                $data['effective'] === 'immediate' ? 'immédiat' : 'à l\'échéance',
            ),
        );
    }

    public function complimentary(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        /** @var array{days: int|string, reason: string} $data */
        $data = Validator::make($request->all(), [
            'days' => ['required', 'integer', 'min:1', 'max:365'],
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        $reason = $data['reason'];

        try {
            $this->grantComplimentaryPeriod->grant(new GrantComplimentaryPeriodCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                days: (int) $data['days'],
                reason: $reason,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.complimentary_granted',
            $reason,
            sprintf('%d jour(s) offerts ajoutés.', $data['days']),
        );
    }

    public function pay(Request $request, OperatorContext $operator, string $projectId, string $subscriptionId): RedirectResponse
    {
        $this->permittedScope($operator, 'subscriptions:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'subscriptions:write');

        /** @var array{seq: int|string|string, reference?: string|null} $data */
        $data = Validator::make($request->all(), [
            'seq' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:200'],
        ])->validate();

        try {
            $this->confirmPayment->confirm(new ConfirmSubscriptionPeriodPaymentCommand(
                projectId: $projectId,
                subscriptionId: $subscriptionId,
                seq: (int) $data['seq'],
                reference: isset($data['reference']) && $data['reference'] !== '' ? $data['reference'] : null,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (SubscriptionNotFound|InvalidSubscriptionTransition $exception) {
            return $this->refused($exception);
        }

        return $this->redirectWithAudit(
            $operator,
            $projectId,
            $subscriptionId,
            'billing.subscription.period_declared_paid',
            null,
            'Période déclarée réglée — aucun mouvement n\'est écrit au grand livre avant la facturation.',
        );
    }

    private function redirectWithAudit(
        OperatorContext $operator,
        string $projectId,
        string $subscriptionId,
        string $action,
        ?string $reason,
        string $message,
    ): RedirectResponse {
        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: $action,
            resource: 'subscription:'.$subscriptionId,
            before: null,
            after: ['changed_fields' => ['status']],
            context: array_filter([
                'project_id' => $projectId,
                'reason' => $reason,
            ], static fn (mixed $value): bool => $value !== null),
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.subscriptions.show', [$projectId, $subscriptionId])
            ->with('status', $message);
    }

    /**
     * The operator's acts carry a reason (ADR-0072 §6) — an act without
     * one is refused.
     */
    private function reason(Request $request): string
    {
        /** @var array{reason: string} $data */
        $data = Validator::make($request->all(), [
            'reason' => ['required', 'string', 'max:500'],
        ])->validate();

        $reason = trim($data['reason']);

        if ($reason === '') {
            abort(422, 'Le motif est obligatoire.');
        }

        return $reason;
    }

    /**
     * The reason of an act that may go without one.
     */
    private function optionalReason(Request $request): ?string
    {
        /** @var array{reason?: string|null} $data */
        $data = Validator::make($request->all(), [
            'reason' => ['nullable', 'string', 'max:500'],
        ])->validate();

        $reason = isset($data['reason']) ? trim($data['reason']) : '';

        return $reason === '' ? null : $reason;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }

    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
