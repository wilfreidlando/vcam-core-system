<?php

declare(strict_types=1);

namespace Core\Portal\Application\UseCase;

use Core\Billing\Contracts\CancelSubscription;
use Core\Billing\Contracts\CancelSubscriptionCommand;
use Core\Billing\Contracts\ChangeSubscriptionPlan;
use Core\Billing\Contracts\ChangeSubscriptionPlanCommand;
use Core\Billing\Contracts\CreateSubscription;
use Core\Billing\Contracts\CreateSubscriptionCommand;
use Core\Billing\Contracts\EntitlementsView;
use Core\Billing\Contracts\GetCustomer;
use Core\Billing\Contracts\GetCustomerCommand;
use Core\Billing\Contracts\GetEntitlements;
use Core\Billing\Contracts\GetEntitlementsCommand;
use Core\Billing\Contracts\GetInvoice;
use Core\Billing\Contracts\GetInvoiceCommand;
use Core\Billing\Contracts\GetSubscription;
use Core\Billing\Contracts\GetSubscriptionCommand;
use Core\Billing\Contracts\InvoiceView;
use Core\Billing\Contracts\KeepSubscriptionRenewing;
use Core\Billing\Contracts\KeepSubscriptionRenewingCommand;
use Core\Billing\Contracts\ListInvoicePayments;
use Core\Billing\Contracts\ListInvoicePaymentsCommand;
use Core\Billing\Contracts\ListInvoices;
use Core\Billing\Contracts\ListInvoicesCommand;
use Core\Billing\Contracts\ListPlans;
use Core\Billing\Contracts\ListPlansCommand;
use Core\Billing\Contracts\ListSubscriptions;
use Core\Billing\Contracts\ListSubscriptionsCommand;
use Core\Billing\Contracts\ListSubscriptionSupplements;
use Core\Billing\Contracts\ListSubscriptionSupplementsCommand;
use Core\Billing\Contracts\ManageBillingSettings;
use Core\Billing\Contracts\PayInvoice;
use Core\Billing\Contracts\PayInvoiceCommand;
use Core\Billing\Contracts\PlanVersionView;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPack;
use Core\Billing\Contracts\PurchaseSubscriptionSupplementPackCommand;
use Core\Billing\Contracts\SubscriptionView;
use Core\Billing\Contracts\SupplementPackView;
use Core\Payment\Contracts\NoProviderForChannelException;
use Core\Payment\Contracts\PaymentChannel;
use Core\Payment\Contracts\PaymentProviderRouter;
use Core\Portal\Application\Dto\PortalHome;
use Core\Portal\Application\Dto\PortalInvoiceDetail;
use Core\Portal\Application\Dto\PortalPackGroup;
use Core\Portal\Application\Dto\PortalPackOffer;
use Core\Portal\Application\Dto\PortalPackPurchase;
use Core\Portal\Application\Dto\PortalPaymentLine;
use Core\Portal\Application\Dto\PortalPaymentOutcome;
use Core\Portal\Application\Dto\PortalPlanOption;
use Core\Portal\Application\Dto\PortalPlans;
use Core\Portal\Application\Dto\PortalPrincipal;
use Core\Portal\Application\Dto\PortalProfile;
use Core\Portal\Application\Dto\PortalUsage;
use Core\Portal\Application\Exception\PortalActionRefused;
use Core\Portal\Application\Support\PortalFormat;
use Core\Portal\Application\Support\PortalLinks;
use Core\Portal\Domain\Port\SecretHasher;
use Core\Support\Access\AccessScope;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * ADR-0075 §3 — everything a customer can see or do in the portal.
 *
 * The portal owns no business table: it asks `billing` through its
 * contracts, always with the `(project, customer)` of the session. A thing
 * that belongs to another customer is **not found** — never "forbidden"
 * (G15) — and an action that is not in this class does not exist.
 */
