# ADR-0045 — Lot 6 (deuxième tranche) : listing générique projects/payments/wallets/notifications

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précise** : plan de delivery §4 Lot 6 ; suite d'ADR-0044

## Contexte

ADR-0044 livrait la fondation opérateur/RBAC et les trois actions citées
par la « sortie » du Lot 6 (rejouer un webhook, consulter l'audit,
réconcilier des paiements), en notant explicitement le listing générique
comme non-but de cette première tranche. L'utilisateur a demandé de
poursuivre avec ce listing : parcourir les projects d'une organisation,
puis les payments/wallets/notifications d'un project — la brique qui
transforme les quatre capacités déjà livrées en une véritable console de
navigation.

## Décisions

### 1. Un port `List*` par module concerné, jamais un module de listing centralisé

Même patron que Lot 6 §1 (ADR-0044) : `admin` compose, il n'interroge pas
directement (ADR-0027). Quatre nouveaux ports :
`Core\Identity\Contracts\ListProjects`,
`Core\Payment\Contracts\ListPayments`,
`Core\Wallet\Contracts\ListWallets`,
`Core\Notification\Contracts\ListNotifications` — chacun dans son module,
chacun avec son propre `Command`/`Page`. Aucun module « Reporting »
transverse n'a été envisagé : ADR-0027 l'a déjà écarté explicitement pour
la raison symétrique (« déplace le problème plutôt que de le résoudre »).

### 2. Réutilisation des read models existants, pas de nouveaux DTOs quand un existe déjà

`ListPayments`/`ListNotifications` renvoient des `list<PaymentView>` /
`list<NotificationView>` — exactement le DTO que `GetPaymentStatus`/
`GetNotificationStatus` renvoient déjà pour un paiement ou une
notification unique. Aucune duplication de forme. Deux DTOs sont
réellement nouveaux, parce qu'aucun équivalent n'existait :
`Core\Wallet\Contracts\WalletSummaryView` (id/partyId/currency/status +
solde — `WalletBalanceView` existant ne porte pas partyId/status,
pertinents seulement en contexte de liste) et
`Core\Identity\Contracts\ProjectView` (rien n'exposait encore un Project
en lecture hors du `ProjectContext` interne à l'auth).

### 3. Filtre de statut : chaîne brute dans les DTOs `Contracts`, conversion dans l'Application

`ListPaymentsCommand::$status`/`ListNotificationsCommand::$status` sont
typés `?string`, pas `?PaymentStatus`/`?NotificationStatus` : ces enums
canoniques vivent top-level, hors de `Contracts/` (même mécanisme que
`OperatorRole`), donc un type `Core\Payment\PaymentStatus` sur un DTO de
`Contracts` le rendrait inutilisable depuis `admin` — G3c interdit à
`admin` de référencer quoi que ce soit de `payment` hors de
`Payment\Contracts`, `PaymentStatus` y compris. `admin` a d'ailleurs
heurté cette règle concrètement en cours de route (tentative initiale de
typer le contrôleur avec `PaymentStatus` directement, rejetée par le
garde-fou G3c). La conversion (`PaymentStatus::tryFrom()`) se fait dans
le use case, module par module ; une valeur non reconnue est traitée
comme « pas de filtre », pas comme une erreur 422 — plus simple, et
cohérent avec le principe qu'un listing reste permissif sur ses filtres.

### 4. Convention de pagination reprise telle quelle depuis ADR-0044 (`AuditQuery`)

`page`/`per_page` en query params (défauts 1/25, plafond 100 imposé côté
contrôleur), enveloppe `{data, meta: {page, per_page, total}}` — quatrième
et cinquième consommateurs de la même convention, sans primitive partagée
dans `core/support` (toujours en dessous de la barre « ≥2 modules »
d'ADR-0020 dans l'absolu, mais le motif se répète maintenant cinq fois ;
si un sixième listing apparaît, l'extraction devient justifiée — pas
avant). Même contournement Larastan que dans ADR-0044 : `toBase()` +
`Model::hydrate()` pour `orderBy`/`skip`/`take`/`count`/`whereIn` chaînés
sur un Eloquent Builder.

### 5. Nouvelles permissions par rôle, alignées sur la spec §8.1

| Permission | Rôles |
| --- | --- |
| `projects:read` | Platform Admin, Finance Operator, Support Operator, Provider Manager, Read-only/Auditor |
| `payments:read` | Platform Admin, Finance Operator, Support Operator |
| `wallets:read` | Platform Admin, Finance Operator |
| `notifications:read` | Platform Admin, Support Operator |

`projects:read` est donné à Provider Manager (a besoin de savoir quels
projects existent pour configurer leur routage provider, même si aucun
endpoint de configuration n'est encore livré) et à Read-only/Auditor
(« lecture seule » couvre par construction tout ce qui est lisible, pas
seulement l'audit). Read-only/Auditor reçoit aussi `payments:read`,
`wallets:read`, `notifications:read` pour la même raison — mais jamais
`payments:reconcile:write` ni `webhooks:replay` (aucune action, seulement
de la lecture).

### 6. Pas de vérification d'existence du project

`GET /v1/admin/projects/{projectId}/payments` sur un `projectId` inconnu
renvoie une liste vide (`meta.total: 0`), pas un 404 — comme n'importe
quel autre listing filtré par une clé qui ne correspond à rien. Ajouter
une vérification d'existence dédiée n'apporterait rien qu'une liste vide
ne dise déjà.

## Alternatives écartées

- **Module `Reporting` centralisé pour tous les listings** — rejeté,
  symétrique à la décision déjà prise dans ADR-0027.
- **Typer les DTOs `Contracts` avec les enums canoniques directement** —
  rejeté, violerait G3c pour tout consommateur cross-module (`admin`
  compris) ; confirmé concrètement par le garde-fou lui-même en cours
  d'implémentation.
- **404 sur un project_id inconnu** — rejeté, une liste vide porte la même
  information sans complexité supplémentaire.

## Conséquences

- Quatre nouveaux endpoints : `GET /v1/admin/projects`,
  `GET /v1/admin/projects/{projectId}/{payments,wallets,notifications}`.
  Tous en lecture seule (GET), donc hors du champ de G7 par construction —
  aucune question de middleware `idempotency` à trancher ici (contrairement
  à ADR-0044 §7, qui concernait des routes `POST`).
- Non-buts encore hors périmètre : règles de frais, administration
  billing, dashboards de métriques/OTel, gestion self-service des
  opérateurs — inchangés depuis ADR-0044.
