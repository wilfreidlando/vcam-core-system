# ADR-0115 — Les cibles privées sont ouvertes en local seulement

- **Statut** : Accepté
- **Date** : 2026-11-01
- **Décideurs** : commanditaire (il a demandé de voir le flux complet Core → application cliente fonctionner), comité d'architecture
- **Dérives concernées** : D3 données · D5 contrat
- **Amende** : ADR-0058 (le garde-fou SSRF : hôte re-résolu et re-vérifié à chaque envoi, connexion épinglée)

## Contexte

Le premier client réel du Core — une application de gestion d'auto-écoles, branchée
sur le paquet `core-adapter` — a buté sur le garde-fou d'ADR-0058.

L'application cliente s'inscrit dans le réseau Docker du développeur ; le Core y
resout son nom (`api`) vers une adresse privée (`172.x`). Le garde-fou, lui, refuse
toute destination qui ne soit pas **publiquement routable** : c'est la protection
contre le SSRF, et elle est bonne. Mais elle rend le parcours **impossible à
faire fonctionner en local** : impossible d'enregistrer le point d'entrée de
l'application, impossible donc de voir le Core pousser `customer.created`, impossible
de vérifier la signature, impossible de prouver la boucle complète.

Le résultat est une plateforme dont on ne peut pas **sortir** le **premier** trajet
sans un nom de domaine public et un tunnel — c'est-à-dire une plateforme que
personne ne développe contre, et dont le premier client réel a été découvert par
l'accident.

Le garde-fou n'a pas été affaibli : il a été **paramétré**, avec une limite que le
code lui-même vérifie.

## Décision

**Décision 1 — `OUTBOUND_ALLOW_PRIVATE_TARGETS` ouvre les plages privées, en local
seulement.**

La configuration `integration.outbound.allow_private_targets` (par défaut **false**)
autorise les plages privées RFC 1918 (`10/8`, `172.16/12`, `192.168/16`) et les
adresses IPv6 uniques locales (`fc00::/7`) comme destination d'un point d'entrée ou
d'un `callback_url`.

**Décision 2 — le refus est dans le code, au démarrage, pas dans la configuration.**

`IntegrationServiceProvider::register()` lève `PrivateTargetsForbidden` si le
drapeau est actif et que l'environnement n'est pas `local`. Aucune variable
d'environnement, seule, ne peut ouvrir les plages privées sur un déploiement qui
fait face à Internet : c'est la même règle que le contournement MFA
(`MfaBypassForbidden`, ADR-0081 S-05) — le drapeau peut être posé par erreur, il
ne peut pas ouvrir la porte.

**Décision 3 — le loopback et l'adresse de métadonnées restent refusés, partout.**

`127.0.0.0/8`, `::1`, `169.254.0.0/16` (où vit `169.254.169.254`, l'adresse de
métadonnées du cloud) et les plages réservées (CGNAT `100.64/10`, benchmarking
`198.18/15`, `192.0.0.0/24`) sont refusées **même en local**. `localhost` et
`*.local` sont refusés avant même toute résolution DNS.

La raison est simple : en local, le Core n'a aucune raison d'appeler le service de
métadonnées, et `localhost` désigne le Core lui-même — jamais l'application cliente,
qui est une autre machine (ou un autre conteneur). Le drapeau ouvre les plages
privées ; il n'ouvre pas les adresses réservées.

**Décision 4 — la règle de validation et le contrôle à la connexion obéissent au
même drapeau.**

L'URL est vérifiée deux fois : à la validation (`SafeOutboundUrlRule`) et à chaque
envoi (`GuardedHttpTransport`, qui épingle la connexion sur l'adresse vérifiée pour
fermer la fenêtre de rebinding DNS, ADR-0058). Les deux passent par le même
`OutboundUrlGuard`, construit avec le drapeau : le garde-fou est **immuable**
(`withPrivateTargets()` en renvoie une copie), et une URL acceptée à la validation
ne peut pas être refusée à l'envoi — ni l'inverse.

**Décision 5 — `http://` reste refusé par défaut, et le reste aussi.**

Le schéma `http://` exige `OUTBOUND_ALLOW_INSECURE_HTTP=true`, un drapeau distinct,
déjà prévu par l'audit du 2026-10-03 §2.3. Les deux drapeaux sont **indépendants** :
l'un ouvre les adresses privées, l'autre le texte en clair. Un déploiement local
veut généralement les deux ; un déploiement public n'a ni l'un ni l'autre.

## Alternatives écartées

**Affaiblir le garde-fou partout.** — Rejeté : c'est la décision 2 qui rend la
décision 1 acceptable. Un garde-fou qu'un fichier d'environnement suffit à
désactiver n'est pas un garde-fou.

**Supprimer le garde-fou des points d'entrée.** — Rejeté : ADR-0058 a raison. Un
point d'entrée est réessayé pendant des heures et rejoué depuis la file d'attente ;
une adresse interne d'aujourd'hui peut devenir un canal d'exfiltration demain.

**N'accepter les cibles privées que depuis la console.** — Rejeté, pour une raison
technique : l'API `POST /v1/webhook-endpoints` et la console partagent la même
règle (`SafeOutboundUrlRule`), et le garde-fou est aussi re-vérifié à la connexion,
par le transport. Scinder les deux chemins obligerait à maintenir deux politiques —
et le contrôle à la connexion, lui, ne distingue pas l'origine de l'appel.

**Exiger un tunnel ou un nom public même en local.** — Rejeté : cela rend le
parcours impossible à démontrer, donc impossible à développer contre. Le tunnel
serait lui-même une dépendance externe, un binaire téléchargé, et une surface
d'exposition du poste du développeur.

**Un drapeau par plage.** — Rejeté : la granularité utile est « le réseau local
est accessible », pas « la plage 172.16/12 mais pas 192.168/16 ». Une liste de
plages autorisées serait une configuration que personne ne lirait.

## Conséquences

**Ce qui est gagné.** — Le parcours complet — le Core crée un locataire, le pousse
vers l'application cliente, l'application accuse réception de la signature et
envoie un message — se déroule **en local**, sur la machine du développeur. C'est
la condition pour que le premier client réel soit développable.

**Ce qui est engagé.**

- Le noyau partagé porte un réglage de plus, et une exception de plus
  (`PrivateTargetsForbidden`). Le noyau reste pur PHP : le drapeau est lu par le
  `ServiceProvider`, jamais par le garde-fou lui-même.
- Le garde-fou a une méthode de plus (`withPrivateTargets()`) et un constructeur
  plus long. Les tests le couvrent par cas : plages privées acceptées en local,
  loopback et métadonnées refusées **quand même**, copie immuable.
- `phpunit.xml` épingle `OUTBOUND_ALLOW_PRIVATE_TARGETS=false` : le `.env` d'un
  développeur ne change pas ce que la suite prouve.

**Point de réévaluation.** — Si un jour un déploiement doit joindre une application
cliente **sur le même hôte** sans passer par un nom public (un client auto-école
dédié à une seule organisation, par exemple), ce ne sera pas ce drapeau qu'il
faudra élargir : ce sera la décision sur **qui** peut enregistrer un point d'entrée
sur une adresse privée — l'opérateur de la console, oui ; une clé de projet, non.
Cette décision-là n'est pas prise ici.
