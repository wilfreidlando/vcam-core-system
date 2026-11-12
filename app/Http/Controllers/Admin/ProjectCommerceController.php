<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesProjectCounters;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Notices\CoreNoticeCatalogue;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\ListUsage;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Notification\Contracts\ManageSystemNotificationSettings;
use Core\Notification\Contracts\SaveSystemNotificationSettingsCommand;
use Core\Notification\Contracts\SystemNotificationPreferenceView;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Contracts\SavePortalSettingsCommand;
use Core\Portal\Domain\Exception\InvalidPortalSettings;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

/**
 * The commerce settings of a project, in the console: how its customer
 * portal looks and what a customer may do alone (ADR-0075 §4), how its
 * commerce is configured (ADR-0071 §4, ADR-0074 §5–6), and what its customers
 * consume (ADR-0074, ADR-0080 §2).
 *
 * Every write is audited here (the contract modules may not write to
 * `audit`, ADR-0002) and every read goes through a port that requires the
 * operator's scope (G16).
 */
final class ProjectCommerceController
{
    use ResolvesProjectCounters;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ManagePortalSettings $portal,
        private readonly ManageBillingSettings $billing,
        private readonly ManageSystemNotificationSettings $systemNotifications,
        private readonly ListPlans $listPlans,
        private readonly ListUsage $usage,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    // ---------------------------------------------------------------- portal

    public function portal(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'portal:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'portal:write');

        return response()->view('admin.billing.portal', [
            'title' => 'Portail client',
            'projectId' => $projectId,
            'settings' => $this->portal->get($projectId, $scope),
        ]);
    }

    public function savePortal(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'portal:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'portal:write');

        /** @var array{display_name: string, logo_url?: string|null, primary_color?: string|null, support_url?: string|null, locale?: string|null, allowed_return_urls?: string|null, payment_channels?: list<string>|null, direct_login_session?: string|null} $data */
        $data = Validator::make($request->all(), [
            'display_name' => ['required', 'string', 'max:80'],
            'logo_url' => ['nullable', 'string', 'max:500'],
            'primary_color' => ['nullable', 'string', 'max:7'],
            'support_url' => ['nullable', 'string', 'max:500'],
            'locale' => ['nullable', 'string', 'max:5'],
            'allowed_return_urls' => ['nullable', 'string', 'max:4000'],
            'allow_self_cancel' => ['nullable', 'boolean'],
            'allow_plan_change' => ['nullable', 'boolean'],
            'payment_channels' => ['nullable', 'array', 'max:10'],
            'payment_channels.*' => ['string', 'in:mtn_momo,orange_money,card'],
            'direct_login_enabled' => ['nullable', 'boolean'],
            'direct_login_session' => ['nullable', 'string', 'max:64'],
        ])->validate();

        try {
            $this->portal->save(new SavePortalSettingsCommand(
                projectId: $projectId,
                displayName: $data['display_name'],
                logoUrl: $this->blank($data['logo_url'] ?? null),
                primaryColor: $this->blank($data['primary_color'] ?? null) ?? '#2563eb',
                supportUrl: $this->blank($data['support_url'] ?? null),
                locale: $this->blank($data['locale'] ?? null) ?? 'fr',
                allowedReturnUrls: $this->lines($data['allowed_return_urls'] ?? null),
                allowSelfCancel: $request->boolean('allow_self_cancel'),
                allowPlanChange: $request->boolean('allow_plan_change'),
                paymentChannels: $this->channels($request),
                actor: $this->actor($operator),
                directLoginEnabled: $request->boolean('direct_login_enabled'),
                directLoginSession: $this->blank($data['direct_login_session'] ?? null),
            ), $scope);
        } catch (InvalidPortalSettings $e) {
            return back()->withInput()->withErrors(['resource' => $e->getMessage()]);
        }

        // The portal module audits the change itself (it may write to `audit`).
        return redirect()->route('admin.projects.portal', $projectId)->with('status', 'Réglages du portail enregistrés.');
    }

    // ---------------------------------------------------------------- commerce

