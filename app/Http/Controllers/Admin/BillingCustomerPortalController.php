<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\HandlesBillingRefusals;
use App\Http\Controllers\Admin\Concerns\ResolvesScopedProject;
use App\Http\View\PortalDirectLogin;
use Core\Audit\Contracts\AuditEntry;
use Core\Audit\Contracts\AuditLogger;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Domain\Exception\CustomerNotFound;
use Core\Identity\Contracts\GetProject;
use Core\Identity\Contracts\OperatorContext;
use Core\Portal\Contracts\OpenedPortalSession;
use Core\Portal\Contracts\OpenPortalSession;
use Core\Portal\Contracts\OpenPortalSessionCommand;
use Core\Support\Access\AccessScope;
use Core\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * ADR-0125 — the console opens the customer's space and renders the link
 * to copy.
 *
 * The gesture is the portal's own (ADR-0075 §1): the console asks the
 * module — through its published contract, exactly like an application's
 * server does — and gets a **one-time link** back.
 *
 * Three rules shape the screen:
 *
 *  - **the link is a secret** (ADR-0083 §4): it is rendered in THIS
 *    response and never flashed into the session, where a flash would
 *    outlive the response that carries it (ADR-0081 S-07). The sheet is
 *    therefore rendered here, not redirected to;
 *  - **the audit keeps the act, never the link**: the entry names the
 *    project and the customer, and nothing that opens their space;
 *  - **the console speaks to the operator**: the link sits in a
 *    read-only field (the console has no JavaScript, ADR-0104), with the
 *    moment it stops working and the fact that it is worth one use.
 */
final class BillingCustomerPortalController
{
    use HandlesBillingRefusals;
    use ResolvesScopedProject;

    public function __construct(
        private readonly GetProject $getProject,
        private readonly GetCustomer $customers,
        private readonly OpenPortalSession $openPortal,
        private readonly PortalDirectLogin $directLoginBlock,
        private readonly AuditLogger $audit,
        private readonly Clock $clock,
    ) {}

    /**
     * Opens the customer's space and renders the link to copy.
     */
    public function open(Request $request, OperatorContext $operator, string $projectId, string $externalRef): Response|RedirectResponse
    {
        $scope = $this->permittedScope($operator, 'customers:read');
        $this->resolveScopedProject($this->getProject, $operator, $projectId, 'customers:read');

        // The client must exist: there is no space to open for a customer
        // this project does not know.
        $customer = $this->customers->get(new GetCustomerCommand($projectId, $externalRef), $scope);

        if ($customer === null || $customer->erasedAt !== null) {
            return $this->refused(CustomerNotFound::becauseExternalRef($externalRef));
        }

        try {
            $opened = $this->openPortal->open(new OpenPortalSessionCommand(
                projectId: $projectId,
                customerRef: $externalRef,
                flow: 'manage',
                callerIp: $request->ip(),
                correlationId: null,
            ));
        } catch (RuntimeException $exception) {
            return $this->refused($exception);
        }

        // The gesture is auditable: what was opened, for whom. The link
        // itself never enters the journal (ADR-0083 §4).
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'portal.session.opened',
            resource: 'customer:'.$customer->id,
            before: null,
            after: null,
            context: ['project_id' => $projectId, 'customer_ref' => $externalRef, 'flow' => 'manage'],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        // The status line is for this response only: nothing of it
        // reaches the session store (ADR-0081 S-07).
        // @phpstan-ignore-next-line — session()->now() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->now('status', 'Espace du client ouvert : copiez le lien ci-dessous et donnez-le à votre client.');

        return $this->sheet($request, $operator, $projectId, $externalRef, $opened, $scope);
    }

    /**
     * The customer's sheet, with the link to copy and — when the project
     * offers it — the login by code.
     */
    private function sheet(Request $request, OperatorContext $operator, string $projectId, string $externalRef, OpenedPortalSession $opened, AccessScope $scope): Response
    {
        // The sheet is rendered by the customer controller: the same page,
        // with two blocks added. The link travels with the **request**,
        // never with the session: a secret that outlives the response
        // that carries it would live in the session store, and a shared
        // view variable would survive into the next request (ADR-0081
        // S-07).
        $request->attributes->set('portal_link', [
            'url' => $opened->url,
            'expires_at' => $opened->expiresAt,
            'expires_label' => $this->expiryLabel($opened->expiresAt),
        ]);

        $request->attributes->set('portal_direct_login', $this->directLoginBlock->for($projectId, $scope));

        return app(CustomerOverviewController::class)->show($operator, $projectId, $externalRef);
    }

    /**
     * The moment the link stops working, said the way the console says
     * its dates.
     */
    private function expiryLabel(string $expiresAt): string
    {
        return \Illuminate\Support\Carbon::parse($expiresAt)->format('d/m/Y à H:i');
    }
}
