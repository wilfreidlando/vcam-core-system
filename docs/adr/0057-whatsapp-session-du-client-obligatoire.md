# ADR-0057 — Envoi WhatsApp : la session du client est obligatoire, plus de session par défaut partagée

- **Statut** : Accepté — remplace la règle de repli de l'ADR-0042 §4 (« sinon `extras.default_session`, sinon `default` »)
- **Date** : 2026-09-24
- **Décideurs** : décision de l'utilisateur
- **Dérives concernées** : aucune (précise un contrat public, pas de nouvelle capacité)

## Contexte

Le Core expose des endpoints de session (`/v1/notification-channel-sessions/whatsapp/{sessionRef}/{qr,screenshot,pairing-code}`)
pour que chaque client intégrateur crée et lie **son propre** compte WhatsApp. Mais l'envoi
(`POST /v1/notifications/{text,image,file}`) retombait, quand `variables.session` était absent,
sur une session par défaut commune à toute la plateforme (`WAHA_DEFAULT_SESSION`, `default`). Un
client qui n'avait rien lié envoyait donc depuis le numéro de l'opérateur de la plateforme, sans
le savoir — et sans que le contrat public dise que `variables.session` existait.

Constaté en production : un envoi sans session était « réussi » (`sent`) depuis ce numéro partagé.

## Décision

- `variables.session` est **obligatoire** pour `channel = "whatsapp"` sur les trois endpoints
  d'envoi : `required_if:channel,whatsapp`, lettres/chiffres/`-`/`_`, 64 caractères au plus. Son
  absence est un `422`, pas un envoi.
- C'est le même `sessionRef` que celui des endpoints de session : le client lie sa session, puis
  envoie avec son nom. La valeur est toujours préfixée par le `projectId` avant d'atteindre WAHA
  (`<projectId>_<session>`, ADR-0051), donc un Project ne peut pas utiliser la session d'un autre.
- `WahaAdapter::send()` n'a plus aucun repli : sans session il lève `MissingChannelSession`
  (défense en profondeur si un autre chemin atteint l'adaptateur sans passer par la validation HTTP).
- `WAHA_DEFAULT_SESSION` / `extras.default_session` sont supprimés de la configuration et des
  modèles d'environnement.

## Conséquences

- **Changement de contrat** : un appelant qui n'envoyait pas `variables.session` reçoit désormais
  un `422`. Le contrôle de compatibilité OpenAPI (oasdiff) le signalera comme rupture ; c'est voulu.
- Un message ne peut plus partir d'un numéro que le Project appelant n'a pas lié lui-même.
- La session par défaut de l'instance WAHA reste utilisable directement chez WAHA, mais plus via
  le Core.
- **Non traité ici** : la validation ne vérifie pas que la session existe ni qu'elle est liée. Une
  session inconnue ou non liée donne `201` avec `status: failed` et `error_message` peu explicite
  (`HTTP 422 (permanent)`) alors que WAHA indique `Session status is not as expected … expected
  WORKING`. Remonter cette raison au client reste à faire.
