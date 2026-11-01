<?php

declare(strict_types=1);

namespace App\Http\View;

use Core\Identity\Contracts\ListOperators;
use Core\Identity\Contracts\ListOperatorsCommand;
use Core\Identity\Contracts\OperatorContext;
use Throwable;

/**
 * The words of the audit trail, in one place (recette 2026-10-29, F-08).
 *
 * The audit module records machine identifiers: a resource is `kind:<uuid>` too, and an actor is
 * `operator:<uuid>`, `api_key:<uuid>`, `service:<name>`, `customer:<ref>`; an
 * action is `module.event`. A person reading the console wants « Opérateur —
 * marie@exemple.com » and « Clé API émise ». This class turns the former into
 * the latter for every screen that shows an actor or an action (audit log,
 * dashboard, project exports), so the wording is written once.
 *
 * Operators are resolved through the identity module's `ListOperators` port,
 * read in the account's own scope (`operators:read`): an operator the account
 * may not see keeps a label derived from the prefix (« Opérateur 01a187… »)
 * and never leaks an e-mail across organizations.
 *
 * KNOWN GAP — no port resolves an API key, a project or a customer from a bare
 * identifier (`ListApiKeys` needs the project, which the actor does not carry):
 * those actors get a label derived from their prefix. A `ResolveActorLabels`
 * port in `Core\Identity\Contracts` would close it.
 */
