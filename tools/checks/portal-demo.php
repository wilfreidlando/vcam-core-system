<?php

declare(strict_types=1);

// Demo data for looking at the portal in a browser (development database only).
//   docker compose exec app php artisan tinker --execute="require 'tools/checks/portal-demo.php';"

use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\CreateInvoice;
use Core\Billing\Contracts\CreateInvoiceCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\InvoiceLineInput;
use Core\Billing\Contracts\IssueInvoice;
use Core\Billing\Contracts\IssueInvoiceCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\SaveBillingSettingsCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;
use Core\Portal\Application\Dto\OpenPortalSessionCommand;
use Core\Portal\Application\Dto\PortalSettingsData;
use Core\Portal\Application\UseCase\OpenPortalSessionUseCase;
use Core\Portal\Application\UseCase\PortalSettingsService;
use Core\Support\Access\AccessScope;

// Idempotent : le script est relancé souvent pendant une recette, et il
// ne doit pas empiler des organisations « Demo SaaS » identiques. On
// reprend le projet le plus récent s'il existe déjà.
$existing = Illuminate\Support\Facades\DB::table('projects')
    ->where('name', 'Mon Auto-École')
    ->orderByDesc('created_at')
    ->value('id');

$project = is_string($existing) ? $existing : null;

if ($project === null) {
    $org = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Demo SaaS'), actor: 'service:demo', correlationId: null);
    $project = app(CreateProject::class)->create(new CreateProjectCommand($org->id, 'Mon Auto-École'), actor: 'service:demo', correlationId: null)->project->id;
}

app(PortalSettingsService::class)->save($project, new PortalSettingsData(
    'Mon Auto-École', null, '#0f766e', 'mailto:support@mon-auto-ecole.cm', 'fr', ['http://localhost:8000'], true, true, [],
), null);

foreach ([['pro', 'Pro', 25000, 100, 500], ['premium', 'Premium', 60000, 500, 5000]] as [$code, $name, $price, $students, $messages]) {
    // Idempotent: the plan already carries the code, it is not created
    // again — the second run of the script would fail with
    // `PlanCodeAlreadyTaken`.
    $existe = Illuminate\Support\Facades\DB::table('plans')
        ->where('project_id', $project)
        ->where('code', $code)
        ->exists();

    if ($existe) {
        continue;
    }

    app(CreatePlan::class)->create(new CreatePlanCommand($project, $code, $name, 'Pour gérer votre auto-école au quotidien.', 'public', 0, 'demo', null));
    $v = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand($project, $code, $price, 'XAF', 'month', 1, 0, 7, true, [
        'students' => ['type' => 'limit', 'meter' => 'students', 'limit' => $students, 'reset' => 'never', 'overage' => 'block', 'threshold_bps' => 8000],
        'whatsapp_messages' => ['type' => 'limit', 'meter' => 'whatsapp_messages', 'limit' => $messages, 'reset' => 'period', 'overage' => 'block'],
        'exam_planning' => ['type' => 'boolean', 'enabled' => true],
    ], 'demo', null));
    app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand($project, $code, $v->version, 'demo', null));
}

$customer = ['display_name' => 'Auto-école Soleil', 'email' => 'direction@soleil.cm', 'phone' => '+237657467941'];
$first = app(OpenPortalSessionUseCase::class)->open(new OpenPortalSessionCommand($project, 'AE0001', customer: $customer, planCode: 'pro', trialDays: 14));

// The driving school the client application (the auto-école) links to the
// Core: it needs a contract of its own, or the Core refuses its WhatsApp
// sends. The subscription is only created when the customer has none —
// opening the session again changes nothing.
//
// It lives in **its own** project (the one the client application is
// registered on), which is not necessarily the project this script seeds.
$projetDuClient = Illuminate\Support\Facades\DB::table('customers')
    ->where('external_ref', 'ae0000')
    ->orderByDesc('created_at')
    ->value('project_id');

if (is_string($projetDuClient)) {
    app(OpenPortalSessionUseCase::class)->open(new OpenPortalSessionCommand($projetDuClient, 'ae0000', planCode: 'pro', trialDays: 14));

    // ADR-0074 §5 — the project declares which capability of the Core
    // feeds which meter. Without the declaration, the WhatsApp messages
    // are never counted: the quota is not enforced, and the client
    // application cannot read how many messages are left.
    $reglages = app(ManageBillingSettings::class)->get($projetDuClient, AccessScope::platform());

    if ($reglages === null) {
        throw new RuntimeException('Les réglages du projet sont introuvables.');
    }

    app(ManageBillingSettings::class)->save(new SaveBillingSettingsCommand(
        projectId: $projetDuClient,
        timezone: $reglages->timezone,
        defaultCurrency: $reglages->defaultCurrency,
        renewalLeadDays: $reglages->renewalLeadDays,
        incompleteExpiryHours: $reglages->incompleteExpiryHours,
        invoicePrefix: $reglages->invoicePrefix,
        defaultTaxRateBps: $reglages->defaultTaxRateBps,
        manualReceiptApprovalThresholdMinor: $reglages->manualReceiptApprovalThresholdMinor,
        meterMappings: array_merge($reglages->meterMappings, ['notification.whatsapp' => 'whatsapp_messages']),
        refundFailedUsage: $reglages->refundFailedUsage,
        requireCustomerRef: $reglages->requireCustomerRef,
        actor: 'demo',
    ), AccessScope::platform());
}

foreach ([1 => 82, 2 => 310] as $i => $qty) {
    app(ConsumeUsage::class)->consume(new ConsumeUsageCommand($project, 'AE0001', $i === 1 ? 'students' : 'whatsapp_messages', $qty, 'demo-'.$i, 'api'));
}

$draft = app(CreateInvoice::class)->create(new CreateInvoiceCommand(projectId: $project, customerRef: 'AE0001', lines: [new InvoiceLineInput(description: 'Abonnement Pro — novembre 2026', quantity: 1, unitAmountMinor: 25000)], actor: 'demo', correlationId: null));
app(IssueInvoice::class)->issue(new IssueInvoiceCommand(projectId: $project, invoiceId: $draft->id, actor: 'demo', correlationId: null));

$link = app(OpenPortalSessionUseCase::class)->open(new OpenPortalSessionCommand($project, 'AE0001', returnUrl: 'http://localhost:8000/compte'));
echo 'LINK='.$link->url."\n";

return $project;
