# ADR-0101 — Export par projet : le module `export` et ses arêtes

- **Statut** : Proposé — réalisation d'ADR-0088 §3 ; à ratifier par le comité d'architecture
- **Date** : 2026-10-29
- **Décideurs** : commanditaire (cadrage de livraison) ; comité d'architecture (ratification)
- **Dérives concernées** : D2 couplage · D3 données/PII · D5 contrat
- **Amende** : ADR-0088 §3, ADR-0068 (graphe de modules)

## Contexte

ADR-0088 §3 décide qu'un projet peut obtenir l'export de tout ce qu'il a produit : asynchrone, au format
du contrat public, chiffré au repos, lien signé de 15 minutes, supprimé après 7 jours, audité. Cet
export lit presque tous les modules (clients, abonnements, factures, paiements, notifications, usage,
portefeuilles, grand livre). Aucun module existant n'est un bon hôte : `billing` ne doit pas connaître
le grand livre ni les notifications, et `admin` est la console des opérateurs, pas une API de projet.

## Décision

### 1. Un module `export`, hub de lecture

Nouveau module `export` (`packages/export`), construit comme `admin` : il lit à travers les `Contracts/`
des autres modules (G3c), jamais une table. **Personne ne dépend de lui.**

### 2. Arêtes (et seulement celles-là)

`export → support, identity, audit, ledger, billing, payment, notification, wallet`.

| Arête | Pourquoi |
| ----- | -------- |
| `identity` | `ProjectContext` et la portée de la clé (`exports:write`) dans les contrôleurs |
| `audit` | `AuditLogger` : demande, fin, échec, téléchargement et purge sont audités |
| `ledger` | `ListLedgerTransactions` et `TrialBalance` : transactions et balance à la date de l'export |
| `billing`, `payment`, `notification`, `wallet` | une interface `…ExportRecords` par module (`Contracts/`), qui rend les lignes **au format de l'API publique** |

Mis à jour ensemble : `tests/Architecture/ModuleGraph.php`, `deptrac-modules.yaml`,
`packages/export/composer.json`. Pas de cycle : aucun module ne connaît `export`. `events` n'est pas
une arête : l'export ne publie aucun événement.

### 3. Le format est celui de l'API, produit par le module qui le possède

`export` ne connaît la forme ni d'une facture ni d'un paiement. Chaque module expose une interface
(`BillingExportRecords`, `PaymentExportRecords`, `NotificationExportRecords`, `WalletExportRecords`) dont
l'implémentation vit dans sa `Presentation/` et **réutilise le sérialiseur des contrôleurs publics**
(`SerializesBillingViews`, `PaymentJson`, `NotificationJson`, `WalletJson`). L'export et l'API ne peuvent
donc pas dériver, et les données personnelles d'un client effacé restent effacées : ce sont les mêmes
vues. Le grand livre n'a pas de forme publique : `export` en définit une (lignes de transaction, balance).

### 4. Le déroulement

- `POST /v1/exports` (portée `exports:write`, `Idempotency-Key` obligatoire : le rejeu rend le même
  export) ou la console (`org_owner`, `org_admin`, plateforme) enregistre une ligne `exports` en
  `queued`, l'audite et pousse un job (`RunExportJob`, un essai, idempotent : seul un export `queued`
  est construit). `GET /v1/exports/{id}` rend l'état et, une fois `done`, un lien **signé de 15 minutes**
  (`GET /exports/{id}/download`, route signée temporaire hors `/v1`, authentifiée par la seule signature ;
  lien altéré ou expiré → `403`).
- Contenu : **une archive `.zip`** de fichiers JSON Lines, un par type : `customers`, `plans`,
  `subscriptions`, `invoices`, `usage`, `payments`, `notifications`, `wallets`, `wallet_transactions`,
  `ledger_transactions`, `trial_balance`, plus un `manifest.json` (`as_of`, lignes par fichier). Les
  transactions du grand livre et la balance sont exactes à `as_of` ; les autres fichiers sont lus pendant
  l'export. Les ports sont parcourus **par lots de 100**, jamais une table entière en mémoire.
- Chiffrement au repos : l'archive est chiffrée **par blocs de 1 Mio** avec la clé de l'application
  (`Crypt`, authentifié) puis écrite sur le disque privé `exports` (`<projet>/<export>.zip.enc`) ; un
  bloc altéré, retiré ou une archive tronquée échoue au déchiffrement. Le téléchargement déchiffre en
  flux. Le contenu des notifications et leurs destinataires ne sont pas exportés (l'API ne les liste pas).
- Rétention : `exports:purge-expired` (quotidienne, idempotente) supprime les fichiers de plus de
  7 jours ; la ligne reste, avec `file_path` vide. Table `exports` : `id`, `project_id`, `status`
  (`queued|running|done|failed`), `requested_by`, `created_at`, `finished_at`, `expires_at`, `file_path`,
  `error` — index seulement, aucune contrainte.
- Une panne est consignée sur l'export (`failed`, le nom de l'exception, jamais un message qui pourrait
  porter une donnée) et rapportée aux journaux.

### 5. Permissions

`exports:write` (déjà dans le catalogue de clés, ADR-0082) ; côté console, portée par `org_owner` (`*`),
`org_admin` et les rôles plateforme (`*`). La page « Export des données » d'un projet n'existe que pour
eux ; le téléchargement depuis la console génère le lien signé au clic.

## Alternatives écartées

- **Héberger l'export dans `admin`** : mélange l'API d'un projet et la console des opérateurs.
- **Lire les tables des modules** : fige le schéma interne et contourne l'effacement (ADR-0088,
  « Accès en lecture à la base », déjà rejeté).
- **Interface unique dans `core/support`** : évitait les arêtes, mais le manifeste du noyau partagé est
  fermé (ADR-0020) et un port « source d'export » n'est pas une primitive transverse.
- **Chiffrer l'archive d'un bloc** : mémoire non bornée pour un gros projet.

## Conséquences

- Nouvelle arête de lecture de cinq modules ; `export` est la seule porte d'un export complet.
- Les ports de liste de `payment` et `notification` gagnent une variante à curseur (ADR-0088 §2) ; `billing`
  un journal d'usage à curseur.
- Le disque `exports` est local par défaut ; en production il peut pointer vers un stockage objet privé
  sans changer le code (le chiffrement est fait avant l'écriture).

## Vérification

- `tests/Integration/ExportFlowTest.php` : demande → job → `done` → lien signé → archive déchiffrable dont
  les lignes sont égales aux pages de l'API ; objets d'un autre projet absents ; lien expiré `403` ;
  purge à 7 jours avec `Clock` injectée ; rejeu de la même `Idempotency-Key` ; clé sans `exports:write` `403`.
- Tests d'architecture : graphe de modules, IDOR console de la page d'export.