    public function settings(OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        $settings = $this->billing->get($projectId, $scope);

        // Les compteurs que le projet compte : ceux qu'il déclare dans « Limites
        // d'usage », et ceux que ses offres et ses packs portent déjà. On les
        // choisit dans les listes ; on ne les saisit pas deux fois.
        $used = [];

        if ($settings !== null) {
            foreach ($settings->supplementPacks as $pack) {
                $used[] = $pack->meter;
            }
        }

        $offers = $this->listPlans->list(new ListPlansCommand($projectId, null, null, 100), $scope)->items;

        return response()->view('admin.billing.settings', [
            'title' => 'Réglages du commerce',
            'projectId' => $projectId,
            'settings' => $settings,
            'noticeEvents' => $this->noticeEvents($projectId, $scope),
            'counters' => $this->counters(
                // `??` on a null object is quiet: a project that never
                // touched its settings has no counter to offer.
                $settings->meterMappings ?? [],
                array_merge($used, $this->countersOfOffers($offers)),
            ),
        ]);
    }

    /**
     * ADR-0118 §4 — what the project wants its customers notified about.
     *
     * The declarations are the project's own: the commerce's events, named
     * by the console, on the only channel that exists today (WhatsApp).
     */
    public function saveSystemNotifications(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        $validated = Validator::make($request->all(), [
            'events' => ['nullable', 'array'],
            'events.*' => ['string', 'in:'.implode(',', CoreNoticeCatalogue::events())],
        ])->validate();

        $preferences = [];
        $events = $validated['events'] ?? [];
        if (is_array($events)) {
            foreach ($events as $eventType) {
                if (is_string($eventType)) {
                    $preferences[] = new SystemNotificationPreferenceView($eventType, CoreNoticeCatalogue::CHANNEL);
                }
            }
        }

        $before = $this->systemNotifications->get($projectId, $scope);

        $saved = $this->systemNotifications->save(new SaveSystemNotificationSettingsCommand(
            projectId: $projectId,
            preferences: $preferences,
            actor: $this->actor($operator),
        ), $scope);

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'notification.system_settings_changed',
            resource: 'project:'.$projectId,
            before: $before === null ? null : (array) $before,
            after: $saved === null ? null : (array) $saved,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()->route('admin.projects.commerce-settings', $projectId)
            ->with('status', 'Notifications système enregistrées.');
    }

