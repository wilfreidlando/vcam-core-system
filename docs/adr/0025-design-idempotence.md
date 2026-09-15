# ADR-0025 — Design de l'idempotence HTTP

- **Statut** : Accepté — design figé ; implémentation au **Lot 3** (amendé par [ADR-0033](0033-conception-lot-2-money-core.md) : Wallet n'expose pas d'endpoint HTTP de mutation au Lot 2, donc ce store HTTP générique n'a pas encore d'appelant réel ; l'idempotence du Lot 2 passe par `ledger_transactions.idempotency_key`)
- **Date** : 2026-09-14
- **Dérives concernées** : D6

## Contexte

[ADR-0012](0012-idempotency-key-obligatoire.md) impose la règle ; ce document
fige le mécanisme avant que le premier endpoint monétaire n'existe, pour que le
Lot 2 n'ait qu'à l'appliquer plutôt qu'à le concevoir.

La revue d'architecture (§3.6) proposait de le livrer en Phase 0, avant même
l'Identity. Écarté : l'idempotence n'a de sens qu'appliquée à une vraie mutation,
et son premier consommateur réel est le wallet (Lot 2), pas Identity (Lot 1). La
construire sans consommateur risquerait de figer un contrat mal calibré — la
porte n°1 de la gouvernance (transversalité réelle) s'applique aussi en interne.

## Décision

- Table `idempotency_keys` : `project_id`, `key` (fourni par le client),
  `request_hash` (empreinte du corps de la requête), `response_status`,
  `response_body` (JSON mis en cache), `locked_at`, `completed_at`, `expires_at`.
  Unicité sur `(project_id, key)`.
- Portée : projet + clé. Deux projets peuvent réutiliser la même clé sans
  collision.
- Comportement du middleware `RequireIdempotencyKey` :
  - clé absente sur une route monétaire → `400 problem+json`
    (`type=.../idempotency-key-required`) ;
  - clé connue, `request_hash` identique, requête terminée → renvoie
    `response_status`/`response_body` d'origine, **aucun second effet** ;
  - clé connue, `request_hash` différent → `409 problem+json`
    (`type=.../idempotency-key-conflict`) : la clé a été réutilisée pour une
    requête différente ;
  - clé connue, requête encore en cours (`locked_at` récent, `completed_at`
    nul) → `409` (concurrence sur la même clé) ;
  - clé absente en base → verrou posé (`locked_at`), la requête s'exécute.
- TTL configurable par capacité (24 h par défaut) ; l'entrée expirée est purgée
  par une tâche planifiée, jamais lue comme valide après expiration.
- Le port `IdempotencyStore` vit dans `Core\Support\Idempotency` (primitive
  technique, aucune règle métier) ; le stockage réel est implémenté par le
  module qui l'utilise en premier (Wallet, Lot 2).

## Alternatives écartées

- **Clé + hash sans réponse mise en cache** : empêcherait de renvoyer exactement
  la même réponse au rejeu, ce que les clients attendent d'une requête
  idempotente.
- **TTL unique global** : une notification et un paiement n'ont pas la même
  fenêtre de rejeu légitime ; le TTL est paramétrable par capacité.

## Conséquences

- Le garde-fou **G7** passe de « en attente de code » à « actif » dès que ce
  middleware protège la première route de mutation de wallet (Lot 2).
