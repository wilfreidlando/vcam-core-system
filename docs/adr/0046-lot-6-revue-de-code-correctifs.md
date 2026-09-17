# ADR-0046 — Lot 6 : correctifs issus de la revue de code post-livraison

- **Statut** : Accepté
- **Date** : 2026-09-17
- **Décideurs** : revue multi-agent (architecture, chemin monétaire, contrat API) + comité
- **Dérives concernées** : D5 contrat · D6 intégrité financière

## Contexte

Après la livraison des deux tranches du Lot 6 (ADR-0044, ADR-0045), une revue de
code ciblée sur `2c5ef8f..9dd1684` (trois angles en parallèle : frontières de
module, chemin monétaire, contrat API) a fait remonter 18 constats, dont deux
qualifiés de **bloquants** par leurs propres auteurs. Cet ADR documente les
correctifs appliqués, ceux volontairement différés, et les décisions
structurantes tranchées au passage.

## Décision

### 1. Course entre le webhook entrant et la réconciliation (bloquant, corrigé)

`HandlePaymentStatusChangedUseCase::handle()` lisait le `Payment` via
`findByProviderTransactionRef()` (lecture nue, sans verrou) avant de vérifier
`isTerminal()` et d'écrire. En isolation READ COMMITTED (défaut PostgreSQL), un
webhook entrant et un run de réconciliation concurrents pouvaient tous les deux
lire l'état pré-transition, tous les deux passer la garde `isTerminal()`, et
tous les deux écrire — un cas concret : le webhook écrit `succeeded` et crédite
le wallet, la réconciliation (lecture antérieure) écrit `failed` après, sans
écriture compensatoire.

**Correctif** : nouveau `PaymentRepository::findByProviderTransactionRefForUpdate()`
(`SELECT ... FOR UPDATE`, même mécanisme que `WalletBalanceRepository::lockForUpdate()`
— ADR-0006), utilisé par `HandlePaymentStatusChangedUseCase` à la place de la
lecture nue. Le verrou force la deuxième transaction concurrente à attendre le
commit de la première puis à relire l'état réellement committé — sa garde
`isTerminal()` court-circuite alors correctement.

Non fait dans cette passe : une preuve de concurrence PostgreSQL réelle
multi-processus (mirroring `WalletConcurrencyTest`) — le mécanisme (verrou de
ligne) est identique à celui déjà prouvé pour `wallet`/`ledger`, mais aucun
point d'entrée CLI dédié n'existe pour `payment` permettant de déclencher
`HandlePaymentStatusChangedUseCase` isolément depuis un sous-processus sans
construire un nouveau harnais de test. Suivant tour si un incident réel le
justifie.

### 2. Une exception non-provider dans le handler tuait tout le lot de réconciliation (bloquant, corrigé)

`ReconcileStuckPaymentsUseCase::reconcile()` ne rattrapait que
`ProviderException|CircuitOpenException|UnsupportedPaymentGatewayException`
autour de l'appel à `PaymentStatusChangeHandler::handle()`, qui peut pourtant
échouer autrement (violation de contrainte, `LogicException`,
`WalletNotFound`). Une telle exception échappait à la boucle et interrompait le
lot. Combiné à l'absence de garde de famine sur `findStuckSince()` (tri
`updated_at` croissant, pas de plafond d'âge), un paiement empoisonné restait en
tête de file **indéfiniment** — la réconciliation était morte pour toute la
plateforme.

**Correctif** : ajout d'un second `catch (Throwable $e)` autour du même appel,
journalisé au niveau `error` (distinct du niveau `warning` des échecs provider,
routiniers). Le batch continue toujours après un échec, quel qu'il soit.

