# Manuel d'utilisation — Console opérateur, Portail client, API

Ce manuel est le document de formation du Core System. Il décrit **trois produits qui n'en
font qu'un** :

- la **console opérateur** (`/admin`) — 64 écrans où l'équipe de la plateforme gère les
  organisations, les projets, l'argent et les livraisons ;
- le **portail client** (`/portal`) — l'espace où le client final de vos produits consulte
  ses factures, son abonnement et ses quotas ;
- l'**API publique** (`/v1`) — ce que votre logiciel appelle pour encaisser, notifier et
  abonner.

**À quoi ça sert.** Un nouvel opérateur prend la console en main en une demi-journée. Un
opérateur en poste a les chapitres de référence sous la main. Un commercial sait répondre
à un client sur ses factures, ses quotas et son portail. Un intégrateur branche son
produit et sait pourquoi ses appels sont refusés.

**Ce que ce manuel n'est pas.** Ce n'est ni la documentation de l'API (`/docs/api`, et le
fichier `openapi/v1.json`) ni le guide d'intégration (`docs/integration/`). Ce n'est pas un
document d'architecture non plus. Quand ce manuel dit « la console fait X », c'est parce
que le code le fait — chaque chapitre finit par ses repères techniques.

---

## Sommaire

| # | Chapitre | Ce qu'il couvre | Public principal |
|---|----------|-----------------|------------------|
| 01 | [Connexion et sécurité](01-connexion-securite.md) | Connexion, MFA TOTP, **élévation**, rôles, opérateurs, invitation, journal d'audit | Tous |
| 02 | [Organisations et projets](02-organisations-projets.md) | Organisations, membres, rôles d'organisation, projets, cycle de vie, portefeuille | Opérateur, commerce |
| 03 | [Clés d'API et webhooks](03-cles-api-webhooks.md) | Clés d'API (création, rotation, révocation), endpoints webhook, secrets et double signature, applications prestataire, sessions WhatsApp | Opérateur, intégrateur |
| 04 | [Commerce](04-commerce.md) | Clients, offres et versions, abonnements, factures, encaissements hors ligne, réglages du commerce | Commerce |
| 05 | [Argent](05-argent.md) | Paiements, réconciliation, exceptions d'argent, corrections d'exploitation | Opérateur |
| 06 | [Portefeuilles, notifications et exports](06-portefeuille-notifications.md) | Portefeuilles, notifications, usage et quotas, exports par projet | Opérateur, commerce |
| 07 | [Portail client](07-portail-client.md) | Ouverture de session, tableau de bord, abonnement, factures et paiement, quotas, profil, connexion par code | Commerce, support |
| 08 | [API pour l'intégrateur](08-api-integrateur.md) | Authentification, portée des clés, idempotence, quotas de débit, webhooks sortants, notifications, simulateur, exports | Intégrateur |
| 09 | [Comprendre l'argent](09-comprendre-l-argent.md) | Le grand livre en double-entrée, les portefeuilles, la chaîne de l'argent de bout en bout, le compteur d'usage | Opérateur |
| 10 | [Ce que le Core ne fait pas](10-ce-que-le-core-ne-fait-pas.md) | Écarts assumés, modules hors périmètre, comptes déclarés mais non écrits | Tous |

---

## Quatre parcours de formation

### Parcours 1 — « Nouvel opérateur » (une demi-journée)

Vous ne connaissez pas le produit. Suivez les chapitres dans cet ordre.

1. **[01 — Connexion et sécurité](01-connexion-securite.md)**. Vous allez créer votre
   compte, configurer votre authentificateur, apprendre ce qu'est une élévation et lire le
   journal d'audit. Comptez 45 minutes.
2. **[02 — Organisations et projets](02-organisations-projets.md)**. Qui est le client,
   qu'est-ce qu'un projet, qui a le droit de voir quoi. 30 minutes.
3. **[05 — Argent](05-argent.md)**. Les paiements, ce qu'est une exception d'argent, ce
   qu'est une correction. C'est le chapitre qui vous évitera les mauvaises décisions.
   45 minutes.
4. **[09 — Comprendre l'argent](09-comprendre-l-argent.md)**. La lecture. À faire après
   05, pas avant : ce chapitre explique pourquoi 05 se comporte comme il se comporte.
   45 minutes.
5. **[03 — Clés d'API et webhooks](03-cles-api-webhooks.md)** et
   **[06 — Portefeuilles, notifications et exports](06-portefeuille-notifications.md)**.
   45 minutes.

À la fin, vous savez : ouvrir une session, trouver un projet, lire un paiement, dire d'où
vient un montant, et ce qu'il faut rejouer quand une livraison échoue.

### Parcours 2 — « Exploitation courante » (le quotidien)

Vous êtes en poste. Ce sont les gestes que vous ferez chaque jour.

| Quand | Chapitre | Le geste |
|-------|---------|----------|
| Matin | [05 — Argent](05-argent.md) | Lire les exceptions d'argent non résolues, les traiter ou les laisser à un collègue |
| Matin | [05 — Argent](05-argent.md) | Rejouer les livraisons de webhooks en échec |
| Matin | [03 — Clés d'API et webhooks](03-cles-api-webhooks.md) | Répondre à « le client dit qu'il ne reçoit rien » |
| Sur demande | [06 — Portefeuilles, notifications et exports](06-portefeuille-notifications.md) | Lire l'historique d'un envoi WhatsApp, expliquer un quota atteint |
| Sur demande | [04 — Commerce](04-commerce.md) | Émettre une facture, enregistrer un virement reçu |
| Fin de journée | [01 — Connexion et sécurité](01-connexion-securite.md) | Relire le journal d'audit si un doute est revenu |

### Parcours 3 — « Commerce » (facturation et relation client)

Vous parlez aux clients et vous pilotez leur facturation.

1. **[04 — Commerce](04-commerce.md)**. Clients, offres, abonnements, factures, encaissements.
2. **[07 — Portail client](07-portail-client.md)**. Ce que voit le client, ce qu'il peut
   faire seul, ce qu'il vous demande.
3. **[06 — Portefeuilles, notifications et exports](06-portefeuille-notifications.md)**.
   Usage et quotas, et la section « Les indicateurs du projet » : comment vous savez
   qu'un projet va mal.
4. **[09 — Comprendre l'argent](09-comprendre-l-argent.md)**, section « Ce que l'exploitant
   doit en faire » : les questions que vos clients vous posent, et où trouver la
   réponse.

### Parcours 4 — « Intégration » (votre logiciel appelle le Core)

1. **[08 — API pour l'intégrateur](08-api-integrateur.md)**. Le contrat d'appel.
2. **[03 — Clés d'API et webhooks](03-cles-api-webhooks.md)**. Ce que vous demandez à
   l'administrateur de la plateforme, et pourquoi il vous posera des questions sur la
   portée et l'expiration.
3. **[09 — Comprendre l'argent](09-comprendre-l-argent.md)**, sections 4 et 5 : pourquoi
   un paiement réussi ne crédite pas un compte, et ce que le compteur d'usage mesure.
4. **[07 — Portail client](07-portail-client.md)** : si vous ouvrez aussi un espace client.

---

## Conventions de lecture

**Les libellés sont entre guillemets.** Ce sont les mots exacts affichés par le produit.
`« Filtrer »`, `« Enregistrer la décision »`, `« Faire tourner »` : si le texte change dans
le produit, ce manuel est à corriger.

**Chaque écran est désigné par son URL.** `GET /admin/projects/{projectId}/invoices`
signifie « la page listant les factures du projet dont l'identifiant est
`{projectId}` ». Les accolades sont des emplacements : remplacez-les par la valeur réelle.

**Les termes techniques sont expliqués à leur première occurrence**, en gras. Après, on
emploie le terme seul. « Un **projet** est l'espace d'un client dans le Core : il porte ses
clés d'API, ses factures, ses clients. »

**Les actions sont numérotées.** Si une action comporte des choix, chaque option est
détaillée. Un chapitre ne dit jamais « faites ce qu'il faut ».

**Les secrets ne sont affichés qu'une fois.** Un jeton d'invitation, une clé d'API, un
secret de signature : la console les affiche dans la réponse qui les crée, jamais dans une
page consultée plus tard. Si vous l'avez perdu, vous en créez un nouveau.

**Les codes HTTP sont expliqués quand ils portent une information.** `403` signifie « vous
n'avez pas le droit ». `404` sur une ressource qui existe signifie « elle n'est pas dans
votre périmètre » — c'est volontaire : le Core ne confirme pas l'existence d'une
ressource d'un autre client. Les deux codes ne se confondent jamais.

**Chaque chapitre finit par « Repères techniques »** : les fichiers du code qui portent la
comportement décrit. Cette section est pour vous et pour l'équipe qui maintient le
produit. Vous pouvez la sauter.

**La date de référence est le 2026-10-30.** Le produit évolue ; si une capture d'écran ne
ressemble plus à ce que vous lisez, la documentation d'API (`/docs/api`) fait foi.
