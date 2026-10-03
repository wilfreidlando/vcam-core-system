# ADR-0061 — Statuts intermédiaires et finaux, transitions contrôlées, contrat API minimal et payload d'événement unique

- **Statut** : Accepté — amende ADR-0035 (sémantique) et ADR-0038 §4/§5 (forme publique de l'événement)
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (plan d'implémentation, phases 3 et 5 ; décision D3 : `/v1` modifié directement, aucun intégrateur en production)
- **Dérives concernées** : D5 contrat · D6 intégrité financière

## Contexte

- « Final » était ambigu : `isTerminal()` incluait `succeeded`, alors que `refunded` peut
  le suivre. Aucune machine d'états n'existait : la seule garde était « déjà terminal ».
  Un callback `pending` en retard après `processing` était appliqué, et republiait
  l'événement.
- Le payload réellement émis pour `payment.status_changed` (`provider_code`,
  `transaction_ref`, `app_transaction_ref`…) ne correspondait pas à l'OpenAPI
  (`payment_id`, `amount`…). Il exposait le provider interne (ADR-0038), et le
  `callback_url` recevait une **troisième** forme. Audit 2026-10-03, §4.2.
- Les réponses renvoyaient à l'intégrateur ce qu'il venait d'envoyer (`idempotency_key`,
  `callback_url`) et des champs redondants (`requires_action`), sans jamais dire si
  l'issue était acquise.

## Décision

1. **Statuts.**
   - Intermédiaires : `pending`, `processing`.
   - Finaux : `succeeded`, `failed`, `expired`, `canceled`, `refunded`
     (`PaymentStatus::isFinal()`).
   - Transitions acceptées (`canTransitionTo()`, `nextStatuses()`) :
     - `pending` → `processing`, `succeeded`, `failed`, `expired`, `canceled` ;
     - `processing` → `succeeded`, `failed`, `expired`, `canceled` ;
     - `succeeded` → `refunded` ;
     - aucune autre.
   - Toute autre transition, y compris un statut identique reçu une seconde fois, est
     **refusée** : aucun événement n'est émis, et l'étape apparaît dans le Parcours avec
     le motif.
2. **Payload unique** `PaymentStatusNotification`. Les endpoints webhook **et** le
   `callback_url` reçoivent la même enveloppe `{id, type, data}`, avec `data` =
   `{payment_id, reference, status, is_final, amount, currency, failure_reason, occurred_at}`.
   - `reference` est l'`Idempotency-Key` de l'intégrateur.
   - Le callback porte l'`id` d'événement et les en-têtes `X-Event-Id` et
     `X-Event-Type`, comme les webhooks.
   - `PaymentEventContractTest` lie les clés émises au schéma OpenAPI publié.
3. **Réponses de paiement** (création et lecture) :
   - toujours `id`, `status`, `is_final`, `amount`, `currency`, `channel`, `created_at` ;
   - `updated_at` à la lecture ;
   - `payment_url` (paylink) ou `action_hint` (payin), uniquement tant que le paiement
     n'est pas final ;
   - `failure_reason`, uniquement pour un final non réussi ;
   - `callback_secret`, une seule fois, à la création.
   - Retirés : `idempotency_key`, `callback_url`, `requires_action`, `error_message`.
   - Le read model `PaymentView` garde tous les champs pour la console.

## Alternatives écartées

- **Publier `/v2` et garder `/v1`** : aucun intégrateur n'est en production (D3), et un
  `/v1` qui documente un payload qu'il n'émet pas n'est pas un contrat à préserver.
- **`allowed_actions` dans la réponse** : l'API publique n'expose aujourd'hui aucune
  action sur un paiement existant. `is_final` dit tout ce qui est actionnable : attendre,
  ou agir sur l'issue.

## Conséquences

- Les intégrateurs lisent `data.payment_id` / `data.reference` au lieu de
  `app_transaction_ref`, et `is_final` au lieu d'une liste de statuts codée en dur.
- Les jobs de callback encore en file au moment du déploiement (ancienne signature)
  échouent : `GET /v1/payments/{id}` reste la vérité, et le webhook de l'événement
  suivant porte le nouveau format.
