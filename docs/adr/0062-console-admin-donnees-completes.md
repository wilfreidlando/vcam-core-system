# ADR-0062 — Console admin : toutes les données dans les fiches de détail, environnement visible partout

- **Statut** : Accepté — complète ADR-0045 (listing générique) et ADR-0059 (parcours d'un paiement)
- **Date** : 2026-10-03
- **Décideurs** : utilisateur (plan d'implémentation, phase 6 ; décision D5 : PII visibles aux opérateurs autorisés, rétention 90 jours)
- **Dérives concernées** : D7 exploitabilité

## Contexte

- Les fiches de la console n'affichaient qu'une partie des données : la fiche paiement
  ignorait le provider, la référence provider, l'URL de retour, le wallet destinataire
  et les dates ; les notifications n'avaient aucune page dans la console. Une livraison
  webhook n'avait pas de page propre.
- Le contenu envoyé dans une notification n'était pas conservé (seul `content_type`
  l'était) : impossible de répondre à « qu'a-t-on envoyé à ce client ? ».
- Depuis ADR-0060, chaque ressource appartient à un environnement (`live`/`sandbox`),
  mais la console ne le montrait ni ne permettait de filtrer dessus.

## Décision

1. **Notifications : contenu et tentatives conservés.**
   - `notifications.content` et `notifications.variables` (jsonb) gardent ce que
     l'intégrateur a envoyé.
   - `notification_deliveries.error` garde l'erreur de chaque tentative de la cascade.
   - Exposé par un nouveau port `Contracts\GetNotificationDetails` → `NotificationDetailView`.
2. **Rétention (D5).**
   - Le contenu et les variables (numéro de téléphone du destinataire) restent visibles
     aux opérateurs qui portent `notifications:read` pendant
     `notification.content_retention_days` (90 jours par défaut).
   - Passé ce délai, `notification:redact-expired` (planifiée chaque jour) les vide.
     La ligne elle-même (statut, tentatives, idempotence) est conservée.
3. **Pages de la console.**
   - `/admin/projects/{id}/notifications` : liste filtrable par statut et environnement.
   - `…/notifications/{notificationId}` : toutes les données — contenu, variables,
     référence de l'intégrateur, provider, ID du message, erreur, et chaque tentative.
     Une notification d'un autre projet répond 404.
   - `/admin/webhook-deliveries/{id}` : fiche d'une livraison, avec toutes ses tentatives
     (requête, réponse, durée) et le rejeu.
   - Fiche paiement : tous les champs de `PaymentView`, plus le badge « final » ou
     « intermédiaire » (ADR-0061).
4. **Environnement visible partout.**
   - Badges `<x-admin.env-badge>` (LIVE en rouge, SANDBOX en ambre) et
     `<x-admin.status-badge>`.
   - Filtre d'environnement sur les listes de paiements et de notifications
     (`ListPaymentsCommand`/`ListNotificationsCommand::$environment`, chaîne brute —
     G3c).
   - Sélecteur d'environnement à la création d'un endpoint webhook dans la console :
     par défaut `live`, comme l'API.

## Conséquences

- Les notifications antérieures à cette migration n'ont pas de contenu conservé ; la
  fiche l'indique.
- Les ports de lecture restent un par module (G3c) : la console ne lit aucune table
  directement.
