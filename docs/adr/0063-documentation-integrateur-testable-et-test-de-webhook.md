# ADR-0063 — Documentation intégrateur testable, test d'un endpoint webhook, catalogue d'événements unique

- **Statut** : Accepté — complète ADR-0040 (webhooks sortants) et ADR-0061 (contrat minimal)
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (plan d'implémentation, phase 7 : « documentation fonctionnelle et testable par les intégrateurs »)
- **Dérives concernées** : D5 contrat

## Contexte

- Rien ne disait à un intégrateur comment vérifier la signature d'un webhook ; le
  guide d'architecture annonçait des en-têtes `X-Core-*` qui n'existent pas (audit
  2026-10-03, point 14).
- Pour vérifier son récepteur, un intégrateur devait attendre un vrai paiement.
- `POST /v1/webhook-endpoints` acceptait `notification.status_changed`, jamais émis,
  et refusait `notification.delivered`/`notification.read`, réellement émis : un
  intégrateur ne pouvait pas s'abonner à la remise d'un message.
- La réponse de création d'un paiement renvoyait `created_at: null`.

## Décision

1. **`POST /v1/webhook-endpoints/{id}/test`.**
   - Envoie un événement `webhook.test`, de façon synchrone, par le même
     `WebhookSender` qu'un vrai événement : même enveloppe, même signature, même garde
     SSRF.
   - Renvoie `{delivered, event_id, response_status, response_body, error,
     duration_ms}`.
   - Jamais retenté, jamais enregistré comme livraison.
   - Scope `webhooks:write`, limité à 10 appels par minute.
   - Endpoint d'un autre projet ou d'un autre environnement : `404`.
   - Ajout au contrat, non cassant (G9).
2. **Catalogue unique.**
   - `Core\Webhook\Contracts\WebhookEventTypes::ALL` est la seule liste des types
     émis.
   - La validation de l'abonnement et le schéma OpenAPI `WebhookEventType` la lisent
     tous les deux.
   - `notification.status_changed` est retiré (jamais émis ; D3 : `/v1` modifié
     directement). `notification.delivered` et `notification.read` sont ajoutés.
3. **Documentation testable.**
   - `docs/integration/webhooks.md` : réception, en-têtes, codes de réponse et nouvelles
     tentatives, catalogue et statuts finaux, vérification de signature en PHP, Node et
     Python, déduplication, ordre.
   - Les vérificateurs vivent dans `docs/integration/examples/` sous forme de fichiers
     exécutables. `WebhookTestAndSignatureExamplesTest` les exécute contre une requête
     réellement signée par le Core : signature valide, corps altéré, mauvais secret,
     horodatage périmé. Node et Python s'exécutent quand le binaire est présent.
   - `guide-curl-integrateur.md` est réécrit pour le contrat actuel : réponses
     minimales, `is_final`, deux clés par projet, simulateur sandbox.
   - Le guide d'architecture historique est corrigé et marqué comme non normatif.
4. **`created_at`** est relu après persistance dans la réponse de création.

## Conséquences

- Un abonnement existant à `notification.status_changed` (aucun connu) ne reçoit
  toujours rien, comme avant ; une nouvelle inscription avec ce type est refusée (`422`).
- La doc des signatures ne peut plus diverger du code sans casser la CI.
