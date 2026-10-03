# ADR-0060 — Sandbox et live isolés, une clé API par environnement, applications provider par environnement

- **Statut** : Accepté
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (plan d'implémentation, phase 4 ; décisions D2 et D4)
- **Dérives concernées** : D3 données · D5 contrat · D6 intégrité financière

## Contexte

- L'environnement (`sandbox`/`live`) n'existait que sur la clé API ;
  `ProjectContext::$environment` n'était lu nulle part. **Une clé sandbox déclenchait un
  vrai paiement** avec l'application MyCoolPay du projet, et créditait de vrais wallets.
- Un projet pouvait accumuler un nombre illimité de clés API.
- Modifier une application provider échouait avec le message SQL brut
  `duplicate key value violates unique constraint`. La contrainte
  `UNIQUE(provider_code, public_key)` est globale, et le contrôleur affichait le message de
  `QueryException`, sous-classe de `RuntimeException`. Il n'existait aucun écran d'édition
  et une seule application par provider, tous environnements confondus.

## Décision

1. **Une clé par (projet, environnement)**, garantie en base
   (`UNIQUE(project_id, environment)`) :
   - *Générer* est refusé si une clé existe déjà
     (`ApiKeyAlreadyExistsForEnvironment`) ;
   - *Régénérer* (`replaceExisting`, `--replace` en CLI, bouton « Régénérer ») supprime
     l'ancienne et crée la nouvelle dans **une** transaction ;
   - *Révoquer* **supprime** la clé, et l'audit garde la trace (`api_key.revoked`,
     `.replaced`, `.regenerated`) ;
   - `last_used_at` est mis à jour au plus une fois par minute ;
   - migration des données (D4) : les clés révoquées et, par environnement, toutes sauf la
     plus récente sont supprimées, chacune après une entrée d'audit.
2. **Isolation sandbox / live.** Une colonne `environment` est ajoutée sur `payments`,
   `notifications`, `webhook_endpoints`, `event_outbox` et
   `payment_provider_applications`. Les lignes existantes passent en `live`.
   - une clé n'accède qu'aux données de son environnement : `GET` → 404 sinon ;
   - un événement ne part que vers les endpoints webhook du même environnement ;
   - les logs portent `environment`.
3. **Paiements sandbox (D2)** :
   - si le projet a enregistré une application **sandbox** chez le provider, le paiement
     part vers le sandbox du provider ;
   - sinon, il passe par le **simulateur intégré** (`provider_code = sandbox`), sans aucun
     appel sortant. Le paylink renvoie une page de paiement sandbox hébergée, à URL
     signée et expirante ; le payin indique comment simuler ;
     `POST /v1/sandbox/payments/{id}/simulate` (clé sandbox, `payments:write`,
     `Idempotency-Key`) applique le résultat par **exactement** le même chemin qu'un
     callback provider : statut, parcours, événement, webhooks, `callback_url` ;
   - un paiement sandbox **ne touche jamais le ledger** : le crédit wallet demandé est
     tracé comme simulé ;
   - la réconciliation ignore les paiements simulés.
4. **Applications provider** : une par (projet, provider, environnement), avec un
   identifiant, un libellé et des dates.
   - Console : liste par environnement, création, **édition** (une clé privée laissée
     vide conserve l'actuelle) et suppression.
   - Validation applicative **avant** la base : une clé publique déjà utilisée ailleurs
     donne un message qui nomme le projet propriétaire et l'environnement, et dit quoi
     faire.
   - Les contrôleurs n'affichent plus jamais un message SQL.

## Alternatives écartées

- **Clés multiples avec un statut « révoquée »** : c'était l'état existant. Les clés
  oubliées restent actives, et l'utilisateur a demandé explicitement « une live et une
  sandbox ; révoquer supprime ; on recrée ou on remplace ».
- **Sandbox toujours simulé** : empêche de tester contre le vrai sandbox d'un provider
  quand il existe (décision D2 : les deux).
- **Une base ou un schéma séparé pour le sandbox** : opérer deux fois l'infrastructure,
  pour un gain nul tant que l'isolation applicative est testée.

## Conséquences

- Les intégrateurs qui utilisaient une clé sandbox pour de vrais paiements doivent passer
  à leur clé live. C'est voulu : c'était une faille.
- Nouvelles routes : `POST /v1/sandbox/payments/{id}/simulate` et la page signée
  `/sandbox/checkout/{ref}`.
- `PaymentGateway::checkStatus()` reçoit l'environnement, de même que
  `InitiatePaymentCommand` et `PaymentAction`.
