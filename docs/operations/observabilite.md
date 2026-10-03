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

```bash
# 1. Variables (.env du VPS)
OTEL_EXPORTER_OTLP_ENDPOINT=http://alloy:4318
GRAFANA_URL=https://grafana.example.com
GRAFANA_HOST=grafana.example.com
GRAFANA_ADMIN_PASSWORD=<mot de passe fort>
GRAFANA_ALERT_EMAIL=ops@example.com
# + GF_SMTP_ENABLED/GF_SMTP_HOST/GF_SMTP_USER/GF_SMTP_PASSWORD pour l'envoi des alertes

# 2. Démarrer la stack d'observabilité à côté de la stack applicative
docker compose -f compose.prod.yaml -f compose.observability.yaml up -d

# 3. Redémarrer app/horizon/scheduler pour qu'ils prennent l'endpoint OTLP
docker compose -f compose.prod.yaml up -d --force-recreate app horizon scheduler
```

Composants (tous auto-hébergés, aucune donnée ne quitte le VPS) :

| Service | Rôle | Rétention |
|---|---|---|
| Grafana Alloy | collecte les logs JSON de tous les conteneurs Core et reçoit les traces OTLP | — |
| Loki | stockage des logs | 90 jours |
| Tempo | stockage des traces | 30 jours |
| Prometheus | scrape `app:8000/metrics` toutes les 30 s | 30 jours (`PROMETHEUS_RETENTION`) |
| Grafana | interface, tableaux de bord et alertes provisionnés en code | — |

Seul Grafana est exposé, via nginx-proxy sur `GRAFANA_HOST`, derrière sa propre
authentification. `/metrics` est refusé depuis Internet par le sidecar nginx.

## Tableaux de bord (dossier « Core System »)

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
- Chaque appel provider : `integration.attempt.completed` / `.transient`.
- Chaque livraison webhook : `webhook.delivery.*`.
- **Jamais en clair** : en-têtes `Authorization`, clés API, clés privées provider,
  signatures, secrets webhook et callback, mots de passe, jetons
  (`Core\Support\Integration\Redaction\SensitiveKeySet`).
