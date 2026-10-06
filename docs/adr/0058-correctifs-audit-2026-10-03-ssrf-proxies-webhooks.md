# ADR-0058 — Correctifs de l'audit du 2026-10-03 : SSRF à la connexion, IP client, HTTPS, fiabilité des webhooks

- **Statut** : Accepté
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (validation du plan d'implémentation, phase 1)
- **Dérives concernées** : D3 données · D5 contrat · D6 intégrité

## Contexte

L'audit [docs/audit/audit-2026-10-03.md](../audit/audit-2026-10-03.md) relève, après
ADR-0051, des risques restants : SSRF par redirection HTTP et par DNS rebinding
(§2.1), IP client faussée derrière deux proxies (§2.2), HTTPS non imposé (§2.3),
plages d'IP non bloquées (§2.4), livraisons webhook orphelines (§4.3), livraisons
vers un endpoint désactivé (§4.4), rétention illimitée (§4.6), file Horizon unique (§3.2).

## Décision

1. **Aucune redirection suivie** par `LaravelHttpTransport` (`allow_redirects => false`) :
   un `3xx` est une réponse comme une autre, donc un échec de livraison.
2. **`GuardedHttpTransport`** (`core/support`) : pour toute URL choisie par un Project
   (endpoint webhook, `callback_url`), l'hôte est re-résolu et re-vérifié à chaque
   envoi, puis la connexion est épinglée sur l'adresse vérifiée (`CURLOPT_RESOLVE`,
   via `PinnableHttpTransport`). Branché par liaison contextuelle sur
   `HttpWebhookSender` et `HttpPaymentCallbackSender`. Désactivable
   (`OUTBOUND_ENFORCE_AT_CONNECT=false`), ce qui n'est le cas qu'en test, où
   `GuardedHttpTransportTest` le couvre de façon hermétique.
3. **`OutboundUrlGuard`** déballe les IPv6 qui embarquent une IPv4 (mappée `::ffff:`,
   NAT64 `64:ff9b::/96`, compatible) et bloque explicitement `100.64.0.0/10`,
   `198.18.0.0/15` et `192.0.0.0/24`.
4. **HTTPS obligatoire** pour `url` (webhook) et `callback_url`
   (`SafeOutboundUrlRule`), sauf `OUTBOUND_ALLOW_INSECURE_HTTP=true` (développement
   local uniquement).
5. **Proxies de confiance** : `config/trustedproxy.php` (`TRUSTED_PROXIES`, réseaux
   privés par défaut) remplace `trustProxies(at: '*')`, qui ne faisait confiance qu'au
   dernier saut. Couvert par `TrustedProxiesTest` (deux sauts, XFF falsifié, appel
   direct).
6. **Limiteur `api`** : plafond par IP (600/min) en plus du plafond par clé (300/min).
7. **Webhooks** :
   - nouveau statut terminal `canceled` : endpoint désactivé avant la livraison ;
     rien n'est envoyé, aucun rejeu possible (`WebhookEndpointInactive`, 409) ;
   - `DeliverWebhookJob::failed()` passe la ligne en `dead` ;
   - verrou par livraison, et un job exécuté avant `next_retry_at` (doublon) ne fait
     rien ;
   - `webhook:sweep` (toutes les 5 minutes) relance les `pending` en retard de plus
     de 15 minutes.
8. **Rétention** : `model:prune` quotidien sur `event_outbox` (publié depuis
   `EVENTS_RETENTION_DAYS`, 30 j par défaut) et `webhook_deliveries` (terminées depuis
   `WEBHOOK_RETENTION_DAYS`, 90 j). `replayed_from_id` passe en `ON DELETE SET NULL`.
9. **Horizon** : `config/horizon.php` avec deux superviseurs.
   `supervisor-default` traite `default` et `notifications` ;
   `supervisor-outbound` traite `payment-callbacks` et `webhooks`.

## Alternatives écartées

- **Re-valider l'URL à l'envoi sans épingler l'IP** : laisse une fenêtre de rebinding
  entre la vérification et la connexion.
- **`trustProxies(at: '**')`** : fait confiance à tout `X-Forwarded-For`. Ce n'est sûr
  que si le port de l'application n'est jamais exposé, une hypothèse trop fragile.

## Conséquences

- Un intégrateur qui enregistrait une URL `http://` reçoit désormais un `422`.
- Un endpoint qui répond par une redirection voit ses livraisons échouer. Le message
  d'erreur indique le statut `3xx`.
- Le déploiement doit lancer Horizon avec la nouvelle configuration, ce qui se fait
  automatiquement : `php artisan horizon` lit `config/horizon.php`.
