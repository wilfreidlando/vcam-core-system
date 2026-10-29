<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\ListUsage;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Billing\Domain\Exception\InvalidBillingSettings;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Portal\Contracts\ManagePortalSettings;
use Core\Portal\Contracts\SavePortalSettingsCommand;
use Core\Portal\Domain\Exception\InvalidPortalSettings;
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
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly ManagePortalSettings $portal,
        private readonly ManageBillingSettings $billing,
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

        return response()->view('admin.billing.settings', [
            'title' => 'Réglages du commerce',
            'projectId' => $projectId,
            'settings' => $this->billing->get($projectId, $scope),
        ]);
    }

    public function saveSettings(Request $request, OperatorContext $operator, string $projectId): RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'plans:write');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'plans:write');

        /** @var array{timezone: string, default_currency: string, renewal_lead_days: int|string|string, incomplete_expiry_hours: int|string|string, invoice_prefix: string, default_tax_rate_percent: numeric-string|int|float|null, manual_receipt_threshold?: int|string|string|null, meter_mappings?: string|null} $data */
        $data = Validator::make($request->all(), [
            'timezone' => ['required', 'string', 'max:64'],
            'default_currency' => ['required', 'string', 'size:3'],
            'renewal_lead_days' => ['required', 'integer', 'min:0', 'max:90'],
            'incomplete_expiry_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'invoice_prefix' => ['required', 'string', 'regex:/^[A-Z0-9]{2,12}$/'],
            'default_tax_rate_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'manual_receipt_threshold' => ['nullable', 'integer', 'min:0'],
            'meter_mappings' => ['nullable', 'string', 'max:4000'],
            'refund_failed_usage' => ['nullable', 'boolean'],
            'require_customer_ref' => ['nullable', 'boolean'],
        ])->validate();

        $before = $this->billing->get($projectId, $scope);

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
                meterMappings: $this->mappings($data['meter_mappings'] ?? null),
                refundFailedUsage: $request->boolean('refund_failed_usage'),
                requireCustomerRef: $request->boolean('require_customer_ref'),
                actor: $this->actor($operator),
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
     * « notification.whatsapp = whatsapp_messages », one per line.
     *
     * @return array<string, string>
     */
    private function mappings(?string $text): array
    {
        $out = [];

        foreach ($this->lines($text) as $line) {
            [$capability, $meter] = array_pad(array_map('trim', explode('=', $line, 2)), 2, '');

            if ($capability !== '' && $meter !== '') {
                $out[$capability] = $meter;
            }
        }

        return $out;
    }
}
