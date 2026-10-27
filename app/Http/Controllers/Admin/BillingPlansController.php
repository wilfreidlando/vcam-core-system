<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\ArchivePlan;
use Core\Billing\Contracts\ArchivePlanCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\InvalidPlan;
use Core\Billing\Domain\Exception\InvalidPlanFeature;
use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The plan catalogue of a project (ADR-0071) — the console mirror of the
 * public API.
 *
 * A plan is **stable**; its versions carry the terms. The console is where
 * the catalogue is written: a plan is created, a version is drafted, then
 * published — and from that instant the version is **frozen**, in the
 * database as well as in the code (ADR-0071 §2). Changing a price is
 * publishing a new version, never editing the published one.
 */
final class BillingPlansController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListPlans $listPlans,
        private readonly CreatePlan $createPlan,
        private readonly CreatePlanVersion $createPlanVersion,
        private readonly PublishPlanVersion $publishPlanVersion,
        private readonly ArchivePlan $archivePlan,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    public function index(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'plans:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:read');

        $page = $this->listPlans->list(new ListPlansCommand(
            projectId: $projectId,
            status: $this->status($request),
            cursor: $this->cursor($request),
            limit: min(100, max(1, (int) $request->query('limit', 50))),
        ), $scope);

        return response()->view('admin.billing.plans.index', [
            'title' => 'Plans',
            'projectId' => $projectId,
            'plans' => $page->items,
            'nextCursor' => $page->nextCursor,
        ]);
    }

    public function create(OperatorContext $operator, string $projectId): Response
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        return response()->view('admin.billing.plans.form', [
            'title' => 'Nouveau plan',
            'projectId' => $projectId,
            'plan' => null,
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        /** @var array{code: string, name: string, description?: string|null, visibility?: string|null, sort_order?: int|null} $data */
        $data = Validator::make($request->all(), [
            'code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:2000'],
            'visibility' => ['nullable', 'string', 'in:public,private'],
            'sort_order' => ['nullable', 'integer'],
        ])->validate();

        try {
            $plan = $this->createPlan->create(new CreatePlanCommand(
                projectId: $projectId,
                code: $data['code'],
                name: $data['name'],
                description: isset($data['description']) && $data['description'] !== '' ? $data['description'] : null,
                visibility: isset($data['visibility']) && $data['visibility'] !== '' ? $data['visibility'] : 'public',
                sortOrder: $data['sort_order'] ?? 0,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (InvalidPlan|PlanCodeAlreadyTaken $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.plan.created',
            resource: 'plan:'.$plan->id,
            before: null,
            after: ['changed_fields' => ['code', 'name', 'visibility', 'status']],
            context: ['project_id' => $projectId],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.plans.index', $projectId)
            ->with('status', sprintf('Plan %s créé — ajoutez-lui une version.', $plan->code));
    }

    public function storeVersion(Request $request, OperatorContext $operator, string $projectId, string $code): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        /** @var array{price_minor: int, currency: string, interval_unit: string, interval_count: int, trial_days?: int|null, grace_days?: int|null, grace_access?: bool|null, features?: string|null} $data */
        $data = Validator::make($request->all(), [
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval_unit' => ['required', 'string', 'in:day,week,month,year'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'grace_access' => ['nullable', 'boolean'],
            'features' => ['nullable', 'string'],
        ])->validate();

        try {
            $version = $this->createPlanVersion->createVersion(new CreatePlanVersionCommand(
                projectId: $projectId,
                planCode: $code,
                priceMinor: $data['price_minor'],
                currency: strtoupper($data['currency']),
                intervalUnit: $data['interval_unit'],
                intervalCount: $data['interval_count'],
                trialDays: $data['trial_days'] ?? 0,
                graceDays: $data['grace_days'] ?? 0,
                graceAccess: $data['grace_access'] ?? true,
                features: $this->features($data['features'] ?? null),
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (PlanNotFound|PlanVersionNotEditable|InvalidBillingInterval|InvalidPlanFeature|UnsupportedCurrency|InvalidPlan $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.plan_version.drafted',
            resource: 'plan_version:'.$version->id,
            before: null,
            after: ['changed_fields' => ['version', 'price_minor', 'currency', 'interval_unit', 'interval_count', 'status']],
            context: ['project_id' => $projectId, 'plan_code' => $code],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.plans.index', $projectId)
            ->with('status', sprintf('Version %d créée — publiez-la pour qu\'elle soit facturable.', $version->version));
    }

    public function publish(Request $request, OperatorContext $operator, string $projectId, string $code, int $version): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        try {
            $published = $this->publishPlanVersion->publish(new PublishPlanVersionCommand(
                projectId: $projectId,
                planCode: $code,
                version: $version,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (PlanNotFound|PlanVersionNotFound|PlanVersionNotEditable $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.plan_version.published',
            resource: 'plan_version:'.$published->id,
            before: ['status' => 'draft'],
            after: ['status' => $published->status],
            context: ['project_id' => $projectId, 'plan_code' => $code, 'version' => $version],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.plans.index', $projectId)
            ->with('status', sprintf('Version %d publiée — elle est désormais figée.', $version));
    }

    public function archive(Request $request, OperatorContext $operator, string $projectId, string $code): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        try {
            $plan = $this->archivePlan->archive(new ArchivePlanCommand(
                projectId: $projectId,
                planCode: $code,
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (PlanNotFound|InvalidPlan $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.plan.archived',
            resource: 'plan:'.$plan->id,
            before: ['status' => 'active'],
            after: ['status' => $plan->status],
            context: ['project_id' => $projectId, 'plan_code' => $code],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.plans.index', $projectId)
            ->with('status', sprintf('Plan %s archivé — il n\'accepte plus de nouvel abonnement.', $code));
    }

    /**
     * The features are the project's own vocabulary; the console sends
     * them as JSON and the domain validates their format (ADR-0071 §3).
     *
     * @return array<string, mixed>
     */
    private function features(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw ValidationException::withMessages([
                'features' => 'Les fonctionnalités doivent être un objet JSON.',
            ]);
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    private function actor(OperatorContext $operator): string
    {
        return 'operator:'.$operator->operatorId;
    }

    private function cursor(Request $request): ?string
    {
        $cursor = $request->query('cursor');

        return is_string($cursor) && $cursor !== '' ? $cursor : null;
    }

    private function status(Request $request): ?string
    {
        $status = $request->query('status');

        return is_string($status) && $status !== '' ? $status : null;
    }
}
