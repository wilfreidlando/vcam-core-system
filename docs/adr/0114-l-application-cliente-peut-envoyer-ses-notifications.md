# ADR-0114 — L'application cliente peut envoyer ses notifications

- **Statut** : Accepté
- **Date** : 2026-11-01
- **Décideurs** : commanditaire (il a demandé le branchement réel d'une application cliente), comité d'architecture
- **Dérives concernées** : D5 contrat
- **Précise** : ADR-0107 §5 (le modèle de portées « Application cliente »), ADR-0041 (l'orchestration des notifications), ADR-0057 (les sessions de canal), ADR-0110 (le Core pousse le locataire)

## Contexte

Le premier **vrai** client du Core — une application de gestion d'auto-écoles, branchée
sur le paquet `core-adapter` — a révélé un trou du contrat.

Cette application prévient ses propres locataires : « votre séance de demain est
annulée », « votre document expire dans huit jours », « votre mensualité est due ».
C'est le premier geste de ce produit, celui qui se produit **tous les jours**. Et le
modèle de portées « Application cliente » d'ADR-0107 §5 ne portait aucune portée de
notification : `POST /v1/notifications/text` répondait `403`, et l'application ne
pouvait écrire **un seul message** à ses clients.

Le trou ne s'était pas vu plus tôt, parce que le contrat avait été écrit **du côté du
Core** : on avait décrit ce qu'un client *ne peut pas* faire (le commerce), et vérifié
mécaniquement cette limite — un test affirmait qu'aucune portée d'écriture sur le
commerce ne figurait dans le modèle. La borne était prouvée ; l'absence ne l'était pas.

C'est un trou de **sens**, pas de quantité : le modèle de portées est une promesse
faite au développeur qui branche son logiciel. Une promesse qui l'oblige à demander
une clé « Serveur complet » — donc à recevoir le pouvoir de faire le commerce — pour
envoyer un message, est une promesse rompue.

Le commerce et les messages sont deux choses différentes : le commerce déplace de
l'argent et crée des obligations ; un message **prévient** et ne crée rien. Les
confondre conduit à deux erreurs symétriques — interdire aux clients de parler à
leurs clients, ou leur laisser tout faire.

## Décision

**Décision 1 — le modèle « Application cliente » porte `notifications:write` et
`notifications:read`.**

Les huit portées du modèle deviennent :

```
customers:read · usage:read · usage:write · portal:write
subscriptions:read · invoices:read · notifications:write · notifications:read
```

La limite d'ADR-0107 §5 reste **inchangée** : aucune portée d'écriture sur le
commerce — ni `customers:write`, ni `subscriptions:write`, ni `invoices:write`, ni
`plans:write`. Le test qui prouve cette limite (`ClientApplicationScopeTest`) est
inchangé et continue de passer : `notifications:write` n'est pas une écriture sur le
commerce.

**Décision 2 — c'est le projet qui envoie ; l'application cliente écrit en son nom.**

Le message part **du projet** : c'est la clé du projet qui l'envoie, c'est la session du
projet qui l'emporte, et le projet en reste l'expéditeur. L'application cliente
emploie la clé de son projet ; elle n'a pas d'identité d'expéditeur propre, et le Core
ne connaît pas de « message d'application » : il connaît des messages **d'un
projet**.

L'application cliente écrit **ses** messages, **à ses** clients, avec **ses** mots :
elle ne facture rien, ne crée aucun abonnement, ne modifie aucun devis. Le Core
n'est qu'un **canal** : il vérifie la session, route vers le prestataire, trace la
livraison. La chaîne est inchangée — `POST /v1/notifications/text` → cascade →
adaptateur (WAHA) → `notification_deliveries`.

**Décision 3 — la session est obligatoire : sans elle, rien n'est envoyé.**

La session est nommée par l'application (`variables.session`), mais elle est
**enregistrée par l'opérateur**, depuis la console (ADR-0057). Trois refus, et aucun
d'eux n'envoie quoi que ce soit :

| Cas | Réponse | Effet |
|---|---|---|
| aucune session nommée | `422` | rien n'est écrit, rien n'est envoyé |
| session inconnue ou enregistrée pour un autre projet | `422 channel-session-not-found` | rien n'est écrit, rien n'est envoyé |
| session enregistrée mais non liée à un téléphone | `201` avec `status: failed` | le message n'est pas parti, et l'application le voit |

Une notification **sans session** n'est pas une notification : c'est une lettre sans
adresse. Le Core ne choisit jamais une session à la place de l'appelant — il ne
connaît pas le numéro du destinataire, et la session porte l'identité de
l'expéditeur.

**Décision 4 — le contrat déclare la nouvelle surface, donc la dérive est attrapée.**

La fixture de contrat (`ClientApplicationContractFixture::SCOPES`) porte les deux
portées, et son test vérifie la liste **exacte** du modèle. Une évolution future du
modèle qui oublierait `notifications:write` — ou qui en ajouterait une de trop —
fait échouer la CI du Core le jour même. La documentation d'intégration
(`docs/integration/brancher-une-application-cliente.md`) montre la liste, l'exemple
de `whoami` et le récapitulatif : le développeur voit ce qu'il obtient.

**Décision 5 — la portée `notifications:read` suit `notifications:write`.**

Écrire un message sans pouvoir le suivre est un cul-de-sac : l'application ne peut
alors ni savoir si le message est parti, ni le rattacher à son propre journal
(`GET /v1/notifications/{id}`). La lecture est **limitée aux notifications du
projet** : elle ne divulgue rien d'un autre projet.

## Le parcours que la décision rend possible

C'est le flux que le commanditaire a demandé de voir fonctionner, de bout en bout :

```
   Console Core                        Application cliente
        │                                    │
        │  l'opérateur déclare un locataire │  (external_ref = le code
        │  (external_ref)                    │   de l'auto-école)
        ▼                                    ▼
   PUT /v1/customers/{ref}  ──► event customer.created ──►  relais de l'outbox
                                        (ADR-0032)
                                              │
                                              ▼
                                   POST /webhooks/core  (signature HMAC)
                                              │
                                              ▼
                                   l'application lie son locataire
                                   (RelierLocataireCore)
                                              │
   l'auto-école annule une séance ────────────┘
        │
        ▼
   POST /v1/notifications/text   (notifications:write)
        │  variables.session = la session déclarée par l'opérateur
        │  variables.phone_number = le numéro du locataire
        ▼
   Core ──► WAHA ──► WhatsApp
```

Chaque maillon est vérifiable séparément : l'opérateur voit le client créer dans la
console ; l'application reçoit l'événement **signé** et refuse une signature
invalide ; le message part **depuis la session** nommée, et seulement si elle est
liée à un téléphone.

## Alternatives écartées

**Laisser le client demander une clé « Serveur complet ».** — Rejeté : c'est
réserver le pouvoir de faire le commerce au prix du droit d'envoyer un message.
L'utilisateur de l'API ne verrait plus ce qu'il peut faire ; il verrait ce qu'il a le
droit de faire.

**Créer un modèle « Application cliente + notifications ».** — Rejeté : le modèle
« Application cliente » est une **promesse de complétude**. Le premier client réel
a échoué pour une portée manquante ; un second modèle, lui, aurait été un second
endroit où oublier une portée. Un modèle, complet, et un test qui le vérifie.

**Laisser l'application envoyer ses messages par son propre prestataire.** —
Rejeté : deux chemins WhatsApp, deux registres de sessions, deux jeux de secrets,
deux historiques de livraison. Le Core est déjà la passerelle (ADR-0041, ADR-0057) ;
un second chemin aurait été la dette, pas la liberté.

**Donner à l'application cliente la portée `*`.** — Rejeté : c'est l'inverse exact
de la décision 1 — tout lui rendre pour qu'il puisse tout faire, commerce compris.

**Écrire dans le commerce par l'intermédiaire des notifications.** — Rejeté : c'est
une porte de contournement. `notifications:write` permet d'écrire du **texte** ; le
texte n'encaisse rien, ne crée aucun droit, n'émet aucune facture. C'est vérifiable
mécaniquement : la portée n'ouvre aucun endpoint du commerce.

**Laisser le Core choisir la session quand l'application n'en nomme pas.** — Rejeté :
c'est la décision 3. Une session est l'identité de l'expéditeur ; en choisir une à la
place de l'appelant reviendrait à écrire des messages au nom d'une auto-école qui n'a
rien demandé. S'il n'y a pas de session, il n'y a pas de message.

## Conséquences

**Ce qui est gagné.** — Le premier geste d'un produit de notification (une séance
annulée, un document qui expire) est possible **le premier jour**, avec une clé
« Application cliente », sans que le développeur ait à demander le pouvoir de faire
le commerce. La branche `notifications:*` du contrat public est désormais utilisable
par le paquet `core-adapter` sans être réadaptée.

**Ce qui est engagé.**

- Le modèle de portées n'est plus seulement une **borne** : c'est aussi une
  **surface**. Toute portée ajoutée au modèle « Application cliente » doit
  accompagner un test de contrat et une ligne de documentation. La CI le vérifie
  pour la liste exacte ; elle ne vérifie pas la justification — c'est le rôle de cet
  ADR.
- Le paquet `core-adapter` expose maintenant un service `Notifications` pleinement
  utilisable : le contrat du paquet et celui du Core ne divergent plus.
- Un client application **peut** écrire des messages à ses clients. C'est une
  capacité réelle, donc une responsabilité : le message part avec le nom du projet
  et de l'application comme expéditeur. La portée reste la plus étroite possible
  pour ce besoin — un texte, une image, un fichier, rien d'autre.

**Point de réévaluation.** — Si un jour une application cliente doit envoyer des
messages **en son propre nom** (marque, ton commercial) et non au nom du projet,
la question du modèle de portée ne se posera plus de la même façon : le nom
d'expéditeur du message devra être une donnée du projet, et non une décision de
l'application. Ce jour-là, ce sera un nouvel ADR, pas une modification de celui-ci.
