# ADR-0052 — Console admin : CRUD organizations/projects, CRUD opérateur complet, self-service des applications provider, prévisualisation de réconciliation, visibilité wallet enrichie

- **Statut** : Accepté
- **Date** : 2026-09-20
- **Décideurs** : décision de l'utilisateur (retour direct après usage réel
  de la console livrée par ADR-0044/0045/0046/0047/0050)
- **Dérives concernées** : aucune nouvelle règle métier ajoutée au Core —
  uniquement des capacités d'administration transverses déjà dans le
  périmètre identity/payment/wallet existant

## Contexte

Après un tour de vérification empirique de la console admin (login réel,
navigation page par page), l'utilisateur a signalé trois manques concrets :
la création d'Organizations/Projects restait CLI-only malgré leur listing
déjà self-service (ADR-0045), le CRUD opérateur n'avait pas d'Update
(seulement Create/List/Revoke, ADR-0050), et la réconciliation se
déclenchait « à l'aveugle » — aucune visibilité sur les paiements qui
seraient effectivement contactés avant l'appel réel au provider. Il a aussi
qualifié la sidebar de la console (liste plate de 6 liens) et le dashboard
(3 cartes de stats) d'insuffisants pour ce qui se présente comme un
mini-cœur bancaire.

Une question de gouvernance explicite s'est posée en cours de route :
l'utilisateur a demandé la « gestion des wallets » dans l'admin. Deux
décisions déjà actées s'y opposaient frontalement : ADR-0033 §1 (aucun
endpoint HTTP ne mute un wallet) et ADR-0037 (le crédit d'un wallet n'est
jamais une action manuelle, seulement une conséquence explicite d'un
paiement). Posée à l'utilisateur via une question à choix fermé, la
réponse a été : garder ADR-0033/0037 intacts, enrichir uniquement la
**visibilité** (lecture) d'un wallet, aucune mutation depuis la console.

## Décision

### 1. Organizations/Projects — création self-service

Nouveaux ports `Contracts\CreateOrganization`/`CreateOrganizationCommand` et
`Contracts\CreateProject`/`CreateProjectCommand` dans `identity`, méthode
`create()` (convention déjà établie par `CreateOperator`, ADR-0050) —
remplacent les anciens DTOs `Application\Dto\{CreateOrganizationCommand,
CreateProjectCommand}` consommés uniquement par la CLI jusqu'ici.
`identity:create-organization`/`identity:create-project` convergent
maintenant sur les mêmes ports que la console — plus de logique dupliquée
entre CLI et web, même pattern que `CreateOperator`.