final readonly class AuditLabels
{
    /** The kind of actor (the part before the first colon) → its label. */
    private const KINDS = [
        'operator' => 'Opérateur',
        'api_key' => 'Clé API',
        'service' => 'Service',
        'customer' => 'Client',
        'project' => 'Projet',
        'system' => 'Système',
        'portal' => 'Portail client',
        // Resources named the same way (« payment:<uuid> »).
        'organization' => 'Organisation',
        'export' => 'Export',
        'payment' => 'Paiement',
        'wallet' => 'Portefeuille',
        'webhook_endpoint' => 'Endpoint de webhook',
        'notification' => 'Notification',
        'invoice' => 'Facture',
        'subscription' => 'Abonnement',
        'plan' => 'Offre',
        'channel_session' => 'Session WhatsApp',
    ];

    /**
     * The audit actions the platform writes → their French wording. An action
     * that is not here is shown as it is, in monospace (see `action()`).
     */
    private const ACTIONS = [
        'api_key.issued' => 'Clé API émise',
        'api_key.revoked' => 'Clé API révoquée',
        'api_key.rotated' => 'Clé API renouvelée',
        'api_key.deleted' => 'Clé API supprimée',
        'billing.customer.created' => 'Client créé',
        'billing.customer.erased' => 'Client effacé',
        'billing.customer.reactivated' => 'Client réactivé',
        'billing.customer.suspended' => 'Client suspendu',
        'billing.customer.updated' => 'Client modifié',
        'billing.invoice.created' => 'Facture créée',
        'billing.invoice.issued' => 'Facture émise',
        'billing.invoice.receipt_recorded' => 'Reçu enregistré',
        'billing.invoice.uncollectible' => 'Facture irrécouvrable',
        'billing.invoice.voided' => 'Facture annulée',
        'billing.plan.archived' => 'Offre archivée',
        'billing.plan.created' => 'Offre créée',
        'billing.plan_version.drafted' => 'Version d\'offre rédigée',
        'billing.plan_version.published' => 'Version d\'offre publiée',
        'billing.settings_changed' => 'Réglages de facturation modifiés',
        'billing.subscription.created' => 'Abonnement créé',
        'channel_session.created' => 'Session WhatsApp créée',
        'channel_session.deleted' => 'Session WhatsApp supprimée',
        'customer.suspended' => 'Client suspendu',
        'customer.reactivated' => 'Client réactivé',
        'export.downloaded' => 'Export téléchargé',
        'export.purged' => 'Export purgé',
        'export.requested' => 'Export demandé',
        'operator.created' => 'Opérateur créé',
        'operator.invitation_accepted' => 'Invitation d\'opérateur acceptée',
        'operator.invited' => 'Opérateur invité',
        'operator.login_succeeded' => 'Connexion réussie',
        'operator.logged_in' => 'Connexion réussie',
        'operator.logout' => 'Déconnexion',
        'operator.mfa_enrolled' => 'Double authentification activée',
        'operator.mfa_skipped' => 'Double authentification ignorée',
        'operator.revoked' => 'Opérateur révoqué',
        'operator.updated' => 'Opérateur modifié',
        'organization.created' => 'Organisation créée',
        'organization.invitation_accepted' => 'Invitation à l\'organisation acceptée',
        'organization.member_invited' => 'Membre invité',
        'organization.member_revoked' => 'Membre retiré',
        'organization.member_role_changed' => 'Rôle d\'un membre modifié',
        'organization.updated' => 'Organisation modifiée',
        'payment_reconciliation.single_triggered' => 'Réconciliation d\'un paiement lancée',
        'payment_reconciliation.triggered' => 'Réconciliation lancée',
        'portal.session_opened' => 'Session du portail client ouverte',
        'portal.settings_changed' => 'Réglages du portail client modifiés',
        'project.created' => 'Projet créé',
        'project.updated' => 'Projet modifié',
        'provider_application.deleted' => 'Application opérateur supprimée',
        'webhook_delivery.replayed' => 'Livraison de webhook rejouée',
        'webhook_endpoint.previous_secret_revoked' => 'Ancien secret de webhook révoqué',
        'webhook_endpoint.registered' => 'Endpoint de webhook enregistré',
        'webhook_endpoint.secret_rotated' => 'Secret de webhook renouvelé',
        'webhook_endpoint.updated' => 'Endpoint de webhook modifié',
    ];

    public function __construct(
        private ListOperators $operators,
    ) {}

    /**
     * The French wording of an audit action, or null when the platform has no
     * wording for it — the view then shows the raw action in monospace instead
     * of failing or hiding it.
     */
    public static function action(string $action): ?string
    {
        return self::ACTIONS[$action] ?? null;
    }

    /**
     * Labels for a set of actors, keyed by the raw actor string.
     *
     * @param iterable<string> $actors
     * @return array<string, ActorLabel>
     */
    public function actors(iterable $actors, OperatorContext $operator): array
    {
        $unique = [];
        foreach ($actors as $actor) {
            $unique[$actor] = true;
        }
        $actors = array_keys($unique);

        $emails = [];
        foreach ($actors as $actor) {
            if (str_starts_with($actor, 'operator:')) {
                $emails = $this->operatorNames($operator);
                break;
            }
        }

        $labels = [];
        foreach ($actors as $actor) {
            $labels[$actor] = $this->label($actor, $emails);
        }

        return $labels;
    }

    /**
     * A single actor, with no operator lookup — for a screen that shows one
     * identifier (the export list resolves its whole column through `actors()`).
     *
     * @param array<string, string> $operatorNames operator id → e-mail
     */
    public function label(string $actor, array $operatorNames = []): ActorLabel
    {
        $colon = strpos($actor, ':');
        if ($colon === false || $colon === 0) {
            return new ActorLabel($actor, $actor);
        }

        $kind = substr($actor, 0, $colon);
        $id = substr($actor, $colon + 1);
        $kindLabel = self::KINDS[$kind] ?? null;

        if ($kindLabel === null || $id === '') {
            return new ActorLabel($actor, $actor);
        }

        if ($kind === 'operator' && isset($operatorNames[$id])) {
            return new ActorLabel($kindLabel.' — '.$operatorNames[$id], $actor);
        }

        // « service:e2e » → « Service — e2e » ; an identifier → « Opérateur 01a187… ».
        $short = self::shorten($id);

        return new ActorLabel(
            $short === $id ? $kindLabel.' — '.$short : $kindLabel.' '.$short,
            $actor,
        );
    }

    /**
     * Operator id → « e-mail » (or the name when it has no e-mail), read in the
     * account's own scope. Never throws: a failing lookup only means the
     * operators keep their prefix-derived label.
     *
     * @return array<string, string>
     */
    private function operatorNames(OperatorContext $operator): array
    {
        try {
            $scope = $operator->scopeFor('operators:read');
            if ($scope->isEmpty()) {
                return [];
            }

            $names = [];
            foreach ($this->operators->list(new ListOperatorsCommand(page: 1, perPage: 500), $scope)->items as $item) {
                $names[$item->id] = $item->email !== '' ? $item->email : $item->name;
            }

            return $names;
        } catch (Throwable) {
            return [];
        }
    }

    private static function looksLikeIdentifier(string $id): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $id) === 1;
    }

    /** « 01a1876f-73da-… » → « 01a187… » ; a short reference is kept whole. */
    private static function shorten(string $id): string
    {
        if (self::looksLikeIdentifier($id) || mb_strlen($id) > 24) {
            return mb_substr($id, 0, 6).'…';
        }

        return $id;
    }
}
