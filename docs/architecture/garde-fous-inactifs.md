# Registre des garde-fous

Un garde-fou que l'équipe croit actif alors qu'il ne protège rien est **pire
qu'aucun garde-fou** : il supprime la vigilance qui s'appliquerait autrement.

Ce registre rend cet état visible et daté. La source de vérité est
`tests/Architecture/GuardRegistry.php` ; `tests/Architecture/GuardCoverageTest.php`
vérifie que ce fichier ne mente pas :

- une règle déclarée `pending-code` **doit** figurer dans la section « en attente
  de code », avec son lot d'activation ;
- une règle déclarée `active` **ne doit pas** y figurer — un registre périmé
  transforme « temporairement inactif » en « définitivement oublié ».

Tout garde-fou `active` est aussi exercé par `make guard-test` : le script injecte
une vraie violation et exige que la porte la rejette **avec le message du
garde-fou** (une porte qui échoue pour une autre raison ne compte pas).

## Garde-fous actifs

| ID  | Règle | Outil |
| --- | ----- | ----- |
| G1  | Aucune dépendance inter-modules hors du graphe autorisé | Deptrac |
| G1b | Les dépendances déclarées dans les `composer.json` respectent le graphe | Pest |
| G2  | Le Ledger ne dépend d'aucun autre module | Deptrac + Pest |
| G3  | Les couches pointent vers l'intérieur | Deptrac |
| G3b | `Domain/` et `Contracts/` sans type framework | PHPat |
| G3c | Un module n'en atteint un autre que par son `Contracts/` — trou révélé par la revue du 2026-09-14 | PHPat |
| G3d | Le shared kernel `Support` ne contient aucune persistance | PHPat |
| G5  | Montants en `bigint` minor units, jamais en flottant ni décimal | Pest + PostgreSQL |
| G4  | Aucune colonne PII sur `parties` — actif depuis la migration Lot 2 | Pest + PostgreSQL |
| G6  | Statuts bruts provider cantonnés à leur adaptateur | Règle PHPStan |
| G6b | Enums de statuts canoniques conformes à la gouvernance — actif depuis `PaymentStatus` (Lot 3) et `NotificationStatus` (Lot 5, ADR-0041) | Pest |
| G6c | Seul l'appelant d'intégration partagé ouvre des connexions HTTP sortantes | PHPat |
| G7  | Tout endpoint monétaire exige un `Idempotency-Key` — actif depuis `POST /v1/payments` (Lot 3, ADR-0025) | Pest (application bootée) |
| G8  | Aucun événement dispatché hors de l'outbox | PHPat + règle PHPStan |
| G8c | L'écriture outbox et le changement d'état partagent une transaction — actif depuis `CreditWalletUseCase` (Lot 2, ADR-0032) | Pest + PostgreSQL |
| G9  | Aucune rupture OpenAPI sans montée de version — actif depuis l'installation de Scramble et le premier snapshot commité (`openapi/v1.json`) | oasdiff + Spectral |
| G11 | Invariants ledger (somme nulle, solde dérivé, concurrence, rejeu) — actif depuis le ledger et le wallet (Lot 2) | Pest + PostgreSQL |

Ces règles sont « actives » au sens où elles **rejettent aujourd'hui** une
violation introduite n'importe où dans le code — ce que `make guard-test`
démontre — même si peu de code métier existe encore.

## Garde-fous en attente de code

| ID   | Règle | Activé par | Propriétaire |
| ---- | ----- | ---------- | ------------ |
| G10  | Aucune régression de contrat consommateur | **Lot 7** — intégration du produit pilote | QA |

## Comment retirer une ligne de la section « en attente »

1. Le code que la règle surveille existe.
2. La règle a été **vue échouer** : ajouter une sonde dans
   `tools/guards/verify-guards.sh` et vérifier que `make guard-test` la rejette
   avec le message attendu.
3. Passer le statut à `active` dans `GuardRegistry.php`.
4. Déplacer la ligne dans le tableau « actifs ».

L'étape 2 n'est pas une formalité : une règle dont personne n'a jamais observé
l'échec n'est pas un garde-fou, c'est une intention.
