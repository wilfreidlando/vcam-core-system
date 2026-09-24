# ADR-0057 — Sessions WhatsApp : créées par l'admin, enregistrées dans une table du Core, obligatoires à l'envoi

- **Statut** : Accepté — remplace ADR-0042 §4 (le Core ne persiste rien sur une session ; repli sur une session par défaut)
- **Date** : 2026-09-24
- **Décideurs** : décision de l'utilisateur
- **Dérives concernées** : aucune (précise un contrat public, pas de nouvelle capacité)

## Contexte

Jusqu'ici, un envoi WhatsApp sans `variables.session` retombait sur une session par défaut commune à
toute la plateforme (`WAHA_DEFAULT_SESSION`) : un client qui n'avait rien lié envoyait depuis le numéro de
l'opérateur, sans le savoir. Par ailleurs les endpoints `qr` / `screenshot` / `pairing-code` **créaient**
implicitement la session chez le fournisseur : n'importe quel client pouvait en faire naître à volonté.

## Décision

1. **Un client ne crée jamais de session.** La création se fait dans la console d'administration
   (« Sessions WhatsApp », sur la fiche du projet ou au niveau plateforme) : elle appelle
   `startSession` chez le fournisseur (la session existe réellement et est démarrée) puis l'enregistre.
   À côté de chaque session : QR code, capture d'écran, code d'appairage.
2. **Le Core garde un registre** : table `notification_channel_sessions` (`project_id` nullable, `channel`,
   `session_ref`, `created_at`). Une session est **liée à un projet** (seul ce projet peut l'utiliser) **ou
   globale** (`project_id` NULL, utilisable par tous les projets — par exemple la session déjà liée de
   l'opérateur). Ne contient qu'un nom et son propriétaire, jamais l'état de la session.
3. **`variables.session` est obligatoire** pour `channel = "whatsapp"` (422 sinon ; lettres, chiffres, `-`, `_`,
   64 max). À l'envoi, la cascade vérifie **uniquement dans cette table** — jamais en interrogeant le fournisseur —
   que la session est enregistrée pour le projet, ou globale (la session propre du projet prime sur une
   globale de même nom). Sinon : `422 channel-session-not-found`, message explicite, **rien n'est envoyé ni
   écrit**.
4. La cascade remplace `variables.session` par le nom **côté fournisseur** (`<projectId>_<ref>` pour une
   session de projet, `<ref>` tel quel pour une globale) ; `WahaAdapter` l'utilise tel quel et n'a plus de repli
   (`MissingChannelSession` sans session).
5. Les endpoints clients `qr` / `screenshot` / `pairing-code` ne créent plus rien et ne fonctionnent que
   pour une session **propre au projet** déjà enregistrée (jamais une globale, jamais celle d'un autre projet).
6. `WAHA_DEFAULT_SESSION` est supprimé.
7. Nouvelle permission `channel-sessions:write` (`ProviderManager` ; `PlatformAdmin` via `*`).

## Pourquoi une table plutôt qu'interroger le fournisseur

Vérifier auprès du fournisseur ajoutait un appel réseau (~1 s mesuré) à chaque envoi, dépendait de ses
codes d'erreur (impossible de distinguer proprement « inexistante » d'une autre erreur 4xx) et rendait le
comportement différent d'un fournisseur à l'autre. Le registre est local, instantané (72 ms pour un refus),
et identique quel que soit le fournisseur.

## Conséquences

- **Changement de contrat** : un appelant qui n'envoyait pas `variables.session` reçoit un `422`.
  Le contrôle de compatibilité OpenAPI le signalera comme rupture ; c'est voulu.
- Les sessions créées avant cette table ne sont pas enregistrées : il faut les enregistrer (les recréer
  dans la console est idempotent) avant de pouvoir envoyer avec.
- **Supprimer une session** (console, avec confirmation) : supprimée chez le fournisseur **d'abord**
  (le téléphone lié est déconnecté ; une session déjà absente chez lui — 404 — compte comme faite), puis
  désenregistrée. Si le fournisseur refuse (panne, authentification), l'enregistrement reste : il ne doit
  jamais disparaître alors que la session vit encore chez le fournisseur.
- **Session enregistrée mais non liée** : le registre ne sait pas si un téléphone est lié, c'est le
  fournisseur qui refuse l'envoi. L'adaptateur traduit ce refus (WAHA : `422 « Session status is not as
  expected »`) en une erreur explicite (`ChannelSessionNotLinked`) : la notification finit `failed` avec un
  `error_message` clair (« not linked to a phone (waiting for the QR code to be scanned | disconnected)… »)
  au lieu de `HTTP 422 (permanent)`. Pour cela `ProviderException` porte désormais le corps de réponse
  du fournisseur (`responseBody`), jamais recopié dans le message.
- **Non traité** : l'état de liaison n'est pas affiché dans la liste de la console (il faudrait interroger le
  fournisseur à chaque affichage).
