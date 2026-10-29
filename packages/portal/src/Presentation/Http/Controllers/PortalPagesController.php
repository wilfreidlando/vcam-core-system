<?php

declare(strict_types=1);

namespace Core\Portal\Presentation\Http\Controllers;

use Core\Portal\Application\Dto\PortalPrincipal;
use Core\Portal\Application\Exception\PortalActionRefused;
use Core\Portal\Application\Support\PortalPalette;
use Core\Portal\Application\UseCase\PortalSessionService;
use Core\Portal\Application\UseCase\PortalWorkspace;
use Core\Portal\Presentation\Http\Middleware\AuthenticatePortal;
use Core\Portal\Presentation\Http\PortalCookie;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The pages of the portal (ADR-0075 §3). Thin: the rules are in
 * `PortalWorkspace`; this class only turns a request into a page.
 *
 * A route that is not in this class does not exist.
 */
final class PortalPagesController
{
    public function __construct(
        private readonly PortalSessionService $sessions,
        private readonly PortalWorkspace $workspace,
    ) {}

    public function enter(Request $request, string $token): Response|RedirectResponse
    {
        $exchanged = $this->sessions->exchange($token);

        if ($exchanged === null) {
            return response()->view('portal::link-invalid', [], 410);
        }

        $response = new RedirectResponse($exchanged->landingPath);
        $response->headers->setCookie(PortalCookie::make($request, $exchanged->cookieValue, $exchanged->cookieLifetimeSeconds));

        return $response;
    }

    public function theme(Request $request): Response
    {
        $cookie = $request->cookies->get(PortalCookie::name($request));
        $principal = $this->sessions->authenticate(is_string($cookie) ? $cookie : null);

        // Strictly validated, derived tokens included (WCAG 1.4.3): see PortalPalette.
        return response(PortalPalette::css($principal?->branding->primaryColor), 200, [
            'Content-Type' => 'text/css; charset=utf-8',
        ]);
    }

    public function home(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::home', $who, ['home' => $this->workspace->home($who)]);
    }

    public function subscriptions(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::subscriptions', $who, ['subscriptions' => $this->workspace->subscriptions($who), 'nonce' => bin2hex(random_bytes(16))]);
    }

    public function invoices(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::invoices', $who, ['invoices' => $this->workspace->invoices($who)]);
    }

    public function invoice(Request $request, string $invoiceId): View|Response
    {
        $who = $this->who($request);
        $detail = preg_match('/^[0-9a-f-]{36}\\z/', $invoiceId) === 1 ? $this->workspace->invoice($who, $invoiceId) : null;

        if ($detail === null) {
            return $this->notFound($who);
        }

        return $this->page('portal::invoice', $who, ['detail' => $detail, 'error' => null]);
    }

    public function pay(Request $request, string $invoiceId): View|Response
    {
        $who = $this->who($request);

        try {
            $outcome = $this->workspace->pay(
                $who,
                $invoiceId,
                $request->string('channel')->toString(),
                $request->string('phone')->toString(),
                $request->string('nonce')->toString(),
            );
        } catch (PortalActionRefused $refused) {
            $detail = preg_match('/^[0-9a-f-]{36}\\z/', $invoiceId) === 1 ? $this->workspace->invoice($who, $invoiceId) : null;

            if ($detail === null) {
                return $this->notFound($who);
            }

            return response()->view('portal::invoice', $this->data($who, ['detail' => $detail, 'error' => $refused->getMessage()]), 422);
        }

        // Rendered, not redirected: the provider's address must not be
        // stored, and a redirect to another origin would be refused by the
        // `form-action 'self'` policy.
        return $this->page('portal::payment-started', $who, ['outcome' => $outcome]);
    }

    public function plans(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::plans', $who, ['plans' => $this->workspace->plansPage($who)]);
    }

    public function payments(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::payments', $who, ['lines' => $this->workspace->payments($who)]);
    }

