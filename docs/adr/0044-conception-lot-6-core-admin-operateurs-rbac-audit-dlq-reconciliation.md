# ADR-0044 — Conception Lot 6 (première tranche) : opérateurs/RBAC, audit, DLQ webhook, réconciliation paiement

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précise** : spec technique §5.1 (Plan 1 — opérateurs plateforme), spec
  fonctionnelle §3.1 et §8.1 ; plan de delivery §4 Lot 6

## Contexte

Lot 5 (Notification) est livré. Le lot suivant du plan de delivery est
Lot 6 — Core Admin & Réconciliation. Rien n'existait encore :
`packages/admin` était un squelette Lot 0 vide, et `identity` n'avait
aucun concept d'opérateur — seulement Organization/Project/ApiKey (Plan 2,
identité machine). C'est une nouvelle strate d'auth de la taille du Lot 1.

Le périmètre complet du Lot 6 (administration des projets/credentials/
transactions/wallets/règles de frais/providers/webhooks/audit + écrans de
réconciliation + monitoring opérationnel) est trop large pour une seule
tranche, et deux dépendances externes ne sont pas prêtes : le module
`fees` reste en pause (l'utilisateur n'a pas tranché sa pertinence) et
`billing` est un scaffold vide non demandé. Cette tranche couvre donc
exactement les trois actions que le plan de delivery cite comme sortie du
Lot 6 : *« un opérateur pilote la plateforme, rejoue un webhook, consulte
l'audit et réconcilie une journée de transactions »* — plus la fondation
opérateur/RBAC que ces trois actions exigent.

## Décisions

### 1. RBAC : enum PHP fixe, pas le schéma dynamique de la spec

