# ADR-0033 — Conception du Lot 2 (Money Core) : surface HTTP, idempotence à deux niveaux, chart of accounts

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Dérives concernées** : D2, D6

## Contexte

Le Lot 2 démarre avec l'ambition explicite de servir de fondation « mini core
banking » — pas seulement satisfaire la sortie minimale du plan de delivery
(« créditer/débiter un wallet de façon cohérente et idempotente »). Plusieurs
questions de conception se posent avant d'écrire le code, dans l'esprit de
« le comité valide le modèle avant le code » (plan de delivery §4, Lot 2).

## Décisions

### 1. Aucun endpoint HTTP public de mutation de wallet au Lot 2

Un `POST /v1/wallets/{id}/credit` public, appelable par n'importe quel
credential de Project authentifié, permettrait à un projet de créer de la
valeur dans son propre wallet à volonté — exactement le risque de fraude que
les garde-fous existent pour empêcher. Dans un système réel, un crédit/débit
de wallet est **toujours** la conséquence d'une opération métier autorisée
ailleurs (un paiement confirmé par un provider, un versement approuvé) — pas
un appel API générique.

**Décision** : `Wallet` expose ses mutations (`CreditWallet`, `DebitWallet`,
`HoldFunds`, `CaptureHold`, `ReleaseHold`) comme des **cas d'usage
Application**, consommés par appel PHP direct depuis un autre module
(`Payment` au Lot 3) ou depuis des commandes artisan d'exploitation
(`wallet:credit`, `wallet:debit`, dans l'esprit des commandes de bootstrap du
Lot 1). Seule une route HTTP **en lecture seule**,
`GET /v1/wallets/{id}/balance`, existe au Lot 2 — protégée par
`ResolveProjectMiddleware` (via l'alias `project.auth`, voir décision 3) et le
scope `wallets:read`. C'est elle qui joue le rôle de smoke test « hello
capability » pour ce lot, comme `GET /v1/whoami` pour le Lot 1.

Qui a le droit d'appeler ces cas d'usage (autorisation applicative) sera
tranché au Lot 3, quand `Payment` deviendra le premier appelant réel — ne pas
concevoir cette autorisation par anticipation (porte n°1 de la gouvernance).

### 2. Idempotence à deux niveaux, pas un seul mécanisme

[ADR-0025](0025-design-idempotence.md) fige un store HTTP générique
(`Core\Support\Idempotency`, avec mise en cache de la réponse complète). Ce
mécanisme suppose un **appelant HTTP externe** rejouant une requête — or,
avec la décision 1, `Wallet` n'a pas de route HTTP de mutation au Lot 2. Le
premier appelant HTTP réel d'une mutation monétaire sera `Payment` (Lot 3).

**Décision** : deux mécanismes distincts, à deux niveaux :

- **Niveau ledger (maintenant, Lot 2)** — `ledger_transactions.idempotency_key`
  (spécification technique §6.4, `UNIQUE (project_id, idempotency_key)`).
  Avant d'insérer des écritures, `PostLedgerTransaction` vérifie si une
  transaction porte déjà cette clé pour ce projet ; si oui, elle retourne la
  transaction existante sans ré-exécuter. C'est un mécanisme applicatif
  simple (une requête + une contrainte unique), pas un port `Core\Support`
  séparé — il vit dans le module `ledger` lui-même. C'est ce qui satisfait
  « rejeu d'une opération identique sans double effet » (Definition of Done
  Money, plan de delivery §9) dès ce lot.
- **Niveau HTTP (différé au Lot 3)** — le store générique d'ADR-0025, mis en
  œuvre quand `Payment` expose son premier endpoint de mutation. ADR-0025 est
  amendé sur ce seul point : « implémentation Lot 2 » devient « implémentation
  Lot 3 ».
- `docs/architecture/garde-fous-inactifs.md` : **G7** (`Idempotency-Key` HTTP
  obligatoire) reste `pending-code`, activé par **Lot 3** (premier endpoint
  de mutation Payment) et non plus « Lot 2 — première route de mutation de
  wallet », puisque cette route n'existe pas.

### 3. `ResolveProjectMiddleware` devient un alias de middleware global

Le Lot 1 a construit `ResolveProjectMiddleware` à l'intérieur de la couche
`Presentation` d'`Identity`, utilisé uniquement par les routes d'Identity.
`Wallet` en a besoin pour sa route de lecture de solde — mais référencer
`Core\Identity\Presentation\Http\Middleware\ResolveProjectMiddleware`
directement depuis `Wallet` violerait **G3c** (un module n'atteint un autre
module que par son `Contracts/`).

**Décision** : la classe reste dans `Identity\Presentation` (elle appartient
à ce module), mais `bootstrap/app.php` l'enregistre sous l'**alias**
`project.auth` (`$middleware->alias(['project.auth' => ...])`). Tout module,
y compris `Wallet`, référence l'alias par sa chaîne (`Route::middleware('project.auth')`),
jamais la classe — aucune dépendance de code n'apparaît, donc aucune entrée
de graphe supplémentaire n'est nécessaire pour ce seul usage.