Exposé en JSON (`POST /v1/admin/organizations`, `POST /v1/admin/projects`,
permission `projects:write` — couverte uniquement par le wildcard `*` de
`PlatformAdmin`, aucun autre rôle n'en a le besoin documenté) et sur la
console web (`OrganizationsController`/`ProjectsController` gagnent
`create()`/`store()`, formulaires `admin/{organizations,projects}/create.blade.php`,
le second avec un `<select>` d'organisation pré-remplissable via
`?organization_id=`).

### 2. CRUD opérateur — Update

Nouveaux `Contracts\UpdateOperator`/`UpdateOperatorCommand` (email, name,
role — role en chaîne brute, même raison que `CreateOperatorCommand`,
ADR-0050). `Domain\Model\Operator` perd son `readonly` sur `email`/`name`/
`role` (les trois seules propriétés publiques désormais mutables — un choix
délibéré de moindre surface de changement plutôt qu'une conversion complète
en privé+accesseurs, qui aurait touché tous les appelants existants lisant
ces propriétés directement) et gagne `updateProfile()`, mutation en place
comme `revoke()`. Exposé en JSON (`PUT /v1/admin/operators/{id}`,
`operators:write`) et web (`OperatorsController::edit()`/`update()`, vue
`admin/operators/edit.blade.php`) — préremplissage par scan de
`ListOperators` (pas de port de lecture unitaire existant, jugé hors
périmètre d'en ajouter un dans cette passe).

### 3. Applications provider — enregistrement self-service, contrainte de graphe de modules

Nouveaux `Contracts\RegisterProviderApplication`/`RegisterProviderApplicationCommand`
dans `payment`, consommés par la CLI existante
(`payment:register-provider-application`, gagne une validation d'existence
du Project) et par un nouvel endpoint admin (`POST
/v1/admin/payment-provider-applications`, permission
`provider-applications:write` — nouvelle, ajoutée au rôle `ProviderManager`
qui n'avait jusqu'ici aucune permission d'écriture réelle malgré son nom).

**Point de conception significatif** : `tests/Architecture/ModuleGraph.php`
n'autorise pas `payment` à dépendre de `audit` (contrairement à `identity`,
qui l'a explicitement). Le brief initial de cette tâche demandait au use
case d'auditer directement (calqué sur `CreateOperator`) — erreur corrigée
en cours de route. `RegisterProviderApplication::register()` ne prend donc
ni `actor` ni `correlationId` et n'audite rien lui-même ; c'est le
contrôleur admin (`Core\Admin\*`, qui A `audit` dans son graphe autorisé)
qui enregistre l'entrée d'audit (`provider_application.registered`,
`public_key` inclus, **`private_key` jamais loggé**) après l'appel au port
— exactement le même renoncement déjà documenté pour `webhook`/`payment`
dans `app/Http/Controllers/Admin/{WebhookDeliveriesController,
ReconciliationController}.php`. Modifier `ModuleGraph.php` pour donner
`audit` à `payment` aurait résolu le symptôme mais nécessite lui-même un
ADR + comité d'architecture (CLAUDE.md) — non engagé ici, la solution
retenue ne le nécessite pas.

### 4. Réconciliation — prévisualisation avant déclenchement

Nouveaux `Contracts\ListStuckPayments`/`ListStuckPaymentsCommand`/
`StuckPaymentView` dans `payment` — lecture pure de
`PaymentRepository::findStuckSince()` (déjà utilisé par
`ReconcileStuckPaymentsUseCase`/`CountStuckPaymentsUseCase`, jamais exposé
en liste avant), même plafond `ON_DEMAND_BATCH_LIMIT` que le déclenchement
réel. `ReconciliationController::new()` affiche désormais la liste des
paiements qui seraient effectivement contactés avant que l'opérateur ne
clique sur le bouton qui appelle réellement le provider — le bouton reste
désactivé quand la liste est vide.

### 5. Wallets — visibilité enrichie, aucune mutation (décision explicite ci-dessus)

`WalletsController::index()` (nouveau, `wallets:read`) — liste en lecture
seule des wallets d'un Project (`Core\Wallet\Contracts\ListWallets`,
port déjà existant, jamais eu de web UI). Aucun port de mutation ajouté,
aucun formulaire de crédit/débit. Pas de détail par écriture de ledger : le
port de lecture correspondant n'existe pas encore dans
`packages/ledger/src/Contracts/` (seuls des ports d'écriture/résolution de
compte y vivent) — ajouté au périmètre d'une future passe si demandé,
volontairement pas construit ici pour ne pas engager un nouveau port de
lecture cross-module sans décision dédiée.

### 6. Console web — deux bugs de layout corrigés, sidebar restructurée, dashboard enrichi

Deux bugs réels trouvés par vérification live (pas des choix de design) :
la sidebar de navigation était **entièrement vide pour tout opérateur, quel
que soit son rôle** — `$has()` lisait `$operator->permissions ?? []`,
propriété inexistante sur `OperatorContext` (seule `hasPermission()`
existe) ; et le conteneur racine (`min-h-screen md:min-h-0`) perdait toute
contrainte de hauteur sur desktop, la coquille entière (sidebar comprise)
s'affaissant/s'étirant selon le contenu de chaque page au lieu de rester
fixée au viewport. Les deux corrigés (`$has` délègue à `hasPermission()`,
shell en `h-screen overflow-hidden` avec seul `<main>` scrollable).

Sidebar restructurée en groupes (Overview/Identity/Money/Platform, chacun
masqué si aucun lien du groupe n'est permis), avec badges de compteur
best-effort (paiements bloqués, webhooks morts — même dégradation
défensive `try/catch(Throwable)` que le reste des stats). Dashboard gagne
trois KPI supplémentaires (organizations/projects/operators, `perPage: 1`
→ `->total`, même trick déjà utilisé pour les webhooks morts) et une
hiérarchie visuelle par groupes plutôt qu'une liste plate de cartes.

## Alternatives écartées

- **Ajouter `audit` au graphe de dépendances de `payment`** pour permettre
  un audit direct dans `RegisterProviderApplication` — rejeté : demande un
  ADR + comité d'architecture dédié (CLAUDE.md), alors que le pattern
  « contrôleur admin audite pour un module qui ne peut pas » est déjà établi
  et suffisant.
- **Bouton créditer/débiter un wallet dans l'admin** — rejeté explicitement
  par l'utilisateur après qu'on lui a posé la question : casserait
  ADR-0033 §1 et ADR-0037 sans qu'aucune nécessité opérationnelle documentée
  ne le justifie pour cette passe.
- **Convertir `Operator::email/name/role` en privé + accesseurs** pour une
  cohérence totale avec `passwordHash` — écarté pour cette passe au profit
  d'un simple retrait du `readonly`, qui évite de toucher tous les
  appelants existants lisant ces propriétés directement ; noté comme
  incohérence mineure à reconsidérer si `Operator` gagne d'autres mutations
  futures.

## Conséquences

- Plus aucune capacité admin listée dans ADR-0044/0045/0050 comme
  « CLI-only » ou « lecture seule sans écriture correspondante » ne le
  reste sans raison documentée, à l'exception explicite et volontaire des
  wallets (mutation refusée par design, pas par oubli).
- `ModuleGraph::ALLOWED` n'a subi aucune édition — chaque nouvelle capacité
  est restée dans le graphe existant, y compris la contrainte
  audit-indisponible-pour-`payment`, gérée au niveau du contrôleur admin
  plutôt qu'en élargissant le graphe.
- Couverture : suite complète à 546 tests (was 523 avant ce tour), 0
  violation Deptrac (modules + layers), 14/14 garde-fous, vérification live
  réelle (curl, cookie de session, MFA réel) pour chaque nouvelle page et
  au moins un flux de création complet round-trippé en base.