    public function profile(Request $request): View|Response
    {
        $who = $this->who($request);
        $profile = $this->workspace->profile($who);

        return $profile === null ? $this->notFound($who) : $this->page('portal::profile', $who, ['profile' => $profile]);
    }

    public function changePlan(Request $request, string $subscriptionId): RedirectResponse|Response
    {
        $who = $this->who($request);

        try {
            $this->workspace->changePlan($who, $subscriptionId, $request->string('plan')->toString());
        } catch (PortalActionRefused $refused) {
            return response()->view('portal::error', $this->data($who, ['title' => 'Action impossible', 'message' => $refused->getMessage()]), 422);
        }

        return new RedirectResponse('/portal/subscriptions');
    }

    public function subscribe(Request $request, string $planCode): RedirectResponse|Response
    {
        $who = $this->who($request);

        try {
            $this->workspace->subscribe($who, $planCode, $request->string('nonce')->toString());
        } catch (PortalActionRefused $refused) {
            return response()->view('portal::error', $this->data($who, ['title' => 'Action impossible', 'message' => $refused->getMessage()]), 422);
        }

        // The first invoice is waiting: send the customer where it can be paid.
        return new RedirectResponse('/portal/invoices');
    }

    public function keepRenewing(Request $request, string $subscriptionId): RedirectResponse|Response
    {
        $who = $this->who($request);

        try {
            $this->workspace->keepRenewing($who, $subscriptionId);
        } catch (PortalActionRefused $refused) {
            return response()->view('portal::error', $this->data($who, ['title' => 'Action impossible', 'message' => $refused->getMessage()]), 422);
        }

        return new RedirectResponse('/portal/subscriptions');
    }

    public function renew(Request $request, string $subscriptionId): RedirectResponse|Response
    {
        $who = $this->who($request);

        try {
            $this->workspace->renew($who, $subscriptionId, $request->string('nonce')->toString());
        } catch (PortalActionRefused $refused) {
            return response()->view('portal::error', $this->data($who, ['title' => 'Action impossible', 'message' => $refused->getMessage()]), 422);
        }

        // The first invoice of the new contract is waiting.
        return new RedirectResponse('/portal/invoices');
    }

    public function usage(Request $request): View
    {
        $who = $this->who($request);

        return $this->page('portal::usage', $who, ['entitlements' => $this->workspace->entitlements($who)]);
    }

    public function cancel(Request $request, string $subscriptionId): RedirectResponse|Response
    {
        $who = $this->who($request);

        try {
            $this->workspace->cancelAtPeriodEnd($who, $subscriptionId);
        } catch (PortalActionRefused $refused) {
            return response()->view('portal::error', $this->data($who, ['title' => 'Action impossible', 'message' => $refused->getMessage()]), 422);
        }

        return new RedirectResponse('/portal/subscriptions');
    }

    public function logout(Request $request): Response
    {
        $this->sessions->end($this->who($request));

        $response = response()->view('portal::signed-out', [], 200);
        $response->headers->setCookie(PortalCookie::forget($request));

        return $response;
    }

    private function who(Request $request): PortalPrincipal
    {
        /** @var PortalPrincipal $principal */
        $principal = $request->attributes->get(AuthenticatePortal::ATTRIBUTE);

        return $principal;
    }

    /**
     * @param view-string $view
     * @param array<string, mixed> $extra
     */
    private function page(string $view, PortalPrincipal $who, array $extra): View
    {
        return view($view, $this->data($who, $extra));
    }

    private function notFound(PortalPrincipal $who): Response
    {
        return response()->view('portal::error', $this->data($who, [
            'title' => 'Introuvable',
            'message' => 'Cette page n\'existe pas ou ne vous est pas accessible.',
        ]), 404);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function data(PortalPrincipal $who, array $extra): array
    {
        return $extra + [
            'principal' => $who,
            'csrf' => $this->sessions->csrfToken($who),
        ];
    }
}