La spec technique §5.1 nomme littéralement quatre tables (`roles`,
`permissions`, `role_permissions`, `operator_roles`). La spec fonctionnelle
§8.1 qualifie elle-même les cinq rôles de « indicatifs ». Choix assumé :
un enum PHP fixe `Core\Identity\OperatorRole` (Platform Admin, Finance
Operator, Support Operator, Provider Manager, Read-only/Auditor), chacun
mappé à une liste fixe de permissions `resource:action` (même convention
que les scopes d'`ApiKey`). Vivant top-level, hors des quatre couches —
même mécanisme que `PaymentStatus`/`NotificationStatus` : Deptrac ne
couvre aucune des quatre couches à cet endroit, donc ni `Contracts` ni
`Domain` ne peut jamais violer G3b en l'important.

Écart assumé (YAGNI) : aucun besoin réel de rôles personnalisables
aujourd'hui ; migrer vers un schéma dynamique reste possible plus tard
sans casser le port `Contracts` qui l'exposerait.

### 2. Sanctum (pas Passport), table `personal_access_tokens` module-owned

Spec technique ADR-10 : Sanctum pour le premier-party/admin, Passport pour
les clients OAuth2 externes — Passport n'a aucun rôle ici. Sanctum tourne
en mode jeton API pur (pas de session/cookie SPA, cette plateforme est
API-only). La migration Sanctum standard n'est pas `vendor:publish`ée
telle quelle : son `morphs('tokenable')` est `unsignedBigInteger`,
incompatible avec l'uuid-partout du dépôt. `identity` possède donc sa
propre copie avec `uuidMorphs('tokenable')`.

`ResolveOperatorMiddleware`/`ResolveOperatorFromBearerToken` sont écrits à
la main (pas le guard `auth:sanctum` natif), miroir exact de
`ResolveProjectMiddleware`/`ResolveProjectFromBearerToken` — cohérence
avec le seul autre plan d'authentification du dépôt plutôt que
d'introduire un deuxième mécanisme.

### 3. MFA via `pragmarx/google2fa`, sans rendu QR serveur

Nouvelle dépendance Composer, bibliothèque PHP pure (RFC 6238). Le Domain
reste pur via un port `TotpProvider` (même patron qu'`ApiKeySecretGenerator`).
Plateforme API-only : aucun rendu d'image QR côté serveur — l'enrôlement
renvoie le secret brut et l'URI `otpauth://`, à charge du futur client
(app authenticator, ou une UI admin qui n'existe pas encore) de générer le
QR. MFA obligatoire dès la première connexion (spec fonctionnelle §3.1 :
« authentification forte (MFA) » fait partie de la définition même du
persona opérateur, pas une option) — le flux de login force
l'enrôlement (`purpose: mfa_enrollment`) tant que `mfa_enabled` est faux.

Flux : `POST /v1/operators/login` (email+mot de passe) → jeton de
challenge chiffré, sans nouvelle table (`LoginChallengeCodec`, encodage
`Crypt::encryptString` d'un payload `{operatorId, purpose, expiresAt}`) →
`POST /v1/operators/mfa/enroll` (première connexion) ou
`POST /v1/operators/mfa/verify` (connexions suivantes) → jeton Sanctum.

### 4. Création d'opérateur : CLI-only ce tour, comme Identity au Lot 1

`identity:create-operator {email} {name} {role}` — miroir exact
d'`IssueApiKeyCommand` (mot de passe aléatoire affiché une fois, jamais
stocké en clair, acteur `service:cli`). Pas d'endpoint HTTP de création
en libre-service cette tranche : la gestion des opérateurs eux-mêmes reste
hors périmètre (voir non-buts), seuls leur authentification et leur usage
des quatre capacités livrées le sont.

### 5. Audit : nouveau port `Contracts\AuditQuery`, pas d'accès direct à la table

`AuditLogger.php` et `packages/audit/README.md` affirmaient (héritage de
l'ébauche Lot 1) qu'« admin lit `audit_log` directement... parce que
c'est le seul endroit autorisé à le faire ». C'était faux et dangereux :
ADR-0027 est justement la décision qui l'interdit (**« Admin compose, il
n'interroge pas directement... jamais d'Eloquent cross-contexte »**), et
le garde-fou G3c (`ModulesMeetOnlyThroughContracts.php`) fait échouer la
CI si un module — `admin` compris, sans exception — référence une classe
d'un autre module hors de son `Contracts/`. Cette tranche corrige donc la
dette documentaire en ajoutant un vrai port `Core\Audit\Contracts\AuditQuery`
(`search()`), implémenté par `EloquentAuditQuery`, et met à jour le
docblock et le README en conséquence.

`EloquentAuditQuery` est la première requête paginée du dépôt entier —
aucune primitive de pagination partagée n'a été introduite dans
`core/support` (un seul consommateur ce tour, sous la barre « ≥2 modules »
qu'ADR-0020 fixe pour le shared kernel) : convention `page`/`per_page` en
query params, enveloppe `{data, meta: {page, per_page, total}}`, à
reconsidérer si un deuxième consommateur apparaît.

**Détail d'implémentation notable** : `orderBy`/`skip`/`take`/`count`/
`whereIn` déclenchent un faux positif Larastan (« dynamic call to static
method ») quand ils sont chaînés directement sur un `Eloquent\Builder` —
première fois que ce dépôt construit une requête au-delà de `where()`
simple. Contournement : construire la requête sur `->toBase()` (le query
builder brut, non affecté), puis `Model::hydrate()` pour restaurer les
casts Eloquent sur les lignes obtenues. Appliqué de façon identique dans
`EloquentAuditQuery`, `EloquentWebhookDeliveryRepository::findByStatusPaginated()`
et `EloquentPaymentRepository::findStuckSince()`.

### 6. DLQ webhook : réutilisation intacte du replay existant + nouveau port de mise en file

`ReplayWebhookDeliveryUseCase`/`Contracts\ReplayWebhookDelivery` (Lot 4,
jusqu'ici CLI-only via `webhook:replay`) sont réutilisés **sans
modification** par le nouveau contrôleur admin. Deux ajouts :
- `Contracts\ListDeadWebhookDeliveries` (+ `WebhookDeliveryRepository::
  findByStatusPaginated()`) pour lister les livraisons `dead`, optionnellement
  filtrées par Project.
- `Contracts\QueueWebhookDelivery` : le replay doit mettre la nouvelle
  livraison en file (`DeliverWebhookJob::dispatch()`), mais ce Job vit
  dans `Core\Webhook\Presentation\Jobs` — `Presentation/` n'est jamais
  franchissable cross-module (G3c). Ce nouveau port, implémenté par
  `QueuedWebhookDeliveryDispatcher` (même module, légal), ferme le trou :
  `admin` l'appelle après `replay()`, et la commande CLI `webhook:replay`
  a été alignée dessus au passage (un seul point de mise en file, deux
  points d'entrée).

### 7. Réconciliation paiement : convergence sur `PaymentStatusChangeHandler` existant

`PaymentStatusChangeHandler`'s propre docblock anticipait exactement ce
besoin depuis le Lot 3 : *« a future checkStatus()-based reconciliation
job (missed webhooks) will call too, so both paths converge on identical,
tested behaviour »*. Nouveau port `Contracts\ReconcileStuckPayments`,
vivant dans `payment` (pas `admin`) car il a besoin de `PaymentRepository`
— Domain, inatteignable cross-module. Pour chaque paiement `pending`/
`processing` dont `updated_at` dépasse le seuil configuré
(`PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES`, défaut 15 — reprend le
libellé de la spec §10 « un paiement resté processing au-delà d'un
seuil ») : résout le gateway par `providerCode`, appelle `checkStatus()`,
et si le statut a changé, construit un `PaymentStatusChanged` et appelle
**`PaymentStatusChangeHandler::handle()` tel quel** — transactionnel,
idempotent, publication outbox comprise, sans aucun `dispatch()`/`event()`
brut ajouté (G8 intact). Une erreur provider sur un paiement est journalisée
et n'interrompt pas le lot.

Deux points d'entrée : `admin:reconcile-stuck-payments` (planifiée toutes
les 5 minutes, lot complet configuré — mirroring `events:relay`) est le
mécanisme principal ; `POST /v1/admin/payment-reconciliation-runs` (lot
réduit à 20, pour rester synchrone/réactif) permet un déclenchement à la
demande.

### 8. Aucune route admin ne porte le middleware `idempotency`

Constat vérifié : `RequireIdempotencyKey`/`IdempotencyScope` est câblé sur
`ProjectContext`, jamais bindée sur une requête authentifiée par
opérateur (`operator.auth`, jamais `project.auth`) — l'attacher
planterait avec un `BindingResolutionException`. Sans conséquence au sens
G7 : aucune des quatre capacités de cette tranche ne mute un **montant
fourni par l'appelant** — la réconciliation lit `checkStatus()` et
converge sur `PaymentStatusChangeHandler` (idempotent au niveau ledger,
ADR-0033 §2, avec verrou de ligne depuis le correctif de revue post-Lot 6
— voir §9 ci-dessous).

**Correction (revue post-Lot 6)** : cette section affirmait à tort que
les quatre capacités « déclenchent ou lisent des opérations déjà
idempotentes en interne » sans distinction. C'est faux pour le replay
webhook : `ReplayWebhookDeliveryUseCase` crée une **nouvelle ligne** de
livraison à chaque appel — rejouer deux fois envoie deux livraisons au
endpoint marchand. Ce n'est délibérément pas un no-op (un replay doit
produire une tentative fraîche), donc ce n'est pas un bug à corriger,
mais l'affirmation d'idempotence était incorrecte et est retirée ici.
Le nom de la route de réconciliation (`payment-reconciliation-runs`)
évite le sous-mot `payments` qu'`IdempotencyTest.php` détecte par
substring — un contournement par nommage plutôt qu'une exemption
explicite documentée dans le garde-fou lui-même ; à régulariser si
`IdempotencyTest.php` gagne un mécanisme d'exemption nommé.

## Alternatives écartées

- **Schéma RBAC dynamique** (roles/permissions/role_permissions/operator_roles,
  libellé littéral de la spec §5.1) — rejeté, YAGNI, aucun besoin réel de
  rôles personnalisés aujourd'hui.
- **Primitive de pagination partagée dans `core/support`** — rejeté, un
  seul consommateur ce tour, sous la barre « ≥2 modules » d'ADR-0020.
- **Rendu d'image QR pour l'enrôlement MFA** — rejeté, plateforme API-only,
  aucune UI à qui servir l'image.
- **`admin` lit `audit_log` directement** (ce que le scaffold Lot 1
  suggérait) — rejeté, contredit G3c et ADR-0027 explicitement.

## Non-buts explicites de cette tranche

Règles de frais (module `fees` en pause), administration billing (scaffold
non construit), endpoints de listing générique pour payments/wallets/
notifications/projects (une vraie console de navigation attendrait un
besoin réel), dashboards de métriques/OpenTelemetry, gestion self-service
des opérateurs (CLI-only ce tour), historique des runs de réconciliation
en lecture (les logs applicatifs et l'audit trail suffisent pour l'instant).

## Conséquences

- `ModuleGraph::ALLOWED['admin']` n'a nécessité aucune édition — déjà
  pré-approuvé pour dépendre de tous les modules concernés. Seul
  `packages/admin/composer.json` gagne les `require` correspondants
  (cosmétique, non bloquant en CI).
- Un futur tour de Lot 6 (fee rules, billing, listing générique,
  monitoring) suit exactement le même patron : port `Contracts` par
  capacité, jamais d'accès direct à une autre couche.
