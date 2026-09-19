# ADR-0050 — Gestion self-service des opérateurs (create / list / revoke)

- **Statut** : Accepté
- **Date** : 2026-09-19
- **Décideurs** : décision de l'utilisateur (choix explicite après ADR-0046)
- **Dérives concernées** : aucune (capacité déjà en scope ADR-0044, restait
  CLI-only)

## Contexte

ADR-0044 livrait le plan opérateur/RBAC/MFA avec une création d'opérateur
volontairement CLI-only (`identity:create-operator`), notée explicitement
comme non-but de cette tranche : « gestion self-service des opérateurs ».
L'utilisateur a ensuite choisi cette capacité comme prochaine étape. Elle
comble le dernier trou : lister et révoquer un opérateur nécessitaient
jusqu'ici un accès direct à la base ou au tinker.

## Décision

Trois capacités, exposées à la fois en JSON (`packages/admin`) et sur la
console web (`app/Http/Controllers/Admin`), toutes réservées à
`operators:read`/`operators:write` — qui, dans `OperatorRole::permissions()`,
ne sont accordées qu'au wildcard `*` de `PlatformAdmin` :

- **Lister** (`GET /v1/admin/operators`, `GET /admin/operators`) — paginé,
  tri `created_at desc, id desc` dès l'écriture (leçon ADR-0046).
- **Créer** (`POST /v1/admin/operators`, formulaire `POST /admin/operators`)
  — même contrat que `identity:create-operator` : mot de passe en clair
  retourné une seule fois, jamais stocké ni loggé ; le rôle est validé côté
  `Application\UseCase\CreateOperator` (`OperatorRole::tryFrom()` puis
  `InvalidOperatorRole` si invalide), pas chez l'appelant.
- **Révoquer** (`POST /v1/admin/operators/{id}/revoke`, bouton de
  confirmation web) — idempotent : révoquer un opérateur déjà révoqué
  réussit sans effet (`Operator::revoke()` en `??=`), audité une seule fois
  (`RevokeOperator` vérifie `wasAlreadyRevoked` avant d'écrire une seconde
  entrée d'audit).

### Contracts (`packages/identity/src/Contracts/`)

`ListOperators`/`ListOperatorsCommand`/`OperatorPage`/`OperatorSummaryView`,
`CreateOperator`/`CreateOperatorCommand`/`CreatedOperatorView`,
`RevokeOperator`/`RevokeOperatorCommand`. `OperatorSummaryView::$role` est
une **chaîne brute**, jamais l'enum `OperatorRole` — même raisonnement que
`PaymentView`/`NotificationView` (ADR-0046) : l'enum vit hors de
`Contracts/` précisément pour que G3c puisse le restreindre ; le typer dans
un DTO `Contracts` le rendrait inutilisable cross-module sans que le
garde-fou ne le détecte.

`CreateOperator`/`RevokeOperator` documentent `@throws RuntimeException`
(générique, importé) — jamais `InvalidOperatorRole`/`OperatorNotFound`
concrets, qui vivent dans `Domain/Exception/`. Les contrôleurs admin
catchent `RuntimeException`, jamais le type concret : le référencer
directement depuis `admin` serait lui-même une violation G3c (confirmé par
un échec PHPStan pendant l'implémentation).

`Application\UseCase\CreateOperator` a été réécrit pour consommer/retourner
les DTOs `Contracts` au lieu de `Application\Dto\{CreateOperatorCommand,
CreatedOperator}` (supprimés, devenus redondants) ; méthode renommée
`__invoke()` → `create()`, cohérent avec la convention des autres ports.
`identity:create-operator` (CLI) construit désormais
`Contracts\CreateOperatorCommand` et laisse l'use case valider le rôle.

### Console web

`App\Http\Controllers\Admin\OperatorsController` vérifie `hasPermission()`
en tête de **chaque** action (`index`, `create`, `store`, `revoke`) — pas
seulement certaines, l'erreur exacte qu'ADR-0047 corrigeait pour les
contrôleurs préexistants. Vues `resources/views/admin/operators/{index,create}.blade.php`
réutilisant `<x-admin.table>`/`<x-admin.pagination>` (ADR-0047). Le mot de
passe généré est affiché une fois via un flash de session
(`session()->flash('admin.operators.created', ...)`), jamais persisté côté
serveur au-delà de la requête suivante — même mécanisme que
`ReconciliationController` pour son dernier résultat de run.

## Alternatives écartées

- **Retourner le mot de passe dans l'URL de redirection (query string)** —
  rejeté, fuiterait dans les logs d'accès HTTP et l'historique navigateur ;
  le flash de session (cookie signé, un seul lu) est le mécanisme déjà
  établi pour ce type de secret éphémère dans cette console.
- **Permettre à un rôle autre que `PlatformAdmin` de lister les
  opérateurs en lecture seule** — rejeté pour cette tranche : aucun rôle
  n'a aujourd'hui de besoin réel documenté, et ajouter la permission à un
  rôle sans cas d'usage concret est le genre de portée non contractualisée
  que la gouvernance anti-dérive écarte par défaut.

## Conséquences

- Le seul trou restant d'ADR-0044 (« gestion self-service ») est comblé.
- `ModuleGraph::ALLOWED['admin']` n'a nécessité aucune édition (déjà
  pré-approuvé pour `identity`, comme les autres capacités Lot 6).
- Couverture de test : `tests/Integration/AdminHttpFlowTest.php` (JSON —
  create/list/revoke, idempotence de la révocation, rôle invalide → 422,
  permission refusée → 403) et `tests/Feature/Admin/AdminWebFlowTest.php`
  (web — flux complet création → affichage du mot de passe → révocation,
  page interdite pour un rôle sans la permission).
