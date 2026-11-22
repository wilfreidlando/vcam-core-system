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
 * to copy. ADR-0111 — it opens it **in read only** too, for the operator
 * who needs to see what the client sees without touching anything.
 *
 * The gesture is the portal's own (ADR-0075 §1): the console asks the
 * module — through its published contract, exactly like an application's
 * server does — and gets a **one-time link** back.
 *
 * Two gestures, two flows:
 *
 *  - `manage` — the link is meant for the **client**: it lets them manage
 *    their subscription. It is the link the operator copies and hands
 *    over;
 *  - `read` — the link is meant for the **operator**: same pages, same
 *    data, and not one gesture of action (ADR-0111 §1).
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
     *
     * The link is meant for the **client**: it lets them manage their
     * subscription. The operator has another need — *see* what the client
     * sees, without touching anything — and gets his own gesture,
     * `read()`.
     */
    public function open(Request $request, OperatorContext $operator, string $projectId, string $externalRef): Response|RedirectResponse
    {
        return $this->openSpace($request, $operator, $projectId, $externalRef, 'manage');
    }

    /**
     * Opens the customer's space **in read only** (ADR-0111).
     *
     * Same pages, same data, and not one gesture of action: the module
     * refuses every mutation on a read session — the refusal is in the
     * code that acts, not in the view (ADR-0111 §2).
     */
    public function read(Request $request, OperatorContext $operator, string $projectId, string $externalRef): Response|RedirectResponse
    {
        return $this->openSpace($request, $operator, $projectId, $externalRef, 'read');
    }

    /**
     * Opens a session of the portal for that customer, and renders the
     * link to copy.
     */
    private function openSpace(Request $request, OperatorContext $operator, string $projectId, string $externalRef, string $flow): Response|RedirectResponse
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
                flow: $flow,
                callerIp: $request->ip(),
                correlationId: null,
            ));
        } catch (RuntimeException $exception) {
            return $this->refused($exception);
        }

        // The gesture is auditable: what was opened, for whom, and in
        // which flow. The link itself never enters the journal
        // (ADR-0083 §4).
        $this->audit->record(new AuditEntry(
            actor: 'operator:'.$operator->operatorId,
            action: 'portal.session.opened',
            resource: 'customer:'.$customer->id,
            before: null,
            after: null,
            context: ['project_id' => $projectId, 'customer_ref' => $externalRef, 'flow' => $flow],
            correlationId: null,
            occurredAt: $this->clock->now(),
            projectId: $projectId,
        ));

        // The status line is for this response only: nothing of it
        // reaches the session store (ADR-0081 S-07).
        // @phpstan-ignore-next-line — session()->now() dynamic call, same class of Laravel stub gap as Request::validate().
        session()->now('status', $flow === 'read'
            ? 'Espace du client ouvert en lecture seule : ce lien est pour vous, votre client ne pourra rien y changer.'
            : 'Espace du client ouvert : copiez le lien ci-dessous et donnez-le à votre client.');

        return $this->sheet($request, $operator, $projectId, $externalRef, $opened, $scope, $flow);
    }

    /**
     * The customer's sheet, with the link to copy and — when the project
     * offers it — the login by code.
     */
    private function sheet(Request $request, OperatorContext $operator, string $projectId, string $externalRef, OpenedPortalSession $opened, AccessScope $scope, string $flow): Response
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
            'flow' => $flow,
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