Non fait dans cette passe (`C1`, non-bloquant d'après la revue elle-même) : une
borne d'âge supérieure ou un compteur d'abandon sur `findStuckSince()` pour
éviter la famine même sans exception (un paiement qui reste légitimement
`processing` très longtemps). À planifier si l'observabilité montre que ça se
produit en pratique.

### 3. Jetons opérateur sans expiration

`SanctumOperatorTokenIssuer::issue()` appelait `createToken()` sans
`expiresAt`, et `config/sanctum.php` n'avait jamais été publié — le défaut
Sanctum (`'expiration' => null`) s'appliquait : un jeton d'opérateur restait
valide indéfiniment, réduisant la MFA obligatoire (ADR-0044 §3) à une porte
franchie une seule fois.

**Correctif** : `config/sanctum.php` publié avec `'expiration' =>
env('OPERATOR_TOKEN_TTL_MINUTES', 480)` (8h) et `'routes' => false` (aucune
route `/sanctum/csrf-cookie` — cohérent avec « jetons API purs, pas de mode
SPA/cookie »). Le `PruneExpired` planifié de Sanctum a désormais quelque chose
à élaguer.

### 4. Aucune action d'opérateur n'était auditée

`AuditLogger` n'était utilisé nulle part dans `packages/admin/src` — rejouer un
webhook ou déclencher une réconciliation (qui peut créditer un wallet) ne
laissait aucune trace de quel opérateur l'avait fait.

**Correctif** : `ReplayDeadWebhookDeliveryController` et
`TriggerPaymentReconciliationController` enregistrent désormais
`webhook_delivery.replayed` / `payment_reconciliation.triggered` via
`AuditLogger`, acteur `operator:<id>`.

### 5. Aucune limitation de débit sur `/v1/operators/{login,mfa/*}`

Ces quatre endpoints publics ne portaient que le limiteur générique `api`
(300 req/min par IP) — insuffisant pour un plan d'authentification à haute
valeur (accès plateforme).

**Correctif** : nouveau limiteur nommé `operator-auth`
(`AppServiceProvider::configureRateLimiting()`), deux plafonds simultanés —
20/min par IP et 5/min par identifiant (email pour `/login`, jeton de défi pour
les étapes MFA) — appliqué via `throttle:operator-auth` sur ces quatre routes.

### 6. Fuite temporelle sur l'énumération d'email opérateur

`LoginOperator` court-circuitait immédiatement sur un email inconnu, sans jamais
appeler `password_verify()` (coût Argon2id), alors qu'un email connu avec
mauvais mot de passe payait toujours ce coût — un attaquant pouvait distinguer
les deux cas par le temps de réponse.

**Correctif** : `Operator::verifyAgainstDummyHash()` — un hash Argon2id fixe et
précalculé, jamais un vrai mot de passe — est appelé sur le chemin email-inconnu
pour égaliser le temps de réponse.

### 7. Vérification de révocation manquante dans deux des quatre étapes MFA

`LoginOperator` et `VerifyOperatorMfa` vérifiaient `isRevoked()` ; `EnrollOperatorMfa`
et `ConfirmOperatorMfaEnrollment` non — asymétrie sans raison. Impact réel
limité (`ResolveOperatorFromBearerToken` revérifie la révocation à chaque
requête ultérieure, donc un jeton ainsi émis serait immédiatement rejeté à
l'usage), mais l'incohérence produisait une entrée d'audit
`operator.mfa_enrolled` trompeuse pour un compte révoqué.

**Correctif** : la même garde `isRevoked()` ajoutée aux deux étapes manquantes.

### 8. Rejeu de code TOTP possible dans sa fenêtre de validité (~90s)

`Google2faTotpProvider::verify()` appelait `verifyKey()` sans mémoriser le
dernier pas de temps consommé — RFC 6238 §5.2 exige le refus d'un code déjà
vérifié.

**Correctif** : nouvelle colonne `operators.mfa_last_verified_timestep`
(migration `2026_09_17_000001`). `TotpProvider::verify()` gagne un troisième
paramètre `$afterTimestep` et renvoie `int|false` (le pas de temps apparié) au
lieu de `bool` ; l'implémentation utilise `Google2FA::verifyKeyNewer()`, qui
rejette tout pas de temps non strictement postérieur. `Operator::recordMfaVerification()`
persiste le nouveau pas ; `enrollMfa()` le réinitialise à `null`.

### 9. `project_id`/`organization_id`/`deliveryId` malformés provoquaient un 500

Cinq endpoints (`ListProject{Payments,Wallets,Notifications}Controller`,
`ListProjectsController`, `ReplayDeadWebhookDeliveryController`) passaient un
identifiant brut dans un `WHERE ... = ?` contre une colonne Postgres `uuid` sans
validation de forme — une valeur non-UUID levait une `QueryException` non
rattrapée, retombant sur le 500 générique au lieu du 422 propre attendu.

**Correctif** : `Validator::make([...], ['...' => 'uuid'])->validate()` en
tête de chaque contrôleur concerné — réutilise le mapping `ValidationException`
→ RFC 7807 422 déjà en place (`bootstrap/app.php`), pas de nouveau format
d'erreur.

### 10. Pagination non déterministe sur les cinq nouveaux listings + l'audit

Tri sur `created_at`/`updated_at` seul, sans départage : deux lignes au même
horodatage pouvaient apparaître deux fois ou disparaître entre deux pages.

**Correctif** : `->orderBy('id', 'desc')` ajouté en second critère de tri dans
`EloquentAuditQuery`, `EloquentPaymentRepository::findByProject`,
`EloquentWalletRepository::findByProject`,
`EloquentNotificationRepository::findByProject`,
`EloquentProjectRepository::findAll`,
`EloquentWebhookDeliveryRepository::findByStatusPaginated`.

### 11. Permissions RBAC mortes ou incohérentes avec leur propre justification

`payments:reconcile:read` était accordée à Finance Operator sans qu'aucun
endpoint ne la vérifie ; Read-only/Auditor n'avait pas `webhooks:read` malgré
la justification d'ADR-0045 §5 (« la lecture seule couvre par construction tout
ce qui est lisible »).

**Correctif** : `payments:reconcile:read` retirée (aucun usage) ;
`webhooks:read` ajoutée à Read-only/Auditor.

### 12. Le listing wallet masquait une anomalie d'intégrité en zéro silencieux

`ListWalletsUseCase` renvoyait `0`/`0` quand aucune ligne `wallet_balances`
n'existait pour un wallet — exactement la condition que `GetWalletBalanceUseCase`
traite comme une erreur (`WalletNotFound`) pour un wallet unique. Un wallet
authentiquement vide et un wallet dont l'initialisation a échoué devenaient
indiscernables sur la console censée révéler ce genre d'anomalie.

**Correctif** : `ListWalletsUseCase` journalise désormais `wallet.listing.balance_missing`
au niveau `error` quand `WalletBalanceRepository::findMany()` ne renvoie rien
pour un wallet listé, tout en conservant le défaut `0`/`0` dans la réponse pour
ne pas casser le listing. Corrigé dans la même passe : `ListWalletsUseCase`
appelait `find()` une fois par wallet (N+1), et
`EloquentWalletBalanceRepository::toDomain()` refaisait une requête par solde
pour la devise du wallet propriétaire — nouveau
`WalletBalanceRepository::findMany(list<string> $walletIds): array<string, WalletBalance>`,
une seule requête pour les soldes et une seule pour les devises, quelle que
soit la taille de la page.

### 13. Les enums canoniques fuyaient à travers les DTOs `Contracts` malgré G3c

`PaymentView::$status`/`NotificationView::$status` étaient typés avec l'enum
canonique (`PaymentStatus`/`NotificationStatus`) plutôt qu'avec sa valeur —
`admin` dépendait donc de ce type à l'exécution via chaînage de propriété
(`$payment->status->value`) sans jamais le nommer dans son propre code, hors de
portée du scan statique de PHPat qui fait G3c. Incohérent avec le choix déjà
fait pour les DTOs de commande (`ListPaymentsCommand::$status: ?string`, pour
la même raison, ADR-0045 §3).

**Décision** : `PaymentView::$status`/`NotificationView::$status` deviennent
`string` (la valeur de l'enum), pas l'enum lui-même — symétrique au côté
commande. Tous les points de construction (`StartPaymentUseCase`,
`GetPaymentStatusUseCase`, `ListPaymentsUseCase`,
`NotificationSendCascade`, `GetNotificationStatusUseCase`,
`ListNotificationsUseCase`) passent désormais `->value` ; tous les points de
lecture (les contrôleurs HTTP des deux modules et les listings `admin`) lisent
`$view->status` directement.

### 14. Corrections documentaires dans ADR-0044

- §8 affirmait que les quatre capacités « déclenchent ou lisent des opérations
  déjà idempotentes en interne » sans distinction — faux pour le replay
  webhook, qui crée délibérément une nouvelle ligne à chaque appel (ce n'est
  pas un bug ; un replay doit produire une tentative fraîche). L'affirmation
  est retirée, remplacée par une justification G7 précise (aucune mutation
  d'un **montant fourni par l'appelant**).
- Le nom de route `payment-reconciliation-runs` qui évite la sous-chaîne
  `payments` détectée par `IdempotencyTest.php` est noté comme un contournement
  par nommage plutôt qu'une exemption explicite — à régulariser si
  `IdempotencyTest.php` gagne un mécanisme d'exemption nommé.

### 15. Index manquant pour la requête de réconciliation

`findStuckSince()` filtre `status IN ('pending','processing') AND updated_at <
?` puis trie sur `updated_at`, sur une table qui ne fait que croître, toutes les
5 minutes — aucun index ne couvrait cette requête.

**Correctif** : index partiel `payments_reconciliation_candidates_idx ON
payments (updated_at) WHERE status IN ('pending', 'processing')` (migration
`2026_09_17_000001`).

## Alternatives écartées

- **Réécrire `ReplayWebhookDeliveryUseCase` pour être idempotent** (rejette un
  second replay au lieu de créer une nouvelle ligne) — rejeté, un replay doit
  produire une tentative fraîche par conception ; seule l'affirmation d'ADR-0044
  était fausse, pas le comportement.
- **404 au lieu de 422 sur un identifiant malformé** — rejeté, réutiliser le
  mécanisme `ValidationException` existant est plus cohérent avec le reste de
  l'API et ne demande aucun nouveau code de statut à documenter.
- **Ajouter une borne d'âge/famine à `findStuckSince()` dans cette même passe** —
  différé : la revue elle-même le classe non-bloquant, et le corriger sans
  données de production sur la fréquence réelle du phénomène risquerait une
  sur-ingénierie.

## Conséquences

- Nouvelle migration `packages/identity/database/migrations/2026_09_17_000001_add_mfa_last_verified_timestep_to_operators.php`
  et `packages/payment/database/migrations/2026_09_17_000001_add_reconciliation_index_to_payments.php`.
- `TotpProvider::verify()` change de signature (`bool` → `int|false`, nouveau
  paramètre optionnel) — implémentation unique (`Google2faTotpProvider`), pas
  de rupture de contrat public puisque le port n'est pas exposé hors du module.
- `PaymentView`/`NotificationView::$status` changent de type (`PaymentStatus`/
  `NotificationStatus` → `string`) — la forme JSON exposée aux clients HTTP est
  strictement inchangée (c'était déjà `->value` sérialisé), donc aucune rupture
  de contrat externe.
- Non-buts inchangés depuis ADR-0044/0045 : règles de frais, administration
  billing, dashboards de métriques/OTel, gestion self-service des opérateurs.
- Reste à planifier, non bloquant : preuve de concurrence multi-processus pour
  le chemin paiement (point 1), garde de famine sur `findStuckSince()` (point
  2), exemption nommée pour le contournement `IdempotencyTest.php` (point 14).
