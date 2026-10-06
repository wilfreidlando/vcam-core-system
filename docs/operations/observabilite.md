# Observabilité — logs, traces, métriques, parcours des transactions

Ce document décrit comment suivre l'application en production : où vont les logs,
comment retrouver une requête de bout en bout, quels tableaux de bord et quelles
alertes existent. Décision d'architecture : [ADR-0059](../adr/0059-observabilite-stack-centralisee-et-parcours-metier.md).

## En une phrase

Chaque requête reçoit un **`Correlation-Id`** (renvoyé dans l'en-tête de réponse) et un
**`trace_id`**. Tous les logs, toutes les traces et toutes les étapes métier qu'elle
provoque les portent, y compris les jobs exécutés plus tard : livraisons webhook,
callbacks de paiement. On retrouve donc tout à partir de ce seul identifiant.

## Deux façons de suivre une transaction

| Besoin | Où regarder |
|---|---|
| « Que s'est-il passé pour ce paiement ? » (vue métier, opérateur) | Console admin → Paiements → un paiement → section **Parcours** |
| « Pourquoi cette requête a échoué / a été lente ? » (vue technique) | Grafana → tableau **Core — Parcours d'une requête**, coller le `Correlation-Id` |

### Le Parcours d'un paiement (console admin)

Étapes enregistrées, dans l'ordre, avec le détail complet (secrets toujours masqués) :

1. **Requête reçue** : corps envoyé par l'intégrateur, `Idempotency-Key`, IP, user-agent.
2. **Appel provider** : requête HTTP envoyée à MyCoolPay et sa réponse, avec durée et
   numéro de tentative.
3. **Paiement créé** : provider, canal, client, URLs, statut initial (intermédiaire ou final).
4. **Réponse envoyée** : corps renvoyé à l'intégrateur (`callback_secret` masqué).
5. **Callback provider** : notification reçue de MyCoolPay, authentifiée ou **rejetée**
   (IP, signature), avec le corps reçu.
6. **Réconciliation** : interrogation du provider (planifiée ou manuelle) et sa réponse.
7. **Changement de statut** : avant → après, source (callback ou réconciliation), final ou
   non. Un statut tardif reçu sur un paiement déjà final apparaît comme **Statut ignoré**.
8. **Wallet crédité** : si l'intégrateur l'avait demandé (ADR-0037).
9. **Événement publié** : `payment.status_changed`, avec son `event_id` et son canal de
   notification (endpoints webhook ou `callback_url`).
10. **Webhook / Callback URL** : chaque tentative de livraison, avec la requête signée
    (signature masquée), le statut HTTP et le corps de la réponse de l'intégrateur, la
    durée et la décision prise (livré, nouvel essai, DLQ).

Chaque étape propose un lien **« voir les logs et la trace technique »** vers Grafana
lorsque `GRAFANA_URL` est configuré.

Rétention : 90 jours (`PAYMENT_TIMELINE_RETENTION_DAYS`), puis purge automatique
(`model:prune`, chaque nuit).

## Mise en place en production

Depuis l'[ADR-0064](../adr/0064-infrastructure-vps-staging-observabilite-mutualisee.md),
la stack d'observabilité **n'appartient plus au Core**. C'est un service mutualisé
du VPS, dans son propre projet Docker (`observability`), partagé par tous les
backends du serveur.

- Installation et branchement d'un autre projet :
  [`observability/README.md`](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/observability/README.md) de la plateforme.
- Le Core est déjà branché : `compose.prod.yaml` met `app`, `horizon` et
  `scheduler` sur le réseau `observability`, avec les labels
  `observability.app=core-system` et `observability.deployment=prod|staging`.

Variables du `.env` du Core (et de `.env.staging`) :

```bash
OTEL_SERVICE_NAME=core-system            # core-system-staging en staging
OTEL_EXPORTER_OTLP_ENDPOINT=http://observability-alloy:4318
GRAFANA_URL=https://grafana.visibilitycam.com
```

Composants (tous auto-hébergés, aucune donnée ne quitte le VPS) :

| Service | Rôle | Rétention |
|---|---|---|
| Grafana Alloy | découvre les conteneurs par labels, collecte leurs journaux JSON, interroge leur `/metrics`, reçoit les traces OTLP | — |
| Loki | stockage des journaux | 90 jours |
| Tempo | stockage des traces | 30 jours |
| Prometheus | stockage des métriques (envoyées par Alloy) | 30 jours (`PROMETHEUS_RETENTION`) |
| Grafana | interface, tableaux de bord et alertes provisionnés en code | — |

Seul Grafana est exposé, via nginx-proxy sur `GRAFANA_HOST`, derrière sa propre
authentification. `/metrics` est refusé depuis Internet par le sidecar nginx.

Les tableaux de bord du Core ont un sélecteur **Déploiement** (`prod` / `staging`).
**Les alertes ne portent que sur la production.**

## Tableaux de bord (dossier « core-system », plus « Plateforme » et « Applications » pour tous les projets)

> **Ces tableaux et ces alertes vivent dans CE dépôt** (`observability/dashboards/*.json`, `observability/alerts/core-alerts.yaml`) : `deploy.sh` les publie dans Grafana après une promotion
> en production, ou à la demande avec `deploy.sh obs-sync` ; la plateforme n'est pas modifiée ([guide 19](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/19-observabilite-de-mon-projet.md)).
> Les tableaux se rechargent seuls ; **les alertes ne sont relues qu'à la recréation de Grafana**. Règles : `uid` uniques, dossier d'alertes = `core-system`,
> jamais de points de contact ni de politique de notification (ils sont à la plateforme).
> L'état d'ensemble d'une application se voit aussi dans **Plateforme → Application — vue d'ensemble → `core-system`**.

- **Core — Vue d'ensemble** : disponibilité, retard de l'outbox, webhooks en DLQ, taux
  de 5xx, débit et latence p95 par route, paiements et notifications des dernières 24 h
  par statut, livraisons webhook, files Horizon, dernières erreurs.
- **Core — Parcours d'une requête** : saisir un `Correlation-Id` (et, au besoin, un
  `trace_id`) pour afficher toutes les lignes de la requête, ses échanges HTTP, ses appels
  provider et ses livraisons sortantes, puis la trace complète.

