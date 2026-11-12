# Table de correspondance — plateforme transitoire → core

> **Document de transition** (ADR-0068 §6). Décision : ADR-0089. Modèle cible :
> ADR-0069 à ADR-0076 et addendum de la spécification technique (T2).

- **Date** : 2026-10-24 · **Version** : 2.0 · **Statut** : **ratifié** le 2026-10-27

**Sorts** — *Recoder* : le concept survit sous la forme cible ; *Retirer* : un
mécanisme du core le remplace ou il disparaît ; *Garder* : il reste dans
l'application. **Aucune donnée n'est reprise** sauf décision contraire de la
direction (plan de bascule §3) : cette table sert à **reconstruire** à blanc,
pas à transférer.

## 1. `applications` → `projects` + `api_keys` + `webhook_endpoints`

| Source | Cible | Règle |
|---|---|---|
| `id` | `projects.id` | UUID v7 neuf |
| `name` | `projects.name` | unicité relâchée (homonymes entre organisations) |
| `api_key`, `api_secret` | `api_keys` (préfixe + hash) | **réémis**, jamais convertis ; portées choisies (ADR-0082) |
| `scopes` | `api_keys.scopes` | redéfinis selon les besoins réels ; les anciens n'ont jamais été appliqués |
| `allowed_ips` | `api_keys.allowed_cidrs` | optionnel, par clé |
| `callback_urls` | `webhook_endpoints` | un endpoint par URL, secret propre |
| `app_url` | `portal_settings.allowed_return_urls` | l'origine de l'application devient une URL de retour autorisée |
| — | `projects.organization_id` | organisation propriétaire (ADR-0069) |

## 2. `clients` → `customers`

| Source | Cible | Règle |
|---|---|---|
| `application_id` | `customers.project_id` | |
| `unique_key` | `customers.external_ref` | clé de corrélation ; dans l'application de référence, le code `AE####` |
| `name` | `customers.display_name` | |
| `email` (unique global) | `customers.email` | normalisé ; **unique par projet** (ADR-0123 : conservé en clair) |
| `telephone` (unique global) | `customers.phone` | E.164 (ADR-0123 : conservé en clair) |
| `description`, `logo` | — | retirés (affichage de l'application) |

## 3. `abonnements`, `plan_notifications` → `plans` + `plan_versions`

| Source | Cible | Règle |
|---|---|---|
| `nom` | `plans.name` ; `plans.code` choisi | |
| `prix_abonnement`, `plan_notifications.prix` (`double`) | `plan_versions.price_minor` (`bigint`) + `currency` | conversion en unités mineures (G5) |
| `duree` | `interval_unit` + `interval_count` | |
| `type` (nature du quota), `taille_archive` | `plan_versions.features` | fonctionnalités nommées (`whatsapp_messages`…) ; stockage hors périmètre |
| `is_active` | `plans.status` | |
| `commune_id` | — | retiré |

## 4. `client_abonnement`, `client_plan`, `parametres` → abonnements, périodes, compteurs

| Source | Cible | Règle |
|---|---|---|
| `client_abonnement` (pivot daté) | `subscriptions` + `subscription_periods` | un contrat, des périodes sans chevauchement |
| `client_plan.quota` | limite dans `plan_versions.features` | la limite appartient au plan |
| `parametres.date_fin_abonnement` | `subscription_periods.ends_at` | |
| `parametres.nombre_sms`, `date_fin_notification` | `usage_counters` (fenêtre = période) | décompte par l'usage réel |
| `parametres.quantite_stockage` | — | retiré (stockage hors périmètre) |

## 5. `paiements` → rien (paiements, factures et grand livre du core)

Le blob ne contient ni montant validé ni statut final : **non repris**. Les
nouveaux encaissements passent par factures (ADR-0073), `payment` (ADR-0077)
et le grand livre (ADR-0076).

## 6. `users` → comptes console

| Source | Cible | Règle |
|---|---|---|
| personnes | `operators` + `organization_memberships` | **invitées** (jeton d'activation) ; aucun mot de passe repris |
| `two_factor_*` | — | MFA TOTP obligatoire, enrôlé à l'activation |

## 7. Jetons et notifications

| Source | Sort |
|---|---|
| `one_time_tokens` | retiré → `portal_sessions` (ADR-0075) |
| `refresh_tokens`, `revoked_jtis` | retirés sans remplacement |
| `notifications`, `type_notifications` | retirés → notifications du core |
| `client_notifications` (alertes envoyées) | retiré → événements `subscription.*`, `usage.*` ; l'application notifie |
| `communes`, `regions` | gardés dans les applications |

## 8. Routes et mécanismes

| Source | Sort |
|---|---|
| `POST /api/sso/session-create`, `/sso/auth` | `POST /v1/portal/sessions` |
| `POST /api/sso/token`, `/refresh`, `/revoke` | retirés |
| `POST /api/client/decrement-sms`, `/decrement-storage` | `POST /v1/usage` ; décompte automatique des notifications |
| `GET /coolpay/callback` (non signé) | callback authentifié du core + vérification du montant |
| `POST /api/notifications/send/email` | `POST /v1/notifications/*` |
| `ClientSyncService` → `/api/clients/sync` | retiré ; le projet appelle `PUT /v1/customers/{ref}` |
| portail `/app/*` | module `portal` |
| back-office `/client`, `/application`, `/abonnement`, `/plan-notification` | console `admin` |
