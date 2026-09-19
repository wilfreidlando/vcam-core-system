# ADR-0047 — Console web admin : correctifs et mécanisme d'authentification

- **Statut** : Accepté
- **Date** : 2026-09-19
- **Décideurs** : revue directe (login réel via curl, deux agents d'investigation en lecture seule) + comité
- **Dérives concernées** : D5 contrat · D6 intégrité financière · D7 organisationnelle

## Contexte

Une console web admin (`/admin/*`, Blade, session/cookie) a été livrée hors de
cette session par-dessus l'API JSON `/v1/admin/*` déjà existante (Lot 6,
ADR-0044/0045). L'utilisateur a demandé de pouvoir la tester, et de vérifier
que les meilleures décisions avaient été prises — c'est un projet de cœur
bancaire à envergure internationale.

Un test empirique direct (création d'un opérateur, login réel, flux MFA
complet, puis requête sur chaque page authentifiée) a montré que **toute page
au-delà du formulaire de login retournait un 500**. Deux agents d'investigation
en lecture seule ont ensuite confirmé l'ampleur exacte : la console était
non-fonctionnelle au-delà du login, avec un bug de sécurité sérieux
(déclenchement caché d'une réconciliation financière réelle sur un simple
`GET`), et les deux garde-fous qui auraient dû l'intercepter (PHPStan, tests)
étaient soit exclus soit jamais exécutés sur exactement ce code.

## Décision

### 1. Mécanisme d'authentification web : session serveur, pas cookies Sanctum

**Rejeté** : le mode « stateful » de Sanctum. Structurellement impossible —
Sanctum ne réexpose jamais le plaintext d'un token après son émission (seul
le hash SHA-256 est persisté), donc « relire le bearer token depuis un cookie
de session résolu » n'a pas de solution côté Sanctum, quel que soit le
correctif ponctuel appliqué. Confirmé par quatre échecs indépendants dans le
code livré : `Sanctum::currentAccessToken()` appelée comme méthode statique
(elle n'existe qu'en instance, trait `HasApiTokens`) ; le guard `web` pointait
vers `App\Models\User`, jamais `Operator` ; `EnsureFrontendRequestsAreStateful`
n'était enregistré nulle part ; le format du cookie était inversé
(`plaintext|id` au lieu de `id|plaintext`).

**Retenu** : le bearer token en clair est stocké dans la session serveur
Laravel (chiffrée, httponly, jamais transmise au client) juste après un
login/MFA réussi (`AuthController::completeLogin()`), et
`ResolveOperatorSession` le relit pour appeler **exactement** le même
`ResolveOperatorFromBearerToken` que le plan API bearer — zéro logique
d'authentification dupliquée, l'objectif que la conception originale visait
sans l'atteindre. `config/sanctum.php` revient à sa forme ADR-0046
(`'routes' => false`) : le CSRF web reste géré par la session Laravel
standard, indépendamment de Sanctum.

### 2. Chaque page authentifiée vérifie sa permission

Aucun contrôleur web sauf le dashboard ne vérifiait `OperatorContext->hasPermission(...)`
— n'importe quel opérateur authentifié, quel que soit son rôle, pouvait
déclencher n'importe quelle action (rejouer un webhook, lancer une
réconciliation), alors que l'API JSON équivalente vérifie correctement chaque
permission. Chaque action de chaque contrôleur web vérifie désormais la même
permission que son équivalent JSON.

### 3. Le dashboard ne déclenche plus jamais une réconciliation réelle

`DashboardController::stuckPaymentsCount()` appelait
`ReconcileStuckPayments->reconcile(...)` pour peupler une statistique — un
appel réseau réel au provider de paiement et une mutation d'état possible
(crédit wallet, notification) à chaque chargement de page, par n'importe quel
opérateur, sans vérification de permission. Nouveau port
`Core\Payment\Contracts\CountStuckPayments` (lecture pure, `COUNT(*)`) —
plus aucun appel provider derrière un `GET`. Nouveau port
`Core\Payment\Contracts\PaymentActivitySummary` (comptage global cross-projet
sur une fenêtre — le seul cas où le dashboard a besoin d'une vue non
scopée-projet, contrairement à `ListPayments`, ADR-0045).

### 4. Résolution des composants Blade

`<x-admin.table>`/`<x-admin.stat>`/`<x-admin.pagination>` ne résolvaient vers
rien : aucune des deux méthodes d'enregistrement Blade
(`anonymousComponentPath`/`anonymousComponentNamespace`) ne supporte en
réalité la syntaxe à points (`<x-admin.table>`) — les deux exigent la syntaxe
`<x-admin::table>`. Le seul mécanisme qui supporte nativement la syntaxe à
points sans aucun enregistrement est l'emplacement par défaut de Blade,
`resources/views/components/`. Les trois fichiers sont déplacés vers
`resources/views/components/admin/` ; aucun enregistrement custom n'est plus
nécessaire. `table.blade.php` avait par ailleurs un second bug indépendant :
il itérait une prop `$rows` jamais passée au lieu de rendre `{{ $slot }}` —
chaque page appelante passe ses lignes en contenu de slot, que le composant
ignorait silencieusement.

### 5. Accès objet, pas tableau, sur les DTOs `Contracts`

`payments/index.blade.php`, `webhook-deliveries/dead.blade.php`,
`dashboard.blade.php` et `audit-log/index.blade.php` accédaient à
`$item['champ']` alors que les contrôleurs passent des objets `PaymentView`/
`DeadWebhookDeliveryView`/`AuditEntryView` (propriétés publiques readonly) —
une erreur fatale PHP dès qu'une ligne réelle existait (invisible en test
manuel tant que la base était vide). Toutes les vues concernées accèdent
maintenant via `->propriete`.