En revanche, un contrôleur qui a besoin de lire des informations du contexte
authentifié (`$project->hasScope('wallets:read')`, `$project->projectId`)
type-hint `Core\Identity\Contracts\ProjectContext` — c'est un accès légitime
au `Contracts/` d'Identity, mais cela crée une dépendance réelle de `Wallet`
vers `Identity`. **`identity` est donc ajouté aux dépendances autorisées de
`wallet`** dans `tests/Architecture/ModuleGraph.php` et `deptrac-modules.yaml`
(même schéma qu'ADR-0029 pour `identity`→`audit`).

### 4. Chart of accounts extensible, pas un enum fermé

`ledger_accounts.kind` (spécification technique §6.4) doit accueillir
`wallet` au Lot 2, puis `platform_fee`, `provider_fee`, `clearing` aux lots
suivants (Fees, Payment) — sans jamais modifier le code du module `ledger`,
qui doit rester la partie la plus stable du système une fois validée.

**Décision** : `AccountKind` est une value object qui **valide la forme**
d'un identifiant de nature de compte (non vide, minuscules,
underscore-séparé) plutôt qu'un enum PHP fermé listant des valeurs connues
à l'avance. Un futur module mint son propre `AccountKind::from('platform_fee')`
sans toucher à `packages/ledger`. C'est délibérément différent du choix fait
pour les statuts canoniques de paiement/notification (G6b, enum fermé) : ces
derniers sont un **contrat public** consommé par les applications
extérieures, quand `kind` est un **détail interne** du grand livre, jamais
exposé tel quel hors du Core.

### 5. Aucune contrainte `FOREIGN KEY` entre tables de modules différents

La spécification technique §6.3–6.4 montre des DDL avec des FK inter-tables
directes : `parties.project_id REFERENCES projects(id)`,
`wallets.party_id REFERENCES parties(id)`,
`ledger_accounts.project_id REFERENCES projects(id)`, etc. Or chaque README
de module de ce dépôt affirme déjà : « Cross-context data is joined by
correlation identifiers… never by a database join across contexts » — une FK
PostgreSQL directe entre les tables de deux modules EST une jointure de base
inter-contextes : elle couple physiquement leurs schémas, empêche d'extraire
un module en base séparée (la promesse de l'[ADR-0001](0001-monolithe-modulaire.md)),
et viole implicitement **G3c** au niveau du schéma même si le code PHP le
respecte.

**Décision** : toute colonne qui référence l'entité d'un **autre** module
(`parties.project_id`, `wallets.party_id`, `wallets.project_id`,
`ledger_accounts.project_id`, `ledger_transactions.project_id`…) est un
`uuid` simple, **sans** contrainte `REFERENCES` vers la table de l'autre
module. La validation d'existence se fait à la couche Application, via le
port/Contracts du module propriétaire (ex. `Wallet` vérifie qu'un `Party`
existe en appelant le port de `Parties`, pas en s'appuyant sur une FK).

Une FK **à l'intérieur** d'un même module reste normale et recommandée
(ex. `api_keys.project_id REFERENCES projects(id)`, toutes deux possédées par
`Identity` — déjà fait au Lot 1 ; `ledger_entries.transaction_id REFERENCES
ledger_transactions(id)`, toutes deux possédées par `Ledger`).

C'est un écart assumé par rapport à la lettre du DDL de la spécification
technique, pas à son esprit : la spec elle-même définit ce même principe de
corrélation par identifiant en §16 (« Base partagée, isolation applicative »).

## Alternatives écartées

- **Exposer `POST /v1/wallets/{id}/credit` dès le Lot 2, avec une
  autorisation stricte à base de scope** : reporté — concevoir cette
  autorisation avant d'avoir un appelant réel (`Payment`) risquerait de
  devoir la refaire, et le seul appelant hypothétique aujourd'hui n'existe
  pas encore.
- **Un seul mécanisme d'idempotence (HTTP uniquement, comme prévu par
  ADR-0025 initial)** : aurait laissé le Lot 2 sans protection contre le
  rejeu au niveau où l'invariant compte réellement — l'écriture ledger elle
  -même, indépendamment de la façon dont l'appel a été déclenché (HTTP,
  appel interne, job en file).
- **`AccountKind` en enum PHP fermé** : aurait forcé chaque futur lot
  (Fees, Payment, Billing) à modifier `packages/ledger` pour ajouter une
  nature de compte — exactement la fragilité qu'on cherche à éviter sur le
  module le plus critique.

## Conséquences

- `ModuleGraph::ALLOWED['wallet']` gagne `identity`.
- `docs/architecture/garde-fous-inactifs.md` : la ligne G7 change de lot
  d'activation (Lot 2 → Lot 3).
- ADR-0025 : note de mise à jour ajoutée, pointant vers cette décision.
