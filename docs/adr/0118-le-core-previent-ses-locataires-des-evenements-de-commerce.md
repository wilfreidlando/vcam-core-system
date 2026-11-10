# ADR-0118 — Le Core prévient ses locataires des événements de commerce

- **Statut** : Accepté, amendé par ADR-0120 (§6)
- **Date** : 2026-11-01
- **Décideurs** : commanditaire (il a demandé que le Core prévienne les clients des rappels d'abonnement), comité d'architecture
- **Dérives concernées** : D1 revenu, D5 contrat
- **Précise** : ADR-0037 (pas de composition implicite entre modules), ADR-0041 §5 (`to_party_ref` n'est jamais une adresse), ADR-0056 (idempotence par clé), ADR-0057 §2 (les sessions), ADR-0070 §3 (aucune donnée personnelle dans un événement), ADR-0072 §9 et ADR-0074 §2 (les charges utiles publiques), ADR-0100 (la connexion directe par code), ADR-0114 (l'application cliente écrit ses messages)
- **Amende** : CLAUDE.md (§ notification — « Rien ne déclenche une notification automatiquement »)

## Contexte

Le Core tient le commerce de ses clients : les clients d'un projet, leurs abonnements,
leurs factures, leurs quotas (ADR-0070, ADR-0072, ADR-0073, ADR-0074). Il produit
les événements qui décrivent cette vie — `subscription.renewal_due`,
`subscription.trial_will_end`, `subscription.past_due`, `subscription.expired`,
`usage.threshold_reached`, `usage.limit_reached`, `invoice.issued`,
`invoice.payment_failed` — et il les écrit dans l'outbox transactionnelle (G8).
Le relais les republie, et deux modules les consomment : `webhook` (fan-out vers les
endpoints du projet) et `payment`/`billing` (imputation d'un règlement).

**Personne ne les consomme côté message.** Un locataire dont l'abonnement se
renouvelle dans trois jours n'en sait rien : le projet le sait (par webhook),
le Core le sait (par l'outbox), le locataire, non. C'est le trou que le
commanditaire a nommé : « le Core doit pouvoir notifier les clients en ce qui
concerne l'abonnement, rappel, etc. ».

Trois choses étaient vraies avant cette décision, et toutes trois comptent :

1. **Le module `notification` ne déclenche rien.** Ses endpoints `send-*` sont la
   seule porte d'entrée ; aucun garde-fou ne déclenche une notification, même en
   réaction à un `payment.status_changed` (CLAUDE.md, ADR-0037 : pas de
   composition implicite entre modules). L'affirmation est vraie, et elle devient
   fausse ici — d'où cet ADR.
2. **Aucun module ne peut appeler `notification` depuis le commerce.** `billing`
   ne connaît pas `notification` ; `notification` connaît `billing` (ADR-0074 §5 :
   le quota, via le port `QuotaGuard`). L'arête inverse fermerait un cycle.
3. **Les messages du Core ont une voix.** Un message écrit par le Core n'est pas
   le message du projet : il porte le nom du Core, et il part de la session du
   Core — la session **globale** nommée `default`, celle que l'opérateur a
   enregistrée pour la plateforme (`project_id` NULL, ADR-0057 §2). C'est le seul
   usage de `default` : le client qui nomme une session dans sa requête ne
   retombe **jamais** sur `default` (ADR-0114 §3 : une session nommée par
   l'appelant est celle du projet, ou une globale du même nom — jamais
   une autre, jamais une création implicite). Cette décision ne touche pas à
   ce chemin : il reste exactement ce qu'il est.

## Décision

**Décision 1 — le Core écrit à ses locataires, pas aux projets.**

Le destinataire est le **client final du projet** — celui dont l'abonnement se
renouvelle, dont la facture est impayée, dont le quota est atteint. Ce n'est pas
le contact du projet : le projet n'a pas de numéro dans le Core, et l'événement
ne parle que de *ses* clients (`customer_ref` dans la charge utile).

Le message part **de la voix du Core** : la session globale `default`, pas la
session du projet. C'est cohérent avec ADR-0107 §5 (le Core est le maître de la
relation commerciale : c'est lui qui tient l'abonnement et la facture, donc
c'est lui qui en prévient) et avec ADR-0116 (le contenu d'un message du Core
appartient à l'acte du Core, pas au catalogue du module `notification`).

**Décision 2 — la voix du Core est une capacité de `notification`, pas une
fonction de `billing`.**

Le module `notification` gagne un contrat public, `SendSystemNotification` :
« envoie ce texte à ce numéro, depuis la session de la plateforme ». Ce contrat
ne connaît ni l'abonnement, ni la facture, ni le quota : il ne connaît que le
transport. Trois règles lui sont propres :

- **la session est résolue en global, jamais en projet.** Le nom vient d'une
  clé de configuration dédiée, `notification.system_session`, dont la valeur
  par défaut reste `default` ; la résolution est un **appartenement exact**
  (`find(null, canal, nom)`), jamais `findUsableFor`. Un projet qui aurait
  enregistré une session nommée `default` ne peut donc pas se substituer à la
  plateforme ;
- **la session n'est jamais créée.** Si la session globale `default` n'existe
  pas côté plateforme, **rien n'est envoyé** : aucune ligne, aucun appel au
  prestataire ;
- **le message du Core n'est pas décompté.** Il ne consomme pas de quota du
  locataire (voir la décision 5).

Le chemin client (`POST /v1/notifications/text`) n'est **pas** modifié : il
résout toujours la session nommée par l'appelant, projet d'abord puis globale
du même nom, sans repli, et `422 channel-session-not-found` reste la réponse
quand elle n'existe pas.

**Décision 3 — la composition vit dans la coquille applicative, et le graphe
des modules ne change pas.**

Aucune arête nouvelle n'est créée. `tests/Architecture/ModuleGraph.php`,
`deptrac-modules.yaml` et les `composer.json` des paquets restent inchangés.

La composition — « cet événement est-il notifiable ? pour ce client ? avec
quel texte ? » — est dans `app/`, la racine de composition : un écouteur de
l'outbox, un rendu de message, une lecture du numéro du client. C'est
exactement ce que fait déjà la console : `ProjectCommerceController` compose
`billing`, `portal`, `identity` et `audit` à travers leurs `Contracts/`. La
composition explicite entre modules a une place dans ce dépôt ; c'est la
coquille.

L'arête `billing → notification` a été écartée : elle fermerait un cycle avec
`notification → billing` (ADR-0074 §5), que le graphe interdit — ADR-0100
l'écrit déjà en clair (« `portal → notification`, `notification → billing` :
pas de cycle »). L'arête `notification → billing` qui existe déjà (le quota)
suffit : c'est par elle que le Core lit le numéro du client, par
`Core\Billing\Contracts\GetCustomer`.

*Risque assumé* : `app/` n'est ni dans les chemins de Deptrac (`./packages`
seulement) ni dans la règle PHPat « un module n'atteint un autre module que par
ses `Contracts/` » (qui ne porte que sur `Core\<module>`). La composition
déposée là n'est donc pas gardée par la mécanique. Elle n'utilise que des
`Contracts/`, et le présent ADR le dit ; c'est le point de réévaluation
ci-dessous.

**Décision 4 — le projet declare, événement par événement.**

Un projet choisit, dans sa console, les événements de commerce qu'il veut voir
prévenir ses clients. Le réglage est **opt-in** : sans déclaration, rien ne
part. Il porte sur un couple (événement, canal) ; le canal disponible
aujourd'hui est WhatsApp seul (ADR-0099 §4), et il est donc écrit dans le
réglage pour que la dimension existe quand un second canal existera.

Le réglage vit dans le module `notification` (`notification_system_settings`),
et se lit et s'écrit par un contrat de `Contracts/`
(`ManageSystemNotificationSettings`) : la console écrit par là, la composition
lit par là. Le module `notification` ne connaît aucun événement de commerce :
il range des chaînes et des canaux, et ne les interprète pas.

**Décision 5 — le message du Core n'est pas décompté, et il ne se répète pas.**

Le quota (`notification.whatsapp`) mesure ce qu'un **projet** envoie à **ses**
clients. Le message du Core n'est pas un service que le locataire achète :
c'est le Core qui l'informe de son propre état commercial. Le décompter
againstre le plan du locataire aurait un effet perverse : le message
« votre abonnement est arrivé à échéance » ne partirait plus précisément
quand l'abonnement n'est plus actif — c'est-à-dire quand il compte le plus.
Le message du Core est donc **hors quota**, et son volume est borné par la
règle de non-duplication ci-dessous.

**Décision 6 — une notification par événement, par client, par fenêtre.**

La clé d'idempotence du message est déterministe :
`core-notice:{type d'événement}:{client}:{fenêtre}`. La fenêtre est la période
qui finit (abonnement), la fenêtre de comptage (usage) ou la facture
(invoice). La table `notifications` porte `unique(project_id, idempotency_key)`
(ADR-0056) : un événement rejoué — le relais republie en *au moins une fois* —
retrouve la notification existante au lieu d'en envoyer une seconde. Un
locataire ne reçoit donc pas deux fois le même rappel.

**Décision 7 — l'absence ne casse rien.**

Trois cas, une seule règle : **rien n'est envoyé, et rien ne échoue.**

| Cas | Effet |
|---|---|
| le projet n'a pas déclaré l'événement | rien n'est envoyé |
| le client n'a pas de numéro connu | rien n'est envoyé |
| la session globale `default` n'existe pas | rien n'est envoyé, rien n'est écrit |
| le prestataire refuse le message | la notification passe `failed`, la raison est tracée |

L'événement métier est **déjà acquis** dans l'outbox au moment où le message
est composé : l'échec de la notification ne peut pas, et ne doit pas, faire
échouer le traitement de l'événement. Lelistener est `ShouldQueue` : il ne
lève rien, il journalise la raison explicite (`core_notice.skipped`) et
rend la main.

## Alternatives écartées

**Laisser le projet notifier lui-même.** — Rejeté : c'est déjà possible
(ADR-0114, `notifications:write`), mais cela suppose que l'application cliente
soit branchée, qu'elle ait le numéro, et qu'elle sache formuler le message.
Le Core, qui tient l'abonnement et la facture, sait le dire — et le demandeur
a demandé que ce soit le Core.

**Écrire à l'appelant de l'API, ou au contact du projet.** — Rejeté : le projet
n'a pas de numéro dans le Core, et l'événement ne parle pas de lui mais de ses
clients.

**Faire porter l'arête `billing → notification`.** — Rejeté : cycle avec
`notification → billing` (ADR-0074 §5). Le graphe des modules est un
arbre orienté, pas un méta-graphe.

**Loger le message dans le module `notification`.** — Rejeté : `notification`
apprendrait le vocabulaire du commerce — abonnement, facture, quota — et
deviendrait spécifique (porte transverse : « aucune règle métier
spécifique »). Le commerce reste dans `billing`, la composition dans la
coquille, le transport dans `notification`.

**Décompter le message du Core contre le quota du locataire.** — Rejeté : voir
la décision 5 — le message le plus urgent est précisément celui que le quota
refuserait.

**Notifier par la session du projet.** — Rejeté : ce serait écrire au nom du
projet, sans qu'il l'ait demandé, alors que le Core parle en son nom. La
session du projet reste la sienne (ADR-0114 §2).

**Notifier sans opt-in, ou notifier à chaque republication de l'outbox.** —
Rejeté : le relais republie en *au moins une fois* ; sans opt-in ni clé
d'idempotence, le locataire recevrait le même rappel plusieurs fois.

## Conséquences

**Ce qui est gagné.** — Le Core prévient enfin ses locataires : le rappel de
renouvellement, la fin d'essai, l'abonnement impayé ou échu, le seuil et la
limite de quota, la facture émise et la facture impayée. Le projet choisit ce
qu'il veut voir notifier, dans sa console, à côté de ses réglages de commerce.

**Ce qui est engagé.**

- Le module `notification` porte deux contrats de plus (`SendSystemNotification`,
  `ManageSystemNotificationSettings`) et une table (`notification_system_settings`).
  Sa cascade d'envoi accepte une session déjà résolue et l'exclusion du quota —
  les deux chemins publics sont inchangés, mais la cascade a grandi.
- `CLAUDE.md` ne peut plus dire « Rien ne déclenche une notification
  automatiquement » : c'est faux depuis cette décision. La phrase est
  remplacée par le fait exact : les endpoints `send-*` restent la seule porte
  d'entrée *pour les messages d'un projet*, et les messages du Core sont une
  exception **opt-in**, branchée sur l'outbox.
- La composition vit dans `app/`, hors du champ de Deptrac et de la règle
  PHPat « Contracts seulement ». C'est le point faible assumé de la décision 3.

**Point de réévaluation.** — Si la composition du noyau doit devenir une
capacité à part entière — plusieurs canaux, plusieurs voix, plusieurs
destinataires par événement — elle quittera la coquille pour un module
dédié, avec ses arêtes déclarées (`core-notice → events, billing,
notification`). Ce jour-là, la règle PHPat la gardera, et l'arête
`billing → notification` restera hors de question tant que
`notification → billing` existe. De même, si un jour le Core doit écrire au
locataire **au nom du projet**, la session du projet redeviendra la seule
admise, et ce sera un autre ADR.