    public function saveSettings(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        // « Ajouter une ligne » : le formulaire revient avec un bloc de plus.
        // Rien n'est enregistré : l'opérateur n'a pas encore validé.
        $added = $request->string('add_row')->toString();

        if ($added === 'meter_mappings' || $added === 'supplement_packs') {
            // Les deux listes retiennent leur taille : l'opérateur peut
            // ajouter une ligne dans l'une sans perdre celles de l'autre.
            $mappings = count((array) $request->input('meter_mappings', []));
            $packs = count((array) $request->input('supplement_packs', []));

            return back()->withInput([
                'meter_mappings_rows' => $added === 'meter_mappings' ? $mappings + 1 : $mappings,
                'supplement_packs_rows' => $added === 'supplement_packs' ? $packs + 1 : $packs,
            ]);
        }

        /** @var array{timezone: string, default_currency: string, renewal_lead_days: int|string|string, incomplete_expiry_hours: int|string|string, invoice_prefix: string, default_tax_rate_percent: numeric-string|int|float|null, manual_receipt_threshold?: int|string|string|null, meter_mappings?: array<int|string, mixed>|null, supplement_packs?: array<int|string, mixed>|null} $data */
        $data = Validator::make($request->all(), [
            'timezone' => ['required', 'string', 'max:64'],
            'default_currency' => ['required', 'string', 'size:3'],
            'renewal_lead_days' => ['required', 'integer', 'min:0', 'max:90'],
            'incomplete_expiry_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'invoice_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'default_tax_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_receipt_threshold' => ['nullable', 'integer', 'min:0'],
            'meter_mappings' => ['nullable', 'array', 'max:50'],
            'supplement_packs' => ['nullable', 'array', 'max:20'],
            'refund_failed_usage' => ['nullable', 'boolean'],
            'require_customer_ref' => ['nullable', 'boolean'],
        ], [
            'meter_mappings.array' => 'Les correspondances se déclarent bloc par bloc.',
            'meter_mappings.max' => 'Au plus 50 correspondances.',
            'supplement_packs.array' => 'Les packs se déclarent bloc par bloc.',
            'supplement_packs.max' => 'Au plus 20 packs.',
        ])->validate();

        $before = $this->billing->get($projectId, $scope);

        // Les correspondances lues dans le formulaire : les packs s'y réfèrent.
        $meterMappings = $this->mappings($data['meter_mappings'] ?? null);

        try {
            $saved = $this->billing->save(new SaveBillingSettingsCommand(
                projectId: $projectId,
                timezone: $data['timezone'],
                defaultCurrency: strtoupper($data['default_currency']),
                renewalLeadDays: (int) $data['renewal_lead_days'],
                incompleteExpiryHours: (int) $data['incomplete_expiry_hours'],
                invoicePrefix: $data['invoice_prefix'],
                // A percentage typed by a human becomes basis points (G5: no float stored).
                defaultTaxRateBps: (int) round(((float) ($data['default_tax_rate_percent'] ?? 0)) * 100),
                manualReceiptApprovalThresholdMinor: (int) ($data['manual_receipt_threshold'] ?? 0),
                meterMappings: $meterMappings,
                refundFailedUsage: $request->boolean('refund_failed_usage'),
                requireCustomerRef: $request->boolean('require_customer_ref'),
                actor: $this->actor($operator),
                supplementPacks: $this->packs($data['supplement_packs'] ?? null, array_values($meterMappings)),
            ), $scope);
        } catch (InvalidBillingSettings $e) {
            return back()->withInput()->withErrors(['resource' => $e->getMessage()]);
        }

        $this->audit->record(new AuditEntry(
            actor: $this->actor($operator),
            action: 'billing.settings_changed',
            resource: 'project:'.$projectId,
            before: $before === null ? null : (array) $before,
            after: $saved === null ? null : (array) $saved,
            context: [],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        return redirect()->route('admin.projects.commerce-settings', $projectId)->with('status', 'Réglages du commerce enregistrés.');
    }

    // ---------------------------------------------------------------- usage

    public function usage(Request $request, OperatorContext $operator, string $projectId): Response
    {
        $scope = $this->permittedScope($operator, 'usage:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'usage:read');

        $filter = $this->query($request, 'filter');
        $customer = $this->query($request, 'customer');
        $view = $this->query($request, 'view') === 'journal' ? 'journal' : 'counters';

        $page = $view === 'journal'
            ? $this->usage->records($projectId, $this->query($request, 'meter'), $customer, $this->query($request, 'cursor'), 50, $scope)
            : $this->usage->counters($projectId, $filter, $customer, $this->query($request, 'cursor'), 50, $scope);

        return response()->view('admin.billing.usage', [
            'title' => 'Usage et quotas',
            'projectId' => $projectId,
            'view' => $view,
            'filter' => $filter,
            'customer' => $customer,
            'meter' => $this->query($request, 'meter'),
            'items' => $page->items,
            'nextCursor' => $page->nextCursor,
        ]);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * The notifiable events, with the label the console shows and whether
     * the project declared them (ADR-0118 §4).
     *
     * @return list<array{event: string, label: string, checked: bool}>
     */
    private function noticeEvents(string $projectId, AccessScope $scope): array
    {
        $settings = $this->systemNotifications->get($projectId, $scope);
        $events = [];

        foreach (CoreNoticeCatalogue::labels() as $eventType => $label) {
            $events[] = [
                'event' => $eventType,
                'label' => $label,
                'checked' => $settings !== null && $settings->wants($eventType, CoreNoticeCatalogue::CHANNEL),
            ];
        }

        return $events;
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

    /**
     * @return list<string>
     */
    private function channels(Request $request): array
    {
        $channels = [];

        foreach ((array) $request->input('payment_channels', []) as $channel) {
            if (is_string($channel)) {
                $channels[] = $channel;
            }
        }

        return $channels;
    }

    private function blank(?string $value): ?string
    {
        return $value === null || trim($value) === '' ? null : trim($value);
    }

    /**
     * @return list<string>
     */
    private function lines(?string $text): array
    {
        $parts = preg_split('/[\r\n,]+/', (string) $text);

        return array_values(array_filter(array_map('trim', $parts === false ? [] : $parts), static fn (string $l): bool => $l !== ''));
    }

    /**
     * Les correspondances, lues bloc par bloc : une capacité et le
     * compteur qu'elle consomme.
     *
     * Un bloc incomplet — une seule des deux cases remplie — est ignoré,
     * comme l'était une ligne incomplète avant (ADR-0074 §5).
     *
     * @return array<string, string>
     */
    private function mappings(mixed $rows): array
    {
        $out = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $capability = $this->text($row['capability'] ?? null);
            $meter = $this->text($row['meter'] ?? null);

            if ($capability !== '' && $meter !== '') {
                $out[$capability] = $meter;
            }
        }

        return $out;
    }

    /**
     * ADR-0119 §1 — le catalogue de packs, lu bloc par bloc.
     *
     * Un bloc par pack : code, compteur, quantité, prix, libellé. Un
     * bloc entièrement vide est ignoré ; un bloc incomplet est refusé
     * par les mêmes règles qu'avant.
     *
     * Le prix est en unités mineures de la devise du projet (G5 : jamais
     * de float) ; pour XAF, l'unité mineure est le franc.
     *
     * Le compteur d'un pack doit être un compteur que le projet a
     * déclaré : un pack qui ne vise rien n'a pas de sens.
     *
     * @param list<string> $compteurs les compteurs déclarés par le projet
     * @return list<array{code: string, meter: string, quantity: int, unit_price_minor: int, label: string}>
     */
    private function packs(mixed $rows, array $compteurs): array
    {
        $packs = [];

        foreach ((array) $rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $code = $this->text($row['code'] ?? null);
            $meter = $this->text($row['meter'] ?? null);
            $quantity = $this->text($row['quantity'] ?? null);
            $prix = $this->text($row['unit_price_minor'] ?? null);
            $label = $this->text($row['label'] ?? null);

            // Un bloc entièrement vide est un bloc vide : il est ignoré.
            if ($code === '' && $meter === '' && $quantity === '' && $prix === '' && $label === '') {
                continue;
            }

            if (preg_match('/^[a-z0-9_-]{1,64}$/', $code) !== 1) {
                throw new InvalidBillingSettings('Le code d\'un pack est en minuscules, chiffres, « _ » et « - » (64 caractères au plus).');
            }

            if (preg_match('/^[a-z0-9_.]{1,64}$/', $meter) !== 1) {
                throw new InvalidBillingSettings('Le compteur d\'un pack est un nom de compteur : lettres minuscules, chiffres, « . » et « _ ».');
            }

            if ($compteurs !== [] && ! in_array($meter, $compteurs, true)) {
                throw new InvalidBillingSettings('Le compteur « '.$meter.' » n\'est pas un compteur déclaré par ce projet.');
            }

            if (preg_match('/^\d{1,9}$/', $quantity) !== 1 || (int) $quantity < 1) {
                throw new InvalidBillingSettings('La quantité d\'un pack est un entier au moins égal à 1.');
            }

            if (preg_match('/^\d{1,12}$/', $prix) !== 1) {
                throw new InvalidBillingSettings('Le prix d\'un pack est un entier positif, en unités mineures de la devise du projet.');
            }

            if ($label === '' || mb_strlen($label) > 120) {
                throw new InvalidBillingSettings('Le libellé d\'un pack est obligatoire (120 caractères au plus).');
            }

            $packs[] = [
                'code' => $code,
                'meter' => $meter,
                'quantity' => (int) $quantity,
                'unit_price_minor' => (int) $prix,
                'label' => $label,
            ];
        }

        return $packs;
    }

    /**
     * La valeur d'un champ du formulaire, comme texte coupé.
     */
    private function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
