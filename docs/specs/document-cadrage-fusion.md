# Document de cadrage — plateforme de projets SaaS et absorption de la plateforme transitoire

> **Document de transition** (ADR-0068 §6) : il peut nommer les dépôts
> voisins. Il est retiré du core après l'extinction.

- **Date** : 2026-10-24 · **Version** : 2.0 · **Statut** : **ratifié** par le
  comité d'architecture le 2026-10-27
- **Réf.** : ADR-0068 à ADR-0091 (tous **acceptés**), addenda du 2026-10-24
  des spécifications fonctionnelle, technique, du plan de delivery et de la
  gouvernance

## 1. Cible

`vcam-core-system` devient la plateforme qui **gère des projets SaaS de bout
en bout**, pour toute organisation, en trois piliers (ADR-0068) :

| Pilier | Contenu |
|---|---|
| Services de plateforme | identité (organisations, projets, comptes console scopés, clés à portée), paiement, notification, webhook, audit, catalogue de capacités |
| Grand livre | toute somme écrite dans un plan de comptes par projet, exceptions d'argent, rapprochement, versements sortants |
| Commerce SaaS | clients, plans versionnés, abonnements par périodes, factures et encaissement hors ligne, usage et quotas, portail client hébergé |

Plus : une **console de portefeuille** (MRR, encaissé, créances, impayés,
usage, onboarding) et un **modèle de menace** avec exigences testées.

## 2. Périmètre

**Dedans** : les 23 ADR et leurs lots (8 à 19, 130 jours estimés, chemin
critique de 59 jours avant la bascule).

**Dehors** : métier et utilisateurs finaux des applications, cloisonnement de
leurs données internes, comptabilité générale de l'organisation, fournisseur
d'identité des utilisateurs finaux, inscription libre-service des
organisations (liste négative).

**Plus tard, prévu par conception** (ADR-0090) : prorata, remises,
facturation à l'usage, prélèvement récurrent, domaine personnalisé du
portail, OAuth pour les intégrations tierces, facturation des projets par la
plateforme elle-même.

## 3. Ce que devient la plateforme transitoire

Détail : `table-correspondance-fusion.md`. En résumé (ADR-0089) :

| `v_cam-services-center` | Core |
|---|---|
| `applications` (+ clé/secret/scopes/IP) | `projects` + clés à portée |
| `clients` | `customers` (identité minimale conservée en clair — ADR-0123) |
| `abonnements`, `plan_notifications` | `plans` + `plan_versions` |
| `client_abonnement`, `client_plan`, `parametres` | `subscriptions` + `subscription_periods` + `usage_counters` |
| `paiements` (blob), encaissement direct, callback non signé | **retirés** → `payment` + factures + grand livre |
| portail `/app/*` | module `portal` |
| back-office | console `admin` (portefeuille) |
| SSO par jeton à usage unique | sessions de portail créées par le projet |
| SSO JWT (cassé) | **retiré sans remplacement** |
| `ClientSyncService` → `{app_url}/api/clients/sync` (rompu) | **retiré** : le projet déclare ses clients au core |
| proxy de notifications, `NotificationService` (factice), Green API (paquet absent) | **retirés** |

## 4. Les trois mécanismes de la demande initiale

| Demande | Réponse |
|---|---|
| **M1** — le client d'une application paie son abonnement | clients + plans versionnés + abonnements par périodes + factures ; paiement en ligne par le portail ou l'API, hors ligne par la console ; tout au grand livre (ADR-0070 à 0073, 0076) |
| **M2** — quotas et entitlements | journal d'usage + compteur atomique dans la transaction de l'effet ; refus avant l'effet ; droits lisibles ; seuils en événements (ADR-0074) |
| **M3** — SSO | le **client payeur** entre dans le portail par une session créée par le serveur du projet ; le core n'authentifie **pas** les utilisateurs finaux (les candidats d'une auto-école restent authentifiés par l'application) — ADR-0075, ADR-0084 |

## 5. Provisionnement

L'application reste propriétaire de son tenant ; elle **déclare** son client
au core (`PUT /v1/customers/{code}`) et réagit aux webhooks
(`subscription.activated`, `subscription.past_due`, `subscription.expired`,
`usage.threshold_reached`…). La désactivation d'un tenant devient une
conséquence de l'état d'abonnement, appliquée par l'application (ADR-0084).

## 6. Défauts relevés dans les dépôts voisins (signalés, non traités ici)

Application de gestion d'auto-écoles :

- cloisonnement **fail-open** (filtre appliqué seulement si un tenant est
  présent ; colonne de tenant de l'utilisateur nullable) ;
- 44 permissions et 4 rôles déclarés, **aucune** vérification côté API ;
- mot de passe temporaire du premier administrateur renvoyé dans la réponse
  `201` de `POST /api/v1/tenants` ;
- oracle d'existence : `GET /api/v1/tenants/{code}` sur 10 000 codes `AE####` ;
- clé unique partagée (`SAAS_API_KEY`) sans portée ni rotation ;
- `date_expiration` jamais écrite en production, route de désactivation
  appelée par personne ;
- montants en `decimal` sérialisés en `float`, sans devise.

Plateforme transitoire : mot de passe par défaut identique pour tous les
opérateurs ; code 2FA stocké en clair ; callback de paiement non signé.
Ancien produit mobile : vérification TLS désactivée vers l'agrégateur.

## 7. Décisions demandées

1. **Accepter la cible** (ADR-0068) et l'ouverture aux organisations tierces
   (ADR-0069).
2. **Accepter les modifications du graphe de modules** : `portal`, `payout`,
   `notification → billing`.
3. **Accepter l'amendement de gouvernance** sur les données des clients payeurs
   (addendum de `gouvernance-anti-derive.md`, A2).
4. **Confirmer qu'aucune donnée de la plateforme transitoire n'est à
   reprendre** — sinon, reprise partielle des clients et plans (plan de
   bascule §3).
5. **Valider l'ordre des lots** et l'affectation d'une seconde personne au
   lot 9 dès le départ.
6. **Demander aux équipes des applications** de corriger les défauts du §6
   avant leur bascule (le cloisonnement fail-open en priorité).
