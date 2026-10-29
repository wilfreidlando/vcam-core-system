# core/export

**Bounded context :** `export`
**Responsabilité :** l'export d'un projet — asynchrone, chiffré au repos, supprimé après 7 jours
([ADR-0088](../../docs/adr/0088-reversibilite-listings-et-export.md) §3,
[ADR-0101](../../docs/adr/0101-export-par-projet-module-et-aretes.md)).

## Ce que fait le module

- `POST /v1/exports` (portée `exports:write`, `Idempotency-Key` obligatoire) enregistre un export `queued`,
  l'audite et le confie à la file ; `GET /v1/exports/{id}` donne l'état et, une fois `done`, un lien de
  téléchargement **signé valable 15 minutes** (`GET /exports/{id}/download`, authentifié par la seule signature ;
  lien altéré ou expiré → `403`). La console propose le même geste (rôles `org_owner`, `org_admin`, plateforme).
- Le fichier est **une archive `.zip`** de fichiers JSON Lines, un par type d'objet, chaque ligne au **format de
  l'API publique** : `customers`, `plans`, `subscriptions`, `invoices`, `usage`, `payments`, `notifications`,
  `wallets`, `wallet_transactions`, `ledger_transactions`, `trial_balance` (exacte à `as_of`), et un
  `manifest.json`. Les données personnelles d'un client effacé restent effacées : le module lit les **mêmes vues**
  que l'API, par les `Contracts/` des autres modules, par lots de 100 — jamais une table.
- Chiffrement au repos : l'archive est chiffrée par blocs de 1 Mio avec la clé de l'application (`Crypt`) et
  stockée sur le disque `exports` (`storage/app/private/exports`). La commande planifiée `exports:purge-expired`
  (quotidienne, idempotente) supprime les fichiers au-delà de 7 jours ; l'enregistrement reste.

## Arêtes

`export → identity, audit, ledger, billing, payment, notification, wallet` (et `support`), toutes par `Contracts/`.
Personne ne dépend d'`export`.
