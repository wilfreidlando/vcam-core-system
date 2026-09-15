# ADR-0043 — Notifications : un endpoint par type de contenu, sans template

- **Statut** : Accepté
- **Date** : 2026-09-15
- **Précise** : ADR-0041 (Lot 5 — orchestration Notification), révise le point
  « templates versionnés » de son §4
- **Remplace** : le flux `POST /v1/notifications` basé template (ADR-0041 §4,
  spec §13) — table `notification_templates` supprimée

## Contexte

ADR-0041 avait livré `POST /v1/notifications` sur un modèle « template
pré-enregistré » : l'appelant référence un `template` existant
(`notification_templates`, versionné, `kind` template/session,
`template_status` approved/pending) plutôt que d'envoyer le contenu
directement. C'est le modèle qu'impose l'API officielle WhatsApp Business
Cloud (templates pré-approuvés par Meta).

En construisant l'adaptateur WAHA (ADR-0042), deux choses sont devenues
claires :

1. **WAHA n'exige aucun template pré-approuvé** — c'est une automatisation du
   client web WhatsApp, pas l'API Business officielle. La contrainte de
   pré-enregistrement n'apportait donc rien pour ce provider, juste de la
   friction (créer une ligne en base avant de pouvoir envoyer un message).
2. **Le contenu doit voyager dans la requête**, jamais dans un identifiant
   opaque — sans quoi aucun appelant ne peut envoyer un texte, une image ou un
   fichier ad hoc.

## Décision

### 1. Trois endpoints, un par type de contenu — plus de template

`POST /v1/notifications/text`, `POST /v1/notifications/image`,
`POST /v1/notifications/file` remplacent `POST /v1/notifications`. Chacun
prend le contenu directement dans le corps de la requête (`text`, ou
`media_url`/`caption`/`filename`/`mimetype`). `notification_templates` est
supprimée (migration `2026_09_15_000004`), ainsi que
`NotificationTemplateKind`/`NotificationTemplateStatus` et tout le port
`NotificationTemplateRepository`.

### 2. Le routage par `channel` reste strictement inchangé

C'est le point qui motivait la question posée avant cette décision : *si un
second provider WhatsApp ou un provider SMS apparaît demain, l'appelant
change-t-il d'endpoint ?* Non. Les trois endpoints prennent toujours un
`channel` (`whatsapp`, `sms`, …) dans le corps, résolu par
`NotificationProviderRouter`/`NotificationChannelProviderRegistry` exactement
comme avant (ADR-0041 §3) — l'endpoint ne nomme jamais un provider. Ajouter
un second provider WhatsApp (fallback) ou un canal SMS entier est un
changement de configuration (`notification_channels.php`) et d'adaptateur,
jamais un changement d'URL ni de contrat pour l'appelant.

### 3. Logique de fallback partagée — `NotificationSendCascade`

Les trois use cases (`SendTextNotificationUseCase`,
`SendImageNotificationUseCase`, `SendFileNotificationUseCase`) sont des
façades fines : chacune construit le `body` (`{"type": "text"|"image"|"file",
...}`) attendu par `OutboundMessage`, puis délègue à
`Core\Notification\Application\Support\NotificationSendCascade` — extraction
directe de l'ancien `SendNotificationUseCase` (création idempotente,
cascade de fallback, transaction sur l'échec total) sans la partie
« résoudre un template ». Ce n'est pas un port `Contracts/` : c'est une
collaboration Application-interne réutilisée par les trois façades.

### 4. `Notification.templateKey` devient `Notification.contentType`

La colonne `notifications.template_key` (renommée `content_type` par
migration) enregistre désormais `text`/`image`/`file` — utile pour l'audit,
plus une référence à un template. Le contenu réel (texte, URL média) n'est
jamais persisté par le Core, cohérent avec `OutboundMessage` qui ne
transporte le contenu qu'en mémoire, du contrôleur à l'adaptateur.

### 5. Documentation OpenAPI : regroupement par module, sous-groupé par provider

Effet de bord demandé séparément : les opérations des endpoints
spécifiques à un provider (session WAHA : QR/pairing-code/screenshot,
webhook) portent désormais deux tags (`Notification`, `Waha`) au lieu d'un
seul. Réalisé en déplaçant les contrôleurs concernés dans un sous-namespace
`Presentation\Http\Controllers\Waha\`, et en étendant le résolveur de tags de
`AppServiceProvider` pour ajouter un second tag quand un sous-répertoire
existe sous `Controllers\` — toujours dérivé de la structure de fichiers, pas
annoté à la main (gouvernance : préférer le structurel au manuel). Les
endpoints génériques (`send-text`, `send-image`, `send-file`, statut) restent
tagués seulement `Notification`, puisqu'ils ne dépendent d'aucun provider en
particulier.

## Conséquences

- Un futur provider qui exige des templates pré-approuvés (l'API Business
  WhatsApp officielle, par exemple) devra porter cette contrainte dans son
  propre adaptateur (refuser `body.type=text` avec un texte libre si aucun
  template ne le couvre, ou exposer sa propre validation) — le Core ne la
  réimpose plus au niveau de l'orchestration.
- Les tests d'intégration/unitaires du Lot 5 ont été réécrits contre les
  trois nouveaux endpoints ; `SendNotificationUseCaseTest` (template-based)
  est remplacé par `SendTextNotificationUseCaseTest` (couvre aussi la
  construction du body image/file).
- ADR-0041 reste la référence pour le reste de l'orchestration (fallback,
  `NotificationStatus`, `to_party_ref` comme corrélation) — seul son §4
  (templates) est remplacé par cet ADR.
