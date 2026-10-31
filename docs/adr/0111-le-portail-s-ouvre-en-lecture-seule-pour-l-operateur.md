# ADR-0111 — Le portail s'ouvre en lecture seule pour l'opérateur

- **Statut** : Accepté
- **Date** : 2026-10-31
- **Décideurs** : commanditaire (compléter la fiche client), comité d'architecture
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Précise** : ADR-0075 (le portail), ADR-0097 (un appel pour ouvrir le portail)

## Contexte

Le portail est l'espace que le Core héberge pour le client d'un projet. On y gère son
abonnement, son offre, ses factures, ses paiements, son usage. Il s'ouvre par
`POST /v1/portal/sessions`, qui accepte deux flux :

- `manage` (par défaut) — le client gère son abonnement, change d'offre, renouvelle, paie ;
- `pay_invoice` — direct sur une facture.

Les deux flux **autorisent à agir** au nom du client. C'est la bonne chose pour le
client lui-même. C'est une **délégation de pouvoir** pour quiconque ouvre la session à
sa place.

L'opérateur de la console a pourtant un besoin légitime : **voir ce que voit le client**
— pour le support, ou pour vérifier une configuration (« le client dit qu'il ne voit
pas sa facture »). Aujourd'hui, il ne peut pas le faire sans **agir** : ouvrir une
session `manage` pour regarder, c'est prendre la main sur le compte du client.

## Décision

**Décision 1 — un troisième flux : `read`.**

`POST /v1/portal/sessions` accepte `flow=read`. La session s'ouvre sur les **mêmes
pages**, avec les **mêmes données**, mais **sans aucune capacité d'agir** :
pas s'abonner, pas annuler, pas changer d'offre, pas renouveler, pas payer.

**Décision 2 — le refus vient du code qui agit, pas de la vue.**

Cacher un bouton n'est pas une garantie : un POST façonné à la main contournerait
l'interface. Chaque geste mutateur du portail — `subscribe`, `cancel`, `changePlan`,
`keepRenewing`, `renew`, `pay` — **refuse** une session en lecture seule. Le refus est
clair (une page qui explique), jamais une exception, jamais un faux succès.

**Décision 3 — le flux est porté par la session, il est déjà persisté.**

`portal_sessions.flow` existe déjà (ADR-0075) : le flux `read` ne demande aucune
migration. L'espace de travail du portail le reçoit par la même voie que les deux
autres, et les vues s'appuient dessus pour masquer les actions — **en plus** du refus
côté code.

**Décision 4 — l'ouverture reste auditée.**

Une session de portail est déjà un acte audité (`portal.session_opened`, avec l'IP de
l'appelant et le flux). Un opérateur qui ouvre l'espace d'un client laisse donc une
trace : qui, quand, et en lecture seule.

**Décision 5 — l'API l'accepte, les portées ne changent pas.**

La console et l'API partagent la même classe de requête. Ouvrir une session de
portail reste un acte qui exige `portal:write` ; le flux `read` ne change **rien** aux
portées, il change ce que la session permet.

## Alternatives écartées

**Masquer les boutons, et rien d'autre.** — Rejeté : c'est une interface, pas une
délégation. Un POST fabriqué à la main agirait quand même. Le refus doit venir du
code qui agit.

**Un portail « lecture seule » séparé.** — Rejeté : ce serait un second portail à
maintenir, avec ses propres pages, ses propres gardes et ses propres tests — pour une
différence d'un seul drapeau. Le flux `read` est ce drapeau.

**Laisser l'opérateur agir au nom du client (le flux `manage` suffit).** — Rejeté :
c'est une délégation de pouvoir que personne n'a demandée, et dont l'opérateur ne
peut pas rendre compte au client. Le client peut agir lui-même : c'est l'objet du
portail.

**Une session « lecture seule » hors portail (un export de l'état du client).** —
Rejeté : un export est une **photographie**, il ne montre pas ce que le client voit.
La question du support est « qu'est-ce qu'il voit ? », pas « qu'est-ce qu'il a ? ».

## Conséquences

**Ce qui est gagné.** — L'opérateur peut ouvrir l'espace d'un client **sans rien
pouvoir faire**, et le client garde la maîtrise de son compte. La question du support
« qu'est-ce qu'il voit ? » a une réponse au lieu d'un risque.

**Ce qui est engagé.**

- Chaque geste mutateur du portail doit connaître le flux et refuser `read`. C'est une
  ligne par geste, et c'est **le** point à tester.
- Les vues doivent savoir masquer les actions : l'espace de travail expose le flux.
- La console doit offrir ce geste (un bouton sur la fiche client) — c'est le pendant
  console d'ADR-0110, et il sera fait avec la page qui enregistre le point d'entrée
  d'une application cliente.
- Un `read` refuse aussi une **inscription** (`subscribe`) : un opérateur ne
  souscrit pas pour le client.

**Point de réévaluation.** — Si un jour l'opérateur doit agir pour le client (un geste
de support autorisé), cela se fera par un **acte audité et explicite**, jamais par un
simple flux de portail.
