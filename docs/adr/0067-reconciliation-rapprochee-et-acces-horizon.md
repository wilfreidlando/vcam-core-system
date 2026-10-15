# ADR-0067 — Réconciliation des paiements chaque minute, à tour de rôle, et accès opérateur à Horizon

- **Statut** : Accepté — amende ADR-0044 §7 (seuil et fréquence de la réconciliation planifiée)
- **Date** : 2026-10-15
- **Décideurs** : utilisateur
- **Dérives concernées** : D4 provider · D6 intégrité financière · D7 organisationnelle

## Contexte

- **Le staging ne reçoit pas les rappels du provider.** MyCoolPay rappelle
  l'adresse de production, si bien que sur le déploiement de staging, la seule
  voie de confirmation est la réconciliation planifiée.
- **Le délai actuel est trop long.** Le 2026-10-15, un paiement MTN MoMo de
  100 XAF a été validé sur le téléphone à 08:10.
  - Le seuil `stuck_after_minutes` vaut 15 (ADR-0044 §7) et la tâche passe
    toutes les 5 minutes. Le paiement ne devenait donc candidat qu'à 08:25, et
    serait passé au plus tard vers 08:30.
  - L'opérateur l'a réconcilié à la main pour obtenir son statut.
  - Pour un payeur qui attend devant son écran, 15 à 20 minutes, c'est trop long.
- **La sélection actuelle peut bloquer les nouveaux paiements.**
  - `findStuckSince()` prend les paiements `pending`/`processing` du plus
    ancien `updated_at` au plus récent, au plus `batch_size` (100) par passage.
  - Une vérification qui ne change rien ne modifie pas le paiement.
  - Un paiement abandonné (le payeur n'a jamais validé, le provider répond
    `pending` indéfiniment) reste donc en tête à chaque passage.
  - Au-delà de 100 paiements abandonnés, la réconciliation relit toujours les
    mêmes et n'atteint plus jamais les nouveaux.
  - Le commentaire de `ReconcileStuckPaymentsUseCase` reconnaît l'absence de
    garde contre cette famine. Passer à une exécution par minute rendrait le
    défaut plus coûteux (appels au provider), sans le corriger.
- **Horizon est inaccessible sur les déploiements.** `/horizon` répond 403 sur
  le staging. Sans règle d'accès, Horizon n'autorise que l'environnement
  `local`, et le Core n'en déclare aucune pour ses opérateurs. Les files
  (webhooks, callbacks de paiement, notifications) ne se voient que par
  Grafana.

## Décision

1. **Planification.** `admin:reconcile-stuck-payments` passe **chaque minute**
   (au lieu de toutes les 5 minutes), toujours `withoutOverlapping()`.
2. **Seuil.** `PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES` vaut **2** par
   défaut (au lieu de 15) : un paiement sans nouvelle depuis 2 minutes est
   demandé au provider.
3. **À tour de rôle.**
   - Nouvelle colonne `payments.reconciled_at` (`timestamptz`, nullable),
     renseignée à chaque vérification, qu'elle change le statut ou non, et
     même en cas d'erreur du provider. La réconciliation à l'unité depuis la
     console la renseigne aussi.
   - Le « dernier regard » sur un paiement est `GREATEST(updated_at,
     reconciled_at)` : son dernier changement ou sa dernière vérification, le
     plus récent des deux.
   - Un paiement est candidat si :
     - il est `pending` ou `processing` ;
     - son dernier regard est antérieur au seuil ;
     - il a été créé dans la fenêtre d'âge (point 4).
   - Les candidats sont pris du dernier regard le plus ancien au plus récent :
     chaque paiement bloqué est revu toutes les 2 minutes au plus tôt, et aucun
     ne peut en empêcher un autre d'être revu.
   - Un index partiel sur cette expression couvre la requête.
4. **Fenêtre d'âge.**
   - `PAYMENT_RECONCILIATION_MAX_AGE_HOURS`, **24** par défaut. Au-delà, un
     paiement n'est plus demandé automatiquement au provider.
   - La réconciliation d'un paiement à l'unité depuis la console
     (`ReconcilePayment`) reste possible à tout âge.
   - L'aperçu et le compte des paiements bloqués (console) suivent les mêmes
     critères : ils montrent ce que le prochain passage traitera.
5. **Accès à Horizon.**
   - `/horizon` est ouvert à l'opérateur connecté à la console web (même
     session et même résolution du jeton que `operator.session`) qui porte la
     permission `queues:manage`.
   - Seul `PlatformAdmin` la porte (`*`) : Horizon permet de relancer et de
     supprimer des tâches.
   - En `local`, Horizon reste ouvert comme avant.
   - La console affiche le lien « Files (Horizon) » aux seuls opérateurs qui
     ont cette permission.

## Alternatives écartées

- **Seulement baisser le seuil et la période.** Le délai serait corrigé, mais
  la famine (Contexte) serait aggravée : jusqu'à 100 appels au provider par
  minute sur les mêmes paiements abandonnés.
- **Toucher `updated_at` à chaque vérification.** Cela donne le même tour de
  rôle sans colonne, mais `updated_at` ne dirait plus « dernier changement du
  paiement ». La console et les compteurs (`countRecentByStatus`) en
  dépendent.
- **Trier du plus récent au plus ancien.** Les nouveaux paiements passeraient,
  mais les anciens de moins de 24 heures pourraient ne jamais l'être en cas
  d'afflux.
- **Faire rappeler le staging par le provider.** C'est hors de notre main
  (configuration MyCoolPay par application). La réconciliation rapide reste
  utile en production, comme filet quand un rappel se perd.
- **Ouvrir Horizon par une liste d'adresses IP ou un mot de passe HTTP.** Ce
  serait un second mécanisme d'authentification à maintenir, sans MFA et sans
  révocation. La session opérateur existante a les deux.

## Conséquences

- Sur le staging, une confirmation MyCoolPay est connue en 2 à 3 minutes au
  plus. En production, un rappel manqué est rattrapé dans le même délai.
- **Charge provider bornée.**
  - Au plus `batch_size` (100) appels `checkStatus()` par minute.
  - Chaque paiement bloqué de moins de 24 heures est revu toutes les 2 minutes
    environ. Au-delà de 100 candidats, chacun est revu un peu plus rarement,
    mais tous le sont.
  - Si cette charge devient sensible, il faudra relever le seuil
    (`PAYMENT_RECONCILIATION_STUCK_AFTER_MINUTES`) ou réduire le lot, sans
    changement de code.
- Migration `add_reconciled_at_to_payments_table` (colonne nullable et index
  partiel sur l'expression), sans reprise de données.
- `PaymentRepository` gagne `markReconciliationChecked()`, et
  `findStuckSince()`/`countStuckSince()` prennent la borne d'âge.
- Nouvelle permission `queues:manage`, portée par `PlatformAdmin` seulement.
- **Point de réévaluation** : quand des adaptateurs MTN/Orange directs
  existeront, avec leurs propres délais de rappel, réexaminer le seuil par
  provider.