## Alertes (e-mail vers `GRAFANA_ALERT_EMAIL`)

| Alerte | Déclencheur | Première action |
|---|---|---|
| Core injoignable | `/metrics` ne répond plus pendant 2 min | conteneur `app` et base de données |
| Relais outbox bloqué | événement non relayé depuis plus de 5 min | conteneur `scheduler` (`events:relay`) |
| Nouvelles livraisons webhook en DLQ | +1 livraison `dead` sur 15 min | console → Webhooks → DLQ, puis rejouer une fois l'endpoint corrigé |
| Taux d'erreurs 5xx élevé | plus de 5 % sur 5 min | tableau Vue d'ensemble → erreurs récentes |
| Callbacks provider rejetés | IP, signature ou secret invalide | fraude ou configuration provider |
| File Horizon en retard | plus de 500 jobs pendant 10 min | conteneur `horizon` |

## Sondes

- `GET /up` : vivacité. Le process PHP répond.
- `GET /ready` : disponibilité réelle. Base, Valkey, outbox relayé depuis moins de 5 min.
  Renvoie `503` si un contrôle échoue, sans jamais exposer de message d'erreur.
- `GET /metrics` : format Prometheus. Accès par réseau privé, ou par
  `Authorization: Bearer <METRICS_TOKEN>`.

## Ce qui est journalisé (et ce qui ne l'est jamais)

- Chaque requête HTTP : une ligne `http.request.received` (méthode, chemin, IP, en-têtes
  et corps **rédigés**) et une ligne `http.response.sent` (route, statut, durée, corps
  JSON rédigé). Les sondes, `/metrics` et la documentation sont exclus.
  **Niveau de la ligne de réponse** : `ERROR` pour un 5xx ; `WARNING` pour un 4xx (401, 403, 422, 429, ou un 404
  sur une route qui existe) ; **`INFO` pour un 404 sur un chemin qu'aucune route ne reconnaît** : ce sont les robots
  d'Internet qui sondent `/.env`, `/wp-login.php`… (311 des 317 avertissements du 2026-10-05). Le Core répond correctement,
  rien n'est à faire ; la ligne reste dans Loki (`{app="core-system"} |= "\"route\":null"`) si l'on veut voir qui sonde.
  Un compteur d'avertissements qui monte signifie donc de vrais refus de clients, pas du bruit.
- Chaque appel provider : `integration.attempt.completed` / `.transient`.
- Chaque livraison webhook : `webhook.delivery.*`.
- **Jamais en clair** : en-têtes `Authorization`, clés API, clés privées provider,
  signatures, secrets webhook et callback, mots de passe, jetons
  (`Core\Support\Integration\Redaction\SensitiveKeySet`).
