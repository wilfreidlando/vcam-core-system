<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesProjectCounters;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\OfferCounting;
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
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PlanView;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpdatePlanVersion;
use Core\Billing\Contracts\UpdatePlanVersionCommand;
use Core\Billing\Domain\Exception\InvalidBillingInterval;
use Core\Billing\Domain\Exception\InvalidPlan;
use Core\Billing\Domain\Exception\InvalidPlanFeature;
use Core\Billing\Domain\Exception\PlanCodeAlreadyTaken;
use Core\Billing\Domain\Exception\PlanNotFound;
use Core\Billing\Domain\Exception\PlanVersionConflict;
use Core\Billing\Domain\Exception\PlanVersionNotEditable;
use Core\Billing\Domain\Exception\PlanVersionNotFound;
use Core\Billing\Domain\Exception\UnsupportedCurrency;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Support\Access\AccessScope;
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
    use ResolvesProjectCounters;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ListPlans $listPlans,
        private readonly ManageBillingSettings $billing,
        private readonly CreatePlan $createPlan,
        private readonly CreatePlanVersion $createPlanVersion,
        private readonly UpdatePlanVersion $updatePlanVersion,
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
            // Ce que chaque offre compte : lu ici, dit en français.
            'counting' => OfferCounting::byPlan($page->items),
        ]);
    }

    public function create(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        return response()->view('admin.billing.plans.form', [
            'title' => 'Nouveau plan',
            'projectId' => $projectId,
            'plan' => null,
            'version' => null,
            'counting' => [],
            // Les compteurs que le projet compte : on les choisit dans la
            // liste, on ne les saisit pas — une clé mal écrite ne compte rien.
            'counters' => $this->countersFor($projectId, $scope),
        ]);
    }

    public function store(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        /** @var array{code: string, name: string, description?: string|null, visibility?: string|null, sort_order?: int|string|null} $data */
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
                sortOrder: (int) ($data['sort_order'] ?? 0),
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

    /**
     * The form of the « new plan » page: the plan is named by a field.
     */
    public function storeVersionOfPlan(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        // « Ajouter une fonctionnalité » : le formulaire revient avec un bloc
        // de plus. Rien n'est enregistré : l'opérateur n'a pas encore validé.
        if ($request->string('add_row')->toString() === 'features') {
            $rows = count((array) $request->input('features', [])) + 1;

            return back()->withInput(['features_rows' => $rows]);
        }

        /** @var array{plan_code: string} $named */
        $named = Validator::make($request->all(), [
            'plan_code' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/'],
        ])->validate();

        return $this->storeVersion($request, $operator, $projectId, $named['plan_code']);
    }

    public function storeVersion(Request $request, OperatorContext $operator, string $projectId, string $code): RedirectResponse
    {
        $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        // « Ajouter une fonctionnalité » : le formulaire revient avec un bloc
        // de plus. Rien n'est enregistré : l'opérateur n'a pas encore validé.
        if ($request->string('add_row')->toString() === 'features') {
            $rows = count((array) $request->input('features', [])) + 1;

            return back()->withInput(['features_rows' => $rows]);
        }

        /** @var array{price_minor: int|string, currency: string, interval_unit: string, interval_count: int|string, trial_days?: int|string|null, grace_days?: int|string|null, grace_access?: bool|null, features?: array<int|string, mixed>|null} $data */
        $data = Validator::make($request->all(), [
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval_unit' => ['required', 'string', 'in:day,week,month,year'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'grace_access' => ['nullable', 'boolean'],
            'features' => ['nullable', 'array', 'max:20'],
        ], [
            'features.array' => 'Les fonctionnalités se déclarent bloc par bloc.',
            'features.max' => 'Au plus 20 fonctionnalités par version.',
        ])->validate();

        try {
            $version = $this->createPlanVersion->createVersion(new CreatePlanVersionCommand(
                projectId: $projectId,
                planCode: $code,
                // A browser form sends strings: the command is strictly typed.
                priceMinor: (int) $data['price_minor'],
                currency: strtoupper($data['currency']),
                intervalUnit: $data['interval_unit'],
                intervalCount: (int) $data['interval_count'],
                trialDays: (int) ($data['trial_days'] ?? 0),
                graceDays: (int) ($data['grace_days'] ?? 0),
                graceAccess: $request->has('grace_access') ? $request->boolean('grace_access') : true,
                features: $this->features($data['features'] ?? null),
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (PlanNotFound|PlanVersionConflict|PlanVersionNotEditable|InvalidBillingInterval|InvalidPlanFeature|UnsupportedCurrency|InvalidPlan $exception) {
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

    /**
     * ADR-0071 §2 — the version form, opened on an offer that exists.
     *
     * The offer's own page leads here: the operator never has to know a
     * code by heart to give it a new version.
     */
    public function newVersion(OperatorContext $operator, string $projectId, string $code): Response
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        try {
            $plan = $this->plan($projectId, $code, $scope);
        } catch (PlanNotFound $exception) {
            // Une offre qui n'existe pas ne se répond pas par une page JSON :
            // l'opérateur revient à la liste des offres, qui est l'endroit
            // où il peut choisir.
            return redirect()
                ->route('admin.projects.plans.index', $projectId)
                ->withErrors(['resource' => $this->refusalMessage($exception)]);
        }

        return response()->view('admin.billing.plans.form', [
            'title' => 'Ajouter une version',
            'projectId' => $projectId,
            'plan' => $plan,
            'version' => null,
            'counting' => [],
            'counters' => $this->countersFor($projectId, $scope),
        ]);
    }

    /**
     * ADR-0071 §2 — the correction of a draft version.
     *
     * A published version is frozen: correcting it means publishing a new
     * one, never editing the old one (ADR-0071 §2). A draft is the
     * operator's working paper, and the catalogue holds only one draft per
     * offer — so correcting it is the only way out of a draft written
     * wrong.
     */
    public function editVersion(OperatorContext $operator, string $projectId, string $code, int $version): Response
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        try {
            $draft = $this->draftVersion($projectId, $code, $version, $scope);
        } catch (PlanNotFound|PlanVersionNotFound $exception) {
            // L'offre ou la version n'existe pas : la console répond par
            // la liste des offres, jamais par une page JSON.
            return redirect()
                ->route('admin.projects.plans.index', $projectId)
                ->withErrors(['resource' => $this->refusalMessage($exception)]);
        }

        if ($draft->status !== 'draft') {
            return redirect()
                ->route('admin.projects.plans.index', $projectId)
                ->withErrors(['resource' => 'Une version publiée est figée : publiez une nouvelle version pour changer ses conditions.']);
        }

        return response()->view('admin.billing.plans.form', [
            'title' => 'Corriger la version '.$version,
            'projectId' => $projectId,
            'plan' => $this->plan($projectId, $code, $scope),
            'version' => $draft,
            'counting' => OfferCounting::rowsOfVersion($draft),
            'counters' => $this->countersFor($projectId, $scope),
        ]);
    }

    /**
     * ADR-0071 §2 — the draft version, corrected.
     */
    public function updateVersion(Request $request, OperatorContext $operator, string $projectId, string $code, int $version): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        /** @var array{price_minor: int|string, currency: string, interval_unit: string, interval_count: int|string, trial_days?: int|string|null, grace_days?: int|string|null, grace_access?: bool|null, features?: array<int|string, mixed>|null} $data */
        $data = Validator::make($request->all(), [
            'price_minor' => ['required', 'integer', 'min:0'],
            'currency' => ['required', 'string', 'size:3'],
            'interval_unit' => ['required', 'string', 'in:day,week,month,year'],
            'interval_count' => ['required', 'integer', 'min:1', 'max:365'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'grace_access' => ['nullable', 'boolean'],
            'features' => ['nullable', 'array', 'max:20'],
        ], [
            'features.array' => 'Les fonctionnalités se déclarent bloc par bloc.',
            'features.max' => 'Au plus 20 fonctionnalités par version.',
        ])->validate();

        try {
            // Les termes d'avant : le journal doit pouvoir dire « le prix
            // était 5 000 avant la correction ».
            $draft = $this->draftVersion($projectId, $code, $version, $scope);

            $updated = $this->updatePlanVersion->update(new UpdatePlanVersionCommand(
                projectId: $projectId,
                planCode: $code,
                version: $version,
                // A browser form sends strings: the command is strictly typed.
                priceMinor: (int) $data['price_minor'],
                currency: strtoupper($data['currency']),
                intervalUnit: $data['interval_unit'],
                intervalCount: (int) $data['interval_count'],
                trialDays: (int) ($data['trial_days'] ?? 0),
                graceDays: (int) ($data['grace_days'] ?? 0),
                graceAccess: $request->has('grace_access') ? $request->boolean('grace_access') : true,
                features: $this->features($data['features'] ?? null),
                actor: $this->actor($operator),
                correlationId: null,
            ));
        } catch (PlanNotFound|PlanVersionNotFound|PlanVersionConflict|PlanVersionNotEditable|InvalidBillingInterval|InvalidPlanFeature|UnsupportedCurrency|InvalidPlan $exception) {
            return $this->refused($exception);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.plan_version.updated',
            resource: 'plan_version:'.$updated->id,
            before: $this->terms($draft),
            after: $this->terms($updated),
            context: ['project_id' => $projectId, 'plan_code' => $code],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()
            ->route('admin.projects.plans.index', $projectId)
            ->with('status', sprintf('Version %d corrigée.', $updated->version));
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
     * The features are the project's own vocabulary; the console declares
     * them **block by block**, never as JSON (ADR-0071 §3).
     *
     * One block per feature: a name, a type, then the settings of that
     * type. A block without a name is empty and is ignored; a block with a
     * name but an incomplete setting is refused with a sentence. The
     * domain still checks the format of the names and of the definitions.
     *
     * @return array<string, array<string, mixed>>
     */
    private function features(mixed $rows): array
    {
        if ($rows === null) {
            return [];
        }

        if (! is_array($rows)) {
            throw ValidationException::withMessages([
                'features' => 'Les fonctionnalités se déclarent bloc par bloc, pas en JSON.',
            ]);
        }

        $features = [];

        foreach ($rows as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $name = $this->text($row['name'] ?? null);

            if ($name === '') {
                continue; // Un bloc sans nom est un bloc vide : il est ignoré.
            }

            $type = $this->text($row['type'] ?? null);

            if ($type === '') {
                $this->refuse('features.'.$index.'.type', 'Choisissez le type de la fonctionnalité « '.$name.' ».');
            }

            if ($type === 'boolean') {
                $enabled = $this->text($row['enabled'] ?? null);

                if ($enabled === '') {
                    $this->refuse('features.'.$index.'.enabled', 'Dites si la fonctionnalité « '.$name.' » est activée.');
                }

                if (! in_array($enabled, ['0', '1'], true)) {
                    $this->refuse('features.'.$index.'.enabled', 'La fonctionnalité « '.$name.' » est activée ou non.');
                }

                $features[$name] = ['type' => 'boolean', 'enabled' => $enabled === '1'];

                continue;
            }

            if ($type !== 'limit') {
                $this->refuse('features.'.$index.'.type', 'Le type « '.$type.' » n\'existe pas : choisissez « Interrupteur » ou « Limite d\'usage ».');
            }

            $meter = $this->text($row['counter'] ?? null);

            if ($meter === '') {
                $this->refuse('features.'.$index.'.counter', 'Indiquez le compteur que compte la fonctionnalité « '.$name.' ».');
            }

            $limit = $this->text($row['limit'] ?? null);

            if ($limit !== '' && preg_match('/^\d+$/', $limit) !== 1) {
                $this->refuse('features.'.$index.'.limit', 'La limite de « '.$name.' » est un nombre entier, ou vide pour une limite sans plafond.');
            }

            $reset = $this->text($row['reset'] ?? null);

            if ($reset === '') {
                $reset = 'period';
            } elseif (! in_array($reset, ['period', 'never'], true)) {
                $this->refuse('features.'.$index.'.reset', 'La remise de « '.$name.' » est « à chaque période » ou « jamais ».');
            }

            $overage = $this->text($row['overage'] ?? null);

            if ($overage === '') {
                $overage = 'block';
            } elseif (! in_array($overage, ['block', 'allow'], true)) {
                $this->refuse('features.'.$index.'.overage', 'Le dépassement de « '.$name.' » est « bloquer » ou « autoriser et signaler ».');
            }

            $threshold = $this->text($row['threshold_bps'] ?? null);

            if ($threshold !== '' && (preg_match('/^\d+$/', $threshold) !== 1 || (int) $threshold < 1 || (int) $threshold > 10000)) {
                $this->refuse('features.'.$index.'.threshold_bps', 'Le seuil d\'alerte de « '.$name.' » est un nombre de points de base entre 1 et 10000, ou vide.');
            }

            $features[$name] = [
                'type' => 'limit',
                'meter' => $meter,
                'limit' => $limit === '' ? null : (int) $limit,
                'reset' => $reset,
                'overage' => $overage,
                'threshold_bps' => $threshold === '' ? null : (int) $threshold,
            ];
        }

        return $features;
    }

    /**
     * The value of a field of the form, as a trimmed string.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * The console's answer to an incomplete block: the message is shown
     * next to the field that is missing.
     */
    private function refuse(string $key, string $message): never
    {
        throw ValidationException::withMessages([$key => $message]);
    }

    /**
     * The counters the project counts: the ones it declares in « Limites
     * d'usage », and the ones its offers already carry.
     *
     * @return list<string>
     */
    private function countersFor(string $projectId, AccessScope $scope): array
    {
        $settings = $this->billing->get($projectId, $scope);
        $offers = $this->listPlans->list(new ListPlansCommand($projectId, null, null, 100), $scope)->items;

        return $this->counters(
            // `??` on a null object is quiet: the project that never
            // touched its settings has no counter to offer.
            $settings->meterMappings ?? [],
            $this->countersOfOffers($offers),
        );
    }

    private function plan(string $projectId, string $code, AccessScope $scope): PlanView
    {
        $offers = $this->listPlans->list(new ListPlansCommand($projectId, null, null, 100), $scope)->items;

        foreach ($offers as $plan) {
            if ($plan->code === $code) {
                return $plan;
            }
        }

        throw PlanNotFound::becauseCode($code);
    }

    private function draftVersion(string $projectId, string $code, int $number, AccessScope $scope): PlanVersionView
    {
        $plan = $this->plan($projectId, $code, $scope);

        foreach ($plan->versions as $version) {
            if ($version->version === $number) {
                return $version;
            }
        }

        throw PlanVersionNotFound::becauseVersion($code, $number);
    }

    /**
     * The terms of a version, as the audit trail records them: the price
     * it was, and the price it becomes.
     *
     * @return array<string, mixed>
     */
    private function terms(PlanVersionView $version): array
    {
        return [
            'price_minor' => $version->priceMinor,
            'currency' => $version->currency,
            'interval_unit' => $version->intervalUnit,
            'interval_count' => $version->intervalCount,
            'trial_days' => $version->trialDays,
            'grace_days' => $version->graceDays,
            'grace_access' => $version->graceAccess,
            'features' => $version->features,
        ];
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
