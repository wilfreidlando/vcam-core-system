<?php

declare(strict_types=1);
use Core\Billing\Contracts\ConsumeUsage;
use Core\Billing\Contracts\ConsumeUsageCommand;
use Core\Billing\Contracts\CreatePlan;
use Core\Billing\Contracts\CreatePlanCommand;
use Core\Billing\Contracts\CreatePlanVersion;
use Core\Billing\Contracts\CreatePlanVersionCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\PublishPlanVersion;
use Core\Billing\Contracts\PublishPlanVersionCommand;
use Core\Billing\Contracts\UpsertCustomer;
use Core\Billing\Contracts\UpsertCustomerCommand;
use Core\Identity\Contracts\CreateOrganization;
use Core\Identity\Contracts\CreateOrganizationCommand;
use Core\Identity\Contracts\CreateProject;
use Core\Identity\Contracts\CreateProjectCommand;

if (getenv('MODE') === 'setup') {
    $o = app(CreateOrganization::class)->create(new CreateOrganizationCommand('Conc'), actor: 'service:t', correlationId: null);
    $p = app(CreateProject::class)->create(new CreateProjectCommand($o->id, 'Conc'), actor: 'service:t', correlationId: null)->project->id;
    app(UpsertCustomer::class)->upsert(new UpsertCustomerCommand($p, 'c1', 'C', null, null, 'fr', [], null, 't', null));
    app(CreatePlan::class)->create(new CreatePlanCommand($p, 'pro', 'Pro', null, 'public', 0, 't', null));
    $v = app(CreatePlanVersion::class)->createVersion(new CreatePlanVersionCommand($p, 'pro', 1000, 'XAF', 'month', 1, 0, 0, true, ['m' => ['type' => 'limit', 'meter' => 'msgs', 'limit' => 1, 'reset' => 'never', 'overage' => 'block', 'threshold_bps' => 5000]], 't', null));
    app(PublishPlanVersion::class)->publish(new PublishPlanVersionCommand($p, 'pro', $v->version, 't', null));
    app(CreateSubscription::class)->create(new CreateSubscriptionCommand($p, 'c1', 'pro', 14, null, 's', 't', null));
    echo "PROJECT=$p\n";
} else {
    $d = app(ConsumeUsage::class)->consume(new ConsumeUsageCommand((string) getenv('PROJECT'), 'c1', 'msgs', 1, 'conc-'.(string) getenv('K'), 'api'));
    echo "RESULT={$d->status}\n";
}
