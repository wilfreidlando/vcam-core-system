# ADR-0066 — Un seul environnement : plus de sandbox ni de live, les tests se font sur le déploiement de staging

- **Statut** : Accepté — remplace ADR-0060 (environnements isolés, une clé par environnement, applications provider par environnement) ; le simulateur de paiement est conservé comme outil du déploiement de staging
- **Date** : 2026-10-14
- **Décideurs** : utilisateur
- **Dérives concernées** : D1 périmètre · D5 contrat · D7 organisationnelle

## Contexte

- ADR-0060 a introduit deux environnements dans les données du Core :
  - une clé `sandbox` et une clé `live` par projet ;
  - une colonne `environment` sur les paiements, notifications, endpoints de
    webhook, l'outbox et les applications provider ;
  - un simulateur intégré pour les paiements sandbox.
- Depuis, le Core a un **déploiement de staging** dédié aux tests (ADR-0064),
  isolé de la production : sa propre base, ses propres projets et clés.
  Les deux mécanismes se recouvrent.
- L'utilisateur constate que cette double notion **embrouille** :
  - un intégrateur se demande quelle clé utiliser où ;
  - la console affiche des badges et des filtres d'environnement ;
  - chaque nouvelle capacité doit être isolée par environnement. Les wallets
    (ADR-0065) auraient dû porter la même colonne.
- Décision de l'utilisateur, le 2026-10-14 : « le core-system ne doit plus
  connaître deux environnements, juste live ; on a une version déployée en
  staging pour les tests ; on ne doit plus parler de clé sandbox ».

## Décision

1. **Plus aucune notion d'environnement dans le Core.**
   - Suppression de `Core\Support\Environment`.
   - Suppression des colonnes `environment` (et de leurs contraintes et index)
     sur `api_keys`, `payments`, `payment_provider_applications`,
     `notifications`, `webhook_endpoints` et `event_outbox`.
   - `ProjectContext`, `DomainEvent` et les commandes des modules ne portent
     plus d'environnement. `GET /v1/whoami` ne renvoie plus `environment`.
2. **Une seule clé par projet** (`UNIQUE(project_id)`), créée avec le projet.
   - Le préfixe des nouvelles clés est `pk_…` ; les clés existantes
     (`pk_live_…`) restent valides, le préfixe n'étant qu'une clé de recherche.
   - Générer, régénérer et révoquer gardent leur sens d'ADR-0060.
3. **Une application provider par (projet, provider).**
4. **Données existantes**, supprimées par les migrations, sans reprise :
   - les clés sandbox, chacune tracée d'abord dans l'audit ;
   - les paiements sandbox (simulés, jamais passés au ledger) et leur parcours ;
   - les applications provider sandbox ;
   - les notifications sandbox et leurs livraisons ;
   - les endpoints de webhook sandbox, avec leurs livraisons et tentatives ;
   - les événements sandbox de l'outbox.
   Un projet qui n'avait qu'une clé sandbox n'a plus de clé : l'opérateur en
   génère une.
5. **Les tests d'intégration se font sur le déploiement de staging.** Le
   **simulateur de paiement** est conservé, mais il devient un outil de
   déploiement et non plus un attribut de clé :
   - activé par `PAYMENT_SIMULATOR_ENABLED=true`, au staging seulement, jamais
     en production ;
   - quand il est actif, un paiement dont le projet n'a pas d'application chez
     le provider routé passe par le simulateur (`provider_code = simulator`) ;
   - `POST /v1/payments/{id}/simulate` (remplace
     `/v1/sandbox/payments/{id}/simulate`) applique l'issue choisie par le même
     chemin qu'un callback réel. Il répond `409 payment-not-simulable` si le
     simulateur est désactivé ou si le paiement n'est pas simulé ;
   - la page de paiement hébergée d'un paylink simulé devient
     `/simulator/checkout/{ref}` ;
   - un paiement simulé crédite réellement le wallet demandé : sur le staging,
     tout le parcours des wallets (ADR-0065) se teste sans argent réel.

## Alternatives écartées

- **Garder sandbox et live et isoler aussi les wallets** : c'était le choix
  initial d'ADR-0065. Il multiplie la complexité de chaque capacité, alors
  qu'un déploiement de staging isole déjà tout.
- **Supprimer aussi le simulateur** : le staging ne pourrait plus tester un
  parcours sans argent réel ni sans compte de test chez chaque fournisseur.
  Gardé, mais derrière une variable de déploiement.
- **Conserver les données sandbox existantes en les passant en live** : des
  paiements simulés se mêleraient aux vrais. Elles sont supprimées.

## Conséquences

- Changement **cassant** du contrat :
  - `environment` disparaît de `GET /v1/whoami` et des listes d'endpoints ;
  - la route de simulation change d'adresse ;
  - la réponse de création de projet (admin) devient `api_key` au lieu de
    `api_keys.{sandbox,live}`.
  Seul consommateur : le premier produit, informé par cette décision.
- Console : plus de badges ni de filtres d'environnement ; l'écran des clés
  n'affiche qu'une clé.
- Configuration : `CORE_ENVIRONMENT` disparaît de `.env.example` ;
  `PAYMENT_SIMULATOR_ENABLED` apparaît (`true` au staging seulement).
- Le déploiement de staging doit garder ses propres données et identifiants
  (voir l'écart connu de `docs/DEPLOIEMENT.md`).