### 6. Détail projet et paiement réellement implémentés

`ProjectsController::show()` était un stub retournant toujours `null` → 404
systématique, et les vues `projects/{index,show}.blade.php` n'existaient pas
sur le disque. Nouveau port `Core\Identity\Contracts\GetProject` (lookup par
id, indépendant de l'organisation). `PaymentsController::show()` redirigeait
toujours avec une erreur ; utilise maintenant le port `GetPaymentStatus`
existant (nécessite `project_id`, cohérent avec le reste de l'API paiement).
`OrganizationsController` dé-dupliquait des organisations à l'intérieur d'une
seule page de `ListProjects`, rapportant un total et une pagination faux dès
que le nombre réel d'organisations dépassait une page de projets — nouveau
port `Core\Identity\Contracts\ListOrganizations`, pagination correcte côté
base.

### 7. Négociation de contenu sur les échecs de validation

Le handler d'exception global retournait du `application/problem+json` pour
**toute** `ValidationException`, y compris sur le plan web — chaque échec de
validation sur un formulaire (login, MFA, réconciliation) affichait un JSON
brut au lieu de rediriger vers le formulaire avec les erreurs ; tous les blocs
`@error()` des vues étaient du code mort pour ce cas. Le handler distingue
maintenant `$request->expectsJson()` : web → comportement Laravel par défaut
(redirect + `$errors` flashés) ; API → RFC 7807 inchangé.

### 8. Throttle sur le plan web, plafond de réconciliation aligné

`POST /admin/login`/`mfa/*` ne portaient aucun throttle, contrairement à
l'équivalent API (`throttle:operator-auth`). Même limiteur appliqué ici.
`ReconciliationController::run()` validait `limit_override` jusqu'à 10 000
alors que le formulaire annonçait un plafond de 20 — un opérateur pouvait
déclencher jusqu'à 10 000 appels provider synchrones en une requête. Plafond
aligné sur `TriggerPaymentReconciliationController::ON_DEMAND_BATCH_LIMIT`
(20) ; le champ, qui contrôle un nombre de lignes et non un seuil d'âge, est
réétiqueté en conséquence.

### 9. Retrait de l'exclusion PHPStan globale

`phpstan.neon.dist` excluait `app/Http/Controllers/Admin` et
`ResolveOperatorSession.php` en bloc, justifié par « les tests d'intégration
couvrent le runtime » — faux (`tests/Feature/Admin/` était vide, et de toute
façon **jamais enregistré comme testsuite** dans `phpunit.xml`, donc rien n'y
aurait tourné même rempli). Cette exclusion cachait précisément les deux
fichiers portant les bugs les plus graves de cette revue (l'appel de méthode
statique inexistante, un type de retour non résolvable). Exclusion retirée ;
les vraies limites d'inférence Laravel (`Request::validate()`,
`Session::flash()`) sont ignorées ligne par ligne, pas fichier par fichier.
`tests/Feature` est maintenant un testsuite réel (`phpunit.xml`), avec
`tests/Feature/Admin/AdminWebFlowTest.php` couvrant le flux complet
login→MFA→chaque page, un test de permission refusée, et un test de
validation UUID côté web.

## Alternatives écartées

- **Authentification Laravel native** (`Auth::guard('web')->login($operator)`,
  `OperatorRecord implements Authenticatable`) — rejeté : plus idiomatique
  Laravel dans l'absolu, mais crée un second mécanisme d'authentification
  parallèle à `ResolveOperatorFromBearerToken`, contrairement à l'objectif
  affiché du design original (« zéro duplication de logique d'auth »). La
  session-stockant-le-plaintext atteint cet objectif en réutilisant le code
  existant tel quel.
- **Reconstruire entièrement la console plutôt que corriger** — rejeté : les
  bugs, une fois identifiés, sont chacun des correctifs ciblés et cohérents
  avec les conventions déjà établies (ADR-0044/0045/0046) ; aucun ne
  justifiait une réécriture.

## Conséquences

- Dette explicitement non traitée dans cette passe : `AuthController`/
  `ResolveOperatorSession` importent `Core\Identity\Application\UseCase\*`
  directement plutôt que `Core\Identity\Contracts\*` — contraire au principe
  d'ADR-0027, mais le garde-fou G3c ne scanne que `Core\<Module>\*`, jamais
  `App\*`, donc rien ne le détecte aujourd'hui. Cause racine réelle :
  `identity` ne publie aucun port `Contracts` pour login/MFA/logout/whoami.
  Combler ce trou (nouveau port + garde-fou étendu à `App\*`) reste un
  chantier séparé, plus large que cette passe corrective.
- Nouveaux ports `Contracts` : `Core\Payment\Contracts\{CountStuckPayments,
  PaymentActivitySummary}`, `Core\Identity\Contracts\{GetProject,
  ListOrganizations}` — tous des lectures pures, cohérents avec le
  patron « un port par capacité » déjà établi (ADR-0045).
- Vérification complète : Pint (709 fichiers), PHPStan (683 fichiers, plus
  aucune exclusion pour la console admin), les deux graphes Deptrac,
  14/14 garde-fous, 478 tests (dont 3 nouveaux dans le testsuite `Feature`
  désormais réellement exécuté), vérification manuelle réelle (login → MFA →
  chaque page → 200 avec contenu réel ; opérateur sans permission → 403 ;
  logout → session invalidée).