final readonly class PortalWorkspace
{
    /**
     * The rails the platform's payment endpoint knows (ADR-0051). The
     * project configures none of them: whichever the platform can route
     * for it is offered; `portal_settings.payment_channels` only narrows.
     */
    private const PLATFORM_CHANNELS = ['mtn_momo', 'orange_money', 'card'];

    public function __construct(
        private GetCustomer $customers,
        private ListSubscriptions $subscriptions,
        private GetSubscription $subscription,
        private CancelSubscription $cancelSubscription,
        private ListInvoices $invoices,
        private GetInvoice $invoice,
        private ListInvoicePayments $invoicePayments,
        private PayInvoice $payInvoice,
        private ChangeSubscriptionPlan $changePlan,
        private ListPlans $plans,
        private CreateSubscription $createSubscription,
        private KeepSubscriptionRenewing $keepRenewing,
        private PaymentProviderRouter $router,
        private GetEntitlements $entitlements,
        private ManageBillingSettings $settings,
        private ListSubscriptionSupplements $supplements,
        private PurchaseSubscriptionSupplementPack $purchasePack,
        private SecretHasher $hasher,
        private PortalLinks $links,
        private LoggerInterface $logger,
    ) {}

    public function home(PortalPrincipal $who): PortalHome
    {
        $subscriptions = $this->subscriptions($who);
        $all = $this->invoices($who);
        $open = array_values(array_filter($all, static fn (InvoiceView $i): bool => $i->status === 'open'));

        $due = [];
        $nextDue = null;
        foreach ($open as $invoice) {
            $due[$invoice->currency] = ($due[$invoice->currency] ?? 0) + max(0, $invoice->totalMinor - $invoice->amountPaidMinor);

            if ($invoice->dueAt !== null && ($nextDue === null || $invoice->dueAt < $nextDue)) {
                $nextDue = $invoice->dueAt;
            }
        }

        // What the customer paid over the last twelve months, per currency.
        $since = gmdate(DATE_ATOM, strtotime('-12 months'));
        $paid = [];
        foreach ($all as $invoice) {
            if ($invoice->amountPaidMinor > 0 && ($invoice->paidAt ?? $invoice->updatedAt) >= $since) {
                $paid[$invoice->currency] = ($paid[$invoice->currency] ?? 0) + $invoice->amountPaidMinor;
            }
        }

        $nextRenewal = null;
        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->status, ['active', 'trialing', 'past_due'], true)
                && $subscription->currentPeriodEnd !== null
                && ($nextRenewal === null || $subscription->currentPeriodEnd < $nextRenewal)) {
                $nextRenewal = $subscription->currentPeriodEnd;
            }
        }

        usort($all, static fn (InvoiceView $a, InvoiceView $b): int => strcmp($b->createdAt, $a->createdAt));

        $customer = $this->customers->get(new GetCustomerCommand($who->projectId, $who->customerRef), $this->scope($who));

        return new PortalHome(
            $customer->displayName ?? $who->customerRef,
            $subscriptions,
            $open,
            $due,
            $this->entitlements($who),
            $paid,
            array_slice($all, 0, 4),
            $nextDue,
            $nextRenewal,
        );
    }

    /**
     * @return list<SubscriptionView>
     */
    public function subscriptions(PortalPrincipal $who): array
    {
        $page = $this->subscriptions->list(new ListSubscriptionsCommand($who->projectId, $who->customerRef, null, null, 100), $this->scope($who));

        return $page->items;
    }

    /**
     * ADR-0075 §3 — the offers page. The catalogue of **public** plans is
     * shown to every customer (a customer who cannot change alone still
     * sees what exists); the button to move is offered only when the
     * project allowed it and a live contract can carry the change.
     */
    public function plansPage(PortalPrincipal $who): PortalPlans
    {
        $subscriptions = $this->subscriptions($who);
        $changeable = null;

        // A read-only session shows the catalogue but moves nothing: the
        // button would be a lie the workspace would refuse.
        $mayAct = ! $who->readOnly;

        if ($mayAct && $who->branding->allowPlanChange) {
            foreach ($subscriptions as $subscription) {
                if (in_array($subscription->status, ['active', 'trialing', 'past_due'], true) && ! $subscription->cancelAtPeriodEnd) {
                    $changeable = $subscription->id;

                    break;
                }
            }
        }

        // A customer with no live contract can take one alone, when the project allows self-service plans.
        $hasLive = false;
        foreach ($subscriptions as $subscription) {
            if (! in_array($subscription->status, ['canceled', 'expired'], true)) {
                $hasLive = true;
            }
        }

        return new PortalPlans(
            $this->planOptions($who, $subscriptions),
            $changeable,
            $mayAct && $who->branding->allowPlanChange && ! $hasLive,
            bin2hex(random_bytes(16)),
        );
    }

    /**
     * ADR-0075 §3 — move to another **public** plan, at the end of the
     * period already paid (no pro-rata, no surprise charge).
     */
    public function changePlan(PortalPrincipal $who, string $subscriptionId, string $planCode): void
    {
        $this->assertWritable($who, 'le changement d\'offre');

        if (! $who->branding->allowPlanChange) {
            throw PortalActionRefused::because('Le changement d\'offre n\'est pas disponible depuis cet espace.');
        }

        $subscription = $this->subscription->get(new GetSubscriptionCommand($who->projectId, $subscriptionId), $this->scope($who));

        if ($subscription === null || $subscription->customerId !== $who->customerId) {
            throw PortalActionRefused::because('Cet abonnement est introuvable.');
        }

        // A contract that is ending or no longer alive takes no new terms: the
        // scheduler would drop the request silently.
        if ($subscription->cancelAtPeriodEnd || ! in_array($subscription->status, ['active', 'trialing', 'past_due'], true)) {
            throw PortalActionRefused::because('Cet abonnement ne peut pas changer d\'offre : reprenez d\'abord son renouvellement ou souscrivez à nouveau.');
        }

        // Only what the project shows as a public plan can be chosen: a
        // private plan (a negotiated price) is never reachable by guessing a code.
        $offered = array_filter($this->planOptions($who, [$subscription]), static fn (PortalPlanOption $o): bool => ! $o->isCurrent && $o->code === $planCode);

        if ($offered === []) {
            throw PortalActionRefused::because('Cette offre n\'est pas proposée.');
        }

        try {
            $this->changePlan->changePlan(new ChangeSubscriptionPlanCommand(
                projectId: $who->projectId,
                subscriptionId: $subscription->id,
                planCode: $planCode,
                effective: 'period_end',
                actor: 'portal:'.$who->customerId,
            ));
        } catch (RuntimeException $e) {
            $this->logger->warning('portal.change_plan.refused', ['subscription_id' => $subscription->id, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('Le changement d\'offre n\'a pas pu être pris en compte.');
        }
    }

    /**
     * ADR-0075 §3 — a customer with no live contract subscribes alone to a
     * **public** plan. The first invoice then waits in « Factures » to be paid.
     */
    public function subscribe(PortalPrincipal $who, string $planCode, string $nonce): void
    {
        $this->assertWritable($who, 'la souscription');

        if (! $who->branding->allowPlanChange) {
            throw PortalActionRefused::because('La souscription n\'est pas disponible depuis cet espace.');
        }

        if (preg_match('/^[a-f0-9]{16,64}$/', $nonce) !== 1) {
            throw PortalActionRefused::because('Le formulaire a expiré, rechargez la page.');
        }

        $subscriptions = $this->subscriptions($who);

        foreach ($subscriptions as $subscription) {
            if (in_array($subscription->status, ['canceled', 'expired'], true)) {
                continue;
            }

            // The same plan already live: this is the replay of a double click, and
            // the customer got what it asked for — not an error.
            if ($subscription->planCode === $planCode) {
                return;
            }

            throw PortalActionRefused::because('Vous avez déjà un abonnement en cours.');
        }

        $offered = array_filter($this->planOptions($who, $subscriptions), static fn (PortalPlanOption $o): bool => $o->code === $planCode);

        if ($offered === []) {
            throw PortalActionRefused::because('Cette offre n\'est pas proposée.');
        }

        try {
            $this->createSubscription->create(new CreateSubscriptionCommand(
                projectId: $who->projectId,
                customerRef: $who->customerRef,
                planCode: $planCode,
                trialDays: null,
                startAt: null,
                idempotencyKey: 'portal-subscribe:'.$this->hasher->hash($who->sessionId.'|'.$planCode.'|'.$nonce, 'subscribe'),
                actor: 'portal:'.$who->customerId,
            ));
        } catch (RuntimeException $e) {
            $this->logger->warning('portal.subscribe.refused', ['plan' => $planCode, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('La souscription n\'a pas pu être enregistrée.');
        }
    }

    /**
     * ADR-0075 §3 — the customer takes back its « do not renew »: the
     * contract renews again at the end of the period. Allowed to whoever the
     * project allowed to cancel.
     */
    public function keepRenewing(PortalPrincipal $who, string $subscriptionId): void
    {
        $this->assertWritable($who, 'la reprise du renouvellement');

        if (! $who->branding->allowSelfCancel) {
            throw PortalActionRefused::because('Cette action n\'est pas disponible depuis cet espace.');
        }

        $subscription = $this->subscription->get(new GetSubscriptionCommand($who->projectId, $subscriptionId), $this->scope($who));

        if ($subscription === null || $subscription->customerId !== $who->customerId) {
            throw PortalActionRefused::because('Cet abonnement est introuvable.');
        }

        try {
            $this->keepRenewing->keep(new KeepSubscriptionRenewingCommand($who->projectId, $subscription->id, 'portal:'.$who->customerId));
        } catch (RuntimeException $e) {
            $this->logger->warning('portal.keep_renewing.refused', ['subscription_id' => $subscription->id, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('Cet abonnement ne peut plus être reconduit : choisissez une offre pour vous réabonner.');
        }
    }

    /**
     * ADR-0075 §3 — « Renouveler » a contract that has ended: a new
     * contract on the same plan, whose first invoice then waits to be paid.
     * Only the customer's own ended contract can be the model.
     */
    public function renew(PortalPrincipal $who, string $subscriptionId, string $nonce): void
    {
        $this->assertWritable($who, 'le renouvellement');

        $ended = $this->subscription->get(new GetSubscriptionCommand($who->projectId, $subscriptionId), $this->scope($who));

        if ($ended === null || $ended->customerId !== $who->customerId || ! in_array($ended->status, ['canceled', 'expired'], true)) {
            throw PortalActionRefused::because('Cet abonnement ne peut pas être renouvelé.');
        }

        $this->subscribe($who, $ended->planCode, $nonce);
    }

    /**
     * Every payment the customer attempted, newest first, with the invoice
     * it was for. The history of the twenty latest invoices — enough for a
     * receipt, bounded for the page.
     *
     * @return list<PortalPaymentLine>
     */
    public function payments(PortalPrincipal $who): array
    {
        $invoices = $this->invoices($who);
        usort($invoices, static fn (InvoiceView $a, InvoiceView $b): int => strcmp($b->createdAt, $a->createdAt));

        $lines = [];

        foreach (array_slice($invoices, 0, 20) as $invoice) {
            $page = $this->invoicePayments->list(new ListInvoicePaymentsCommand($who->projectId, $invoice->id, null, 20), $this->scope($who));

            foreach ($page->items as $payment) {
                $lines[] = new PortalPaymentLine($payment, $invoice->id, $invoice->number);
            }
        }

        usort($lines, static fn (PortalPaymentLine $a, PortalPaymentLine $b): int => strcmp($b->payment->createdAt ?? '', $a->payment->createdAt ?? ''));

        return $lines;
    }

    public function profile(PortalPrincipal $who): ?PortalProfile
    {
        $customer = $this->customers->get(new GetCustomerCommand($who->projectId, $who->customerRef), $this->scope($who));

        if ($customer === null) {
            return null;
        }

        return new PortalProfile(
            $customer->displayName,
            $customer->externalRef,
            $customer->email === null ? null : self::maskEmail($customer->email),
            $customer->phone === null ? null : self::maskPhone($customer->phone),
            $customer->locale,
            $customer->createdAt,
        );
    }

    /**
     * What the customer is asked to pay or has paid. Drafts are the
     * project's working papers: never shown.
     *
     * @return list<InvoiceView>
     */
    public function invoices(PortalPrincipal $who): array
    {
        $page = $this->invoices->list(new ListInvoicesCommand($who->projectId, $who->customerRef, null, null, 100), $this->scope($who));

        return array_values(array_filter(
            $page->items,
            static fn (InvoiceView $i): bool => $i->status !== 'draft' && $i->customerId === $who->customerId,
        ));
    }

    public function invoice(PortalPrincipal $who, string $invoiceId): ?PortalInvoiceDetail
    {
        $invoice = $this->ownInvoice($who, $invoiceId);

        if ($invoice === null || $invoice->status === 'draft') {
            return null;
        }

        $payments = $this->invoicePayments->list(new ListInvoicePaymentsCommand($who->projectId, $invoice->id, null, 50), $this->scope($who))->items;
        // A read-only session is offered no rail: the payment it would
        // start is refused by `pay()`.
        $channels = ($invoice->status === 'open' && ! $who->readOnly) ? $this->channels($who) : [];

        return new PortalInvoiceDetail(
            $invoice,
            $payments,
            $channels !== [],
            bin2hex(random_bytes(16)),
            $channels,
        );
    }

    public function entitlements(PortalPrincipal $who): ?EntitlementsView
    {
        $droits = $this->entitlements->get(new GetEntitlementsCommand($who->projectId, $who->customerRef), $this->scope($who));

        if ($droits === null) {
            return null;
        }

        // Un compteur n'apparaît qu'une fois.
        //
        // Les droits sont exposés deux fois quand le projet déclare une
        // capacité (ADR-0074 §5) : sous le nom du plan, et sous le nom de
        // la capacité que consomme l'application cliente. Les deux lignes
        // portent le même compteur. Le propriétaire ne caresse qu'une
        // seule ligne par quota ; l'application cliente, elle, continue de
        // lire la capacité — cette déduplication est celle du portail.
        $vus = [];
        $compteurs = [];

        foreach ($droits->entitlements as $droit) {
            if ($droit->meter !== null) {
                if (in_array($droit->meter, $compteurs, true)) {
                    continue;
                }

                $compteurs[] = $droit->meter;
            }

            $vus[] = $droit;
        }

        return new EntitlementsView(
            $droits->customerRef,
            $droits->active,
            $droits->planCode,
            $droits->subscriptionId,
            $vus,
        );
    }

    /**
     * ADR-0119 §4 — la page « Utilisation » : les droits, et les packs
     * du catalogue.
     *
     * L'offre **n'est pas conditionnée à l'usage** : dès que le projet
     * propose un pack pour un compteur, le pack est dessiné. Le client
     * n'a pas à vérifier lui-même où il en est de son quota pour savoir
     * qu'il peut en acheter — la décision du commanditaire.
     *
     * Deux seules conditions retirent un pack de la page : le contrat
     * n'est pas dans la devise du projet (le Core refuserait l'achat),
     * et un rechargement de ce compteur attend déjà son paiement.
     */
    public function usage(PortalPrincipal $who): PortalUsage
    {
        $entitlements = $this->entitlements($who);
        $groups = [];

        if ($entitlements !== null && $entitlements->active) {
            $catalogue = $this->catalogueOf($who);

            // ADR-0119 §2 — les packs sont tarifés dans la devise du
            // projet : un contrat dans une autre devise ne peut pas les
            // acheter (le Core refuserait), le portail ne les propose donc
            // pas — il n'offre jamais ce qu'il ne peut pas vendre.
            if ($catalogue['currency'] !== $this->currencyOf($who, $entitlements->subscriptionId)) {
                $catalogue = ['items' => [], 'currency' => $catalogue['currency']];
            }

            // Un rechargement déjà en cours (facture ouverte) : le client
            // n'achète pas deux fois le même quota.
            $enAttente = $this->pendingMeters($who, $entitlements->subscriptionId);

            foreach ($entitlements->entitlements as $entitlement) {
                if ($entitlement->type !== 'limit' || $entitlement->meter === null) {
                    continue;
                }

                $limit = $entitlement->limit;
                $used = $entitlement->used ?? 0;

                // Un compteur sans plafond n'a rien à recevoir
                // (ADR-0117 §3 : un supplément y serait refusé).
                if ($limit === null || $limit <= 0) {
                    continue;
                }

                // ADR-0119 §4 — le pack est **proposé** dès que le projet
                // en propose un pour ce compteur : on n'envoie pas le
                // client vérifier lui-même où il en est de son quota.
                // (La décision du commanditaire : l'offre est visible,
                // l'usage ne la conditionne pas.)
                if (in_array($entitlement->meter, $enAttente, true)) {
                    continue;
                }

                $packs = [];

                foreach ($catalogue['items'] as $pack) {
                    if ($pack->meter !== $entitlement->meter) {
                        continue;
                    }

                    // ADR-0121 — le portail n'offre jamais ce qu'il ne peut
                    // pas vendre : un pack à prix nul est refusé par le
                    // Core (le grand livre refuse une ligne à zéro), donc
                    // le bouton « Recharger » échouerait toujours. Un
                    // catalogue déclaré avant la règle ne doit pas
                    // ressusciter une offre morte.
                    if ($pack->totalMinor <= 0) {
                        continue;
                    }

                    $packs[] = new PortalPackOffer(
                        $pack->code,
                        $pack->label,
                        $pack->quantity,
                        $pack->totalMinor,
                        $catalogue['currency'],
                    );
                }

                if ($packs === []) {
                    continue;
                }

                $subject = self::subjectOf($entitlement->key);

                $groups[] = new PortalPackGroup(
                    $subject,
                    self::messageOf($subject, $used, $limit),
                    $limit,
                    $used,
                    $packs,
                );
            }
        }

        return new PortalUsage($entitlements, $groups, bin2hex(random_bytes(16)));
    }

    /**
     * ADR-0119 §3 — le client achète un pack du catalogue.
     *
     * Le client cite le **code** d'un pack : jamais un prix, jamais une
     * quantité. Ce que le pack contient a été déclaré par le projet.
     *
     * La facture produite est une facture comme les autres : elle se
     * règle par le chemin de paiement qui existe déjà (ADR-0075 §5).
     */
    public function buyPack(PortalPrincipal $who, string $packCode, string $nonce): PortalPackPurchase
    {
        $this->assertWritable($who, 'le rechargement');

        if (preg_match('/^[a-f0-9]{16,64}$/', $nonce) !== 1) {
            throw PortalActionRefused::because('Le formulaire a expiré, rechargez la page.');
        }

        $catalogue = $this->catalogueOf($who);
        $pack = null;

        foreach ($catalogue['items'] as $candidate) {
            if ($candidate->code === $packCode) {
                $pack = $candidate;

                break;
            }
        }

        // Le pack a quitté le catalogue entre le clic et l'achat.
        if ($pack === null) {
            throw PortalActionRefused::because('Ce pack n\'est plus disponible. Choisissez-en un autre.');
        }

        $entitlements = $this->entitlements($who);

        if ($entitlements === null || ! $entitlements->active || $entitlements->subscriptionId === null) {
            throw PortalActionRefused::because('Votre abonnement est introuvable.');
        }

        // L'abonnement ne porte plus ce quota : le Core refuserait
        // l'achat, le client ne voit qu'une phrase claire.
        if (! self::carries($entitlements, $pack->meter)) {
            throw PortalActionRefused::because('Votre abonnement ne compte plus ce quota : contactez le support.');
        }

        // Un rechargement qui attend déjà son paiement n'est plus
        // **proposé** (ADR-0119 §4) ; l'achat, lui, reste rejouable : la
        // clé d'idempotence ci-dessous fait qu'un double clic rend le
        // même résultat, au lieu d'être refusé.

        // ADR-0119 §6 — la clé du geste, dérivée du formulaire côté
        // serveur : un double clic rejoue la même clé, donc n'achète
        // qu'une fois.
        $key = 'portal-pack:'.$this->hasher->hash($who->sessionId.'|'.$pack->code.'|'.$nonce, 'pack');

        try {
            $view = $this->purchasePack->purchase(new PurchaseSubscriptionSupplementPackCommand(
                projectId: $who->projectId,
                subscriptionId: $entitlements->subscriptionId,
                packCode: $pack->code,
                idempotencyKey: $key,
                actor: 'portal:'.$who->customerId,
            ));
        } catch (RuntimeException $e) {
            // Les raisons du domaine (« compteur absent du plan », « pack
            // retiré ») ne sont pas pour les yeux du client ; le journal
            // les garde pour l'opérateur.
            $this->logger->warning('portal.buy_pack.refused', ['pack' => $pack->code, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('Le rechargement n\'a pas pu être enregistré. Réessayez ou contactez le support.');
        }

        return new PortalPackPurchase(
            $view->invoice->id,
            $view->invoice->number ?? '',
            $view->invoice->totalMinor,
            $view->invoice->currency,
        );
    }

    /**
     * ADR-0075 §5 — the payment of an invoice, started from the portal.
     *
     * The invoice is resolved **from the session's customer**; the
     * idempotency key is derived from the form's nonce, so a double click
     * is one payment, not two.
     */
    public function pay(PortalPrincipal $who, string $invoiceId, string $channel, string $phone, string $nonce): PortalPaymentOutcome
    {
        $this->assertWritable($who, 'le paiement');

        $invoice = $this->ownInvoice($who, $invoiceId);

        if ($invoice === null || $invoice->status === 'draft') {
            throw PortalActionRefused::because('Cette facture est introuvable.');
        }

        if ($invoice->status !== 'open') {
            throw PortalActionRefused::because('Cette facture n\'a plus rien à régler.');
        }

        if (! in_array($channel, $this->channels($who), true)) {
            throw PortalActionRefused::because('Ce moyen de paiement n\'est pas proposé.');
        }

        $phone = preg_replace('/[\s.\-()]/', '', $phone) ?? '';

        if (preg_match('/^\+?[0-9]{8,15}$/', $phone) !== 1) {
            throw PortalActionRefused::because('Le numéro de téléphone n\'est pas valide.');
        }

        if (preg_match('/^[a-f0-9]{16,64}$/', $nonce) !== 1) {
            throw PortalActionRefused::because('Le formulaire a expiré, rechargez la page.');
        }

        $customer = $this->customers->get(new GetCustomerCommand($who->projectId, $who->customerRef), $this->scope($who));

        try {
            $result = $this->payInvoice->pay(new PayInvoiceCommand(
                projectId: $who->projectId,
                invoiceId: $invoice->id,
                channel: PaymentChannel::fromString($channel),
                idempotencyKey: 'portal-pay:'.$this->hasher->hash($who->sessionId.'|'.$invoice->id.'|'.$nonce, 'pay'),
                customerPhoneNumber: $phone,
                customerName: $customer->displayName ?? null,
                customerEmail: $customer->email ?? null,
                customerLang: $customer->locale ?? $who->branding->locale,
                returnUrl: $this->links->absolute('/portal/invoices/'.$invoice->id),
                actor: 'portal:'.$who->customerId,
            ));
        } catch (RuntimeException $e) {
            // The domain's reasons ("not payable", "no provider") are not
            // for the customer's eyes; the log keeps them for the operator.
            $this->logger->warning('portal.pay.refused', ['invoice_id' => $invoice->id, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('Le paiement n\'a pas pu être lancé. Réessayez ou contactez le support.');
        }

        // The provider's address is shown as a link: only a secure one is.
        $paymentUrl = $result->paymentUrl !== null && str_starts_with($result->paymentUrl, 'https://') ? $result->paymentUrl : null;

        return new PortalPaymentOutcome($invoice->id, $result->payment->status, $result->requiresAction, $paymentUrl, $result->actionHint);
    }

    /**
     * ADR-0075 §3 — the customer stops the contract at the end of the
     * period it has paid for, when the project allows it.
     */
    public function cancelAtPeriodEnd(PortalPrincipal $who, string $subscriptionId): void
    {
        $this->assertWritable($who, 'la résiliation');

        if (! $who->branding->allowSelfCancel) {
            throw PortalActionRefused::because('La résiliation n\'est pas disponible depuis cet espace.');
        }

        $subscription = $this->subscription->get(new GetSubscriptionCommand($who->projectId, $subscriptionId), $this->scope($who));

        if ($subscription === null || $subscription->customerId !== $who->customerId) {
            throw PortalActionRefused::because('Cet abonnement est introuvable.');
        }

        try {
            $this->cancelSubscription->cancel(new CancelSubscriptionCommand(
                projectId: $who->projectId,
                subscriptionId: $subscription->id,
                atPeriodEnd: true,
                reason: 'Résiliation depuis le portail client',
                actor: 'portal:'.$who->customerId,
            ));
        } catch (RuntimeException $e) {
            $this->logger->warning('portal.cancel.refused', ['subscription_id' => $subscription->id, 'reason' => $e->getMessage()]);

            throw PortalActionRefused::because('Cet abonnement ne peut plus être résilié.');
        }
    }

    /**
     * ADR-0119 §1 — le catalogue du projet, et la devise de ses packs.
     *
     * @return array{items: list<SupplementPackView>, currency: string}
     */
    private function catalogueOf(PortalPrincipal $who): array
    {
        $settings = $this->settings->get($who->projectId, $this->scope($who));

        if ($settings === null) {
            return ['items' => [], 'currency' => ''];
        }

        return ['items' => $settings->supplementPacks, 'currency' => $settings->defaultCurrency];
    }

    /**
     * La devise du contrat : celle dans laquelle le pack sera facturé.
     */
    private function currencyOf(PortalPrincipal $who, ?string $subscriptionId): string
    {
        if ($subscriptionId === null) {
            return '';
        }

        $subscription = $this->subscription->get(
            new GetSubscriptionCommand($who->projectId, $subscriptionId),
            $this->scope($who),
        );

        if ($subscription === null) {
            return '';
        }

        return $subscription->currency;
    }

    /**
     * Les compteurs dont un supplément attend encore son paiement.
     *
     * @return list<string>
     */
    private function pendingMeters(PortalPrincipal $who, ?string $subscriptionId): array
    {
        if ($subscriptionId === null) {
            return [];
        }

        $meters = [];

        foreach ($this->supplements->list(
            new ListSubscriptionSupplementsCommand($who->projectId, $subscriptionId),
            $this->scope($who),
        ) as $supplement) {
            if ($supplement->status === 'pending') {
                $meters[] = $supplement->meter;
            }
        }

        return $meters;
    }

    /**
     * Le nom lisible du quota : la clé de la fonctionnalité du plan.
     */
    private static function subjectOf(string $key): string
    {
        return str_replace(['_', '.'], ' ', $key);
    }

    /**
     * La phrase qui dit ce qui se passe — sans un mot de jargon.
     */
    private static function messageOf(string $subject, int $used, int $limit): string
    {
        if ($used >= $limit) {
            return sprintf('Vous avez épuisé vos %s %s.', PortalFormat::count($limit), $subject);
        }

        return sprintf(
            'Il vous reste %s %s sur %s.',
            PortalFormat::count($limit - $used),
            $subject,
            PortalFormat::count($limit),
        );
    }

    /**
     * Le contrat porte-t-il ce quota ?
     */
    private static function carries(EntitlementsView $entitlements, string $meter): bool
    {
        foreach ($entitlements->entitlements as $entitlement) {
            if ($entitlement->type === 'limit' && $entitlement->meter === $meter) {
                return true;
            }
        }

        return false;
    }

    /**
     * ADR-0075 §1 — the refusal of a **read-only** session.
     *
     * The guard lives here, in the code that performs the mutation, and
     * not only in the view that hides the button: a caller who crafts the
     * POST by hand must be refused exactly like the button that is not
     * drawn. The sentence is safe to show — it tells the operator the
     * session is a window, and nothing about the customer's data.
     *
     * @param string $gesture what the gesture would have done, in a phrase
     */
    private function assertWritable(PortalPrincipal $who, string $gesture): void
    {
        if (! $who->readOnly) {
            return;
        }

        throw PortalActionRefused::because('Cette session est en consultation seule : '.$gesture.' n\'est pas possible.');
    }

    /**
     * The channels this customer can really pay with: the ones the
     * platform routes for the project, narrowed by the project's own list
     * when it chose one. A channel nobody serves is never offered — a
     * button that can only fail is worse than no button.
     *
     * @return list<string>
     */
    private function channels(PortalPrincipal $who): array
    {
        $candidates = $who->branding->paymentChannels !== [] ? $who->branding->paymentChannels : self::PLATFORM_CHANNELS;
        $available = [];

        foreach ($candidates as $candidate) {
            try {
                $this->router->resolveProvider(PaymentChannel::fromString($candidate), $who->projectId);
                $available[] = $candidate;
            } catch (NoProviderForChannelException) {
                continue;
            }
        }

        return $available;
    }

    /**
     * @param list<SubscriptionView> $subscriptions
     * @return list<PortalPlanOption>
     */
    private function planOptions(PortalPrincipal $who, array $subscriptions): array
    {
        $current = [];
        foreach ($subscriptions as $subscription) {
            if (! in_array($subscription->status, ['canceled', 'expired'], true)) {
                $current[$subscription->planCode] = true;
            }
        }

        $page = $this->plans->list(new ListPlansCommand($who->projectId, 'active', null, 50), $this->scope($who));
        $options = [];

        foreach ($page->items as $plan) {
            if ($plan->visibility !== 'public') {
                continue;
            }

            $version = null;
            foreach ($plan->versions as $candidate) {
                if ($candidate->status === 'published' && ($version === null || $candidate->version > $version->version)) {
                    $version = $candidate;
                }
            }

            if ($version === null) {
                continue;
            }

            $options[] = new PortalPlanOption(
                $plan->code,
                $plan->name,
                $plan->description,
                $version->priceMinor,
                $version->currency,
                $version->intervalUnit,
                $version->intervalCount,
                self::highlights($version),
                isset($current[$plan->code]),
                $version->trialDays,
            );
        }

        usort($options, static fn (PortalPlanOption $a, PortalPlanOption $b): int => $a->priceMinor <=> $b->priceMinor);

        return $options;
    }

    /**
     * The advantages of a plan, written to be read on a pricing page:
     * « Jusqu'à 100 élèves », « 500 messages whatsapp par mois », a feature
     * that is on, an unlimited one. The names are the project's keys.
     *
     * @return list<string>
     */
    private static function highlights(PlanVersionView $version): array
    {
        $per = PortalFormat::per($version->intervalUnit, $version->intervalCount);
        $lines = [];

        foreach ($version->features as $key => $definition) {
            if (! is_array($definition)) {
                continue;
            }

            $label = mb_strtolower(str_replace(['_', '.'], ' ', $key));

            if (($definition['type'] ?? null) === 'boolean') {
                if (($definition['enabled'] ?? false) === true) {
                    $lines[] = ucfirst($label);
                }

                continue;
            }

            $limit = $definition['limit'] ?? null;

            if (! is_int($limit)) {
                $lines[] = ucfirst($label).' : illimité';

                continue;
            }

            $count = number_format($limit, 0, ',', "\u{202F}");
            $renews = ($definition['reset'] ?? 'period') === 'period';
            $lines[] = $renews ? $count.' '.$label.' '.$per : 'Jusqu\'à '.$count.' '.$label;
        }

        if ($version->trialDays > 0) {
            array_unshift($lines, 'Essai gratuit de '.$version->trialDays.' jours');
        }

        return $lines;
    }

    private static function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return $domain === '' ? '••••' : mb_substr($local, 0, 1).'•••@'.$domain;
    }

    private static function maskPhone(string $phone): string
    {
        return strlen($phone) <= 4 ? '••••' : substr($phone, 0, 4).' ••• ••'.substr($phone, -2);
    }

    private function ownInvoice(PortalPrincipal $who, string $invoiceId): ?InvoiceView
    {
        $invoice = $this->invoice->get(new GetInvoiceCommand($who->projectId, $invoiceId), $this->scope($who));

        return $invoice !== null && $invoice->customerId === $who->customerId ? $invoice : null;
    }

    private function scope(PortalPrincipal $who): AccessScope
    {
        return AccessScope::forProject($who->projectId);
    }
}
