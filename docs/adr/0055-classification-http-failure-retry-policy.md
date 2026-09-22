# ADR-0055 — Classification HTTP failure : un 4xx (sauf 408/429) ne retry pas

- **Statut** : Accepté
- **Date** : 2026-09-22
- **Décideurs** : équipe Core (déclencheur : incident production — log Horizon du 22 sept., `Callback endpoint responded with HTTP 405.`)
- **Dérives concernées** : D5 contrat, D6 intégrité financière (en cascade : un paiement réussi avec un `callback_url` cassé ne doit pas masquer l'incident opérateur pendant 2h)

## Contexte

Constat empirique sur les logs production du 22 sept. 2026 :

```
22:39:36 DeliverPaymentCallbackJob  52.93ms FAIL
{"message":"Callback endpoint responded with HTTP 405.","level":400,...}
```

Le job a déclenché la policy de retry documentée d'ADR-0038 §4 :
- `$tries = 8`
- `backoff() = [30, 60, 120, 300, 900, 1800, 3600]` secondes
- Total : 7 retries supplémentaires sur ~1h45 après le premier échec

**Problème** : HTTP 405 Method Not Allowed est une erreur **permanente côté caller**. Son endpoint rejette explicitement le POST, peu importe le nombre de retries. Le job va donc :
1. Brûler 7 turns Horizon et 7 écritures en table `jobs` ;
2. Produire 7 lignes `FAIL` dans les logs ;
3. N'apprendre rien de nouveau sur le défaut ;
4. Au bout des 8 tentatives, logguer `payment.callback.delivery_exhausted` sans préciser la cause (un `failed()` générique).

Le même angle mort existe côté webhook plane : `WebhookRetryPolicy::nextRetryAt()` ne lit pas `httpStatus`. La différence est qu'il y a une DLQ visible (ADR-0044, `webhook:replay` + admin `/admin/webhook-deliveries/{id}/replay`) — un opérateur qui consulte la DLQ voit le 405 et corrige. Côté paiement, aucune surface équivalente : l'opérateur (ici : l'intégrateur qui a fourni le `callback_url`) n'a aucun signal pendant les 2h.

## Décision

**Un échec HTTP 4xx autre que 408 Request Timeout et 429 Too Many Requests est permanent et ne doit pas consommer le budget de retry.** Le 5xx, le 408, le 429 et les erreurs réseau (statut `null`) restent transients.

La classification est implémentée comme un helper pur (`Core\Support\Http\HttpStatusClassifier`), consommé par les deux planes qui font des POST sortants vers des URLs caller-supplied :

| Plane | Avant | Après |
|---|---|---|
| Webhook plane (`Core\Webhook`) | 4xx retente jusqu'à 8 fois puis DLQ | 4xx permanent → DLQ immédiate (1 tentative) |
| Payment callback plane (`Core\Payment`) | 4xx retente 8 fois sur ~2h puis log | 4xx permanent → `$this->fail()` immédiat (1 tentative) |

Le mode de « fin de retry » diffère entre les deux planes parce que **leur surface de récupération diffère** :
- Webhook plane : la livraison est persistée (`webhook_deliveries`) et une DLQ + replay existent — un opérateur peut corriger l'endpoint et rejouer manuellement.
- Payment callback plane : il n'y a pas de DLQ (trade-off ADR-0038 §4). Le seul feedback est le log structuré `payment.callback.delivery_exhausted` + la table Laravel `failed_jobs`. Aller directement à `failed()` raccourcit le délai entre l'incident et le log d'épuisement.

### Classification — la table de vérité

| Status | Classification | Raison |
|---|---|---|
| `null` (réseau, TLS, connect, timeout) | transient | Pas de réponse reçue ; le défaut est potentiellement côté Core ou transitoire côté réseau |
| `1xx`, `2xx`, `3xx` | (jamais `failure()`) | Le sender ne construit un `failure()` qu'à partir d'un non-2xx — la classification n'est consultée que sur le chemin d'échec |
| `408 Request Timeout` | transient | RFC 7231 §6.5.7 : "the client did not produce a request within the time the server was prepared to wait" — signal de load-shedding, retenter est la norme |
| `429 Too Many Requests` | transient | Le récepteur demande explicitement de ralentir ; retenter avec le backoff existant est la bonne réponse |
| `4xx` autres (400, 401, 403, 404, 405, 410, 415, 422, …) | permanent | Le récepteur a compris la requête et l'a rejetée sur un défaut qu'aucun retry ne corrigera |
| `5xx` | transient | Défaut côté serveur du récepteur ; peut se résoudre |

### Mécanisme d'intégration

1. **Helper** : `Core\Support\Http\HttpStatusClassifier::isPermanentFailure(?int $httpStatus): bool`. Pur PHP, dépendances zero — ajout au shared kernel conformément à ADR-0020.
2. **Au niveau du résultat** : `WebhookSendOutcome` et `PaymentCallbackSendOutcome` reçoivent un nouveau champ `bool $isPermanentFailure`, calculé à la construction depuis `httpStatus`. Pas de re-dérivation côté consumer.
3. **Au niveau de la policy** : `WebhookRetryPolicy::nextRetryAt(int, DateTimeImmutable, bool $isPermanentFailure = false)` retourne `null` (→ DLQ) si la dernière réponse était permanente, sans consulter le compteur d'attempts.
4. **Au niveau du use case** : `DeliverWebhookUseCase` passe `$outcome->isPermanentFailure` à la policy. Aucun changement de comportement sur le chemin success/transient.
5. **Au niveau du job payment callback** : `DeliverPaymentCallbackJob::handle()` appelle `$this->fail($outcome->error)` sur permanent (au lieu de `throw`). Laravel route `fail()` vers la méthode `failed()` du job, qui log `payment.callback.delivery_exhausted` — un seul round-trip au lieu de 8.
6. **Couverture de test** :
   - `HttpStatusClassifierTest` : table de vérité exhaustive via data provider.
   - `WebhookSendOutcomeTest`, `PaymentCallbackSendOutcomeTest` : classification à la construction.
   - `WebhookRetryPolicyTest` : cas permanent → `null` au premier attempt.
   - `DeliverWebhookUseCaseTest` : cas 405 → `Dead` immédiat.
   - `DeliverPaymentCallbackJobTest` : cas 405 → pas de throw (et donc pas de retry).

### Garde-fou

Cette classification est une dépendance partagée entre webhook et payment callback — l'oublier sur l'un des deux serait une régression silencieuse. Le test `HttpStatusClassifierTest` couvre exhaustivement la table de vérité, et tous les consumers (les deux outcomes, la policy, le job) ont un test explicite « permanent → pas de retry ». Un futur `Core\Storage` ou autre plane sortant du HTTP héritera de la même discipline en important `HttpStatusClassifier` directement.

## Alternatives écartées

- **Pré-valider le `callback_url` à `POST /v1/payments`** par un `OPTIONS`/`HEAD` pour rejeter en 422 les URLs qui n'acceptent manifestement pas POST. Intéressant mais rejeté pour cette ADR : (a) coût d'un round-trip par initiation, (b) ne couvre pas le cas où le serveur répond OK à `OPTIONS` puis rejette le POST réel, (c) `callback_url` peut être derrière un WAF/anti-bot qui n'autorise pas les méthodes non-POST. À reconsidérer si le pattern reste rare en production.
- **Aligner `WebhookRetryPolicy` sur un compteur de `tries` plus court pour les 4xx**. Rejeté : ça garde la complexité du compteur sans bénéfice (un 4xx ne mérite aucun retry, point).
- **Distinguer 405/410 du reste des 4xx** (URL ou méthode fondamentalement incompatible) des 4xx « payload-related » (400, 422). Rejeté : trop subtil, et `retry → fail → operator fixes` est la même boucle dans les deux cas. La table de vérité est volontairement binaire (permanent / transient), pas ternaire.
- **Étendre `Core\Webhook` à un état `WebhookDeliveryStatus::FailedPermanent`** distinct de `Dead`. Rejeté : `Dead` est déjà l'état terminal non-rejouable ; « pourquoi c'est mort » est une métadonnée portée par `last_http_status` + `last_response_body` (déjà en table depuis la migration `2026_09_22_000001_add_last_response_to_webhook_deliveries_table`). Un état supplémentaire multiplierait les chemins de code sans bénéfice observable côté opérateur.

## Conséquences

- **Plus facile** : un opérateur voit un 405 dans la DLQ webhook immédiatement après le premier échec (au lieu de 8 FAILs étalés sur 2h). Côté paiement, le log `payment.callback.delivery_exhausted` arrive dans la seconde qui suit l'incident.
- **Plus difficile** : aucune — les consumers existants voient le même contrat, juste un chemin d'arrêt plus court.
- **Engage** :
  - migration des deux `failure()` outcomes pour porter `isPermanentFailure` ;
  - `WebhookRetryPolicy` accepte le paramètre (avec valeur par défaut `false` pour ne pas casser les appelants existants) ;
  - `DeliverPaymentCallbackJob` doit appeler `$this->fail()` au lieu de `throw` sur permanent — attention à l'ordre : la policy de retry Laravel (`$tries`/`backoff()`) reste intacte pour le transient, le `$this->fail()` court-circuite uniquement le permanent ;
  - si un futur plane sortant HTTP (ex : `Core\Storage` avec une URL de download S3-compatible présignée) importe `HttpStatusClassifier` et adopte le même comportement, sinon c'est une régression.
- **Point de réévaluation** : si le pattern « caller POST nos endpoints sans accepter notre méthode » se révèle fréquent (>5% des échecs), reconsidérer la pré-validation `OPTIONS` à l'enregistrement. Pour l'instant, le coût d'un round-trip supplémentaire à chaque initiation de paiement ne se justifie pas sur un signal faible.