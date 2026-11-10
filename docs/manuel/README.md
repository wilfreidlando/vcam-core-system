# Manuel du Core — le guide de l'opérateur

Le Core a une console (`/admin`), un portail client (`/portal`) et une API (`/v1`).
Ce manuel les explique, mais **pas à partir des objets techniques**. Il part de la
situation que vous vivez : *« un client ne paie plus, que je fais ? »*.

Il y a deux lectures, et **personne n'a besoin des deux en même temps**.

| Vous êtes… | Vous lisez |
|------------|-----------|
| Un **opérateur** qui prend la console en main | Les **parcours** — dix-neuf tâches, rangées par situation |
| Un **commercial** qui répond à un client sur ses factures | Les parcours **F05 à F08** (vendre et encaisser) |
| Un **technicien** qui branche son logiciel au Core | La **référence** technique, et le [guide d'intégration](../integration/) |
| Un **lecteur** qui veut savoir pourquoi c'est comme ça | Les [ADR](../adr/) — une décision par document |

---

## Les parcours — « je veux faire quoi ? »

Chaque parcours est **autonome** : on peut le lire, l'imprimer, l'envoyer à un
collègue, sans le reste du manuel. Il est aussi **dans la console** : un lien
« Guide » dans la barre latérale ouvre la même chose que cette page.

| # | Parcours | Vous voulez… |
|---|----------|-------------|
| [F01](parcours/acces/F01-se-connecter-et-confirmer-mon-identite.md) | Se connecter et confirmer mon identité |
| [F02](parcours/acces/F02-confirmer-une-action-qui-deplace-de-largent.md) | Confirmer une action qui déplace de l'argent |
| [F03](parcours/acces/F03-inviter-un-collegue-et-lui-donner-ses-droits.md) | Inviter un collègue et lui donner ses droits |
| [F04](parcours/acces/F04-retrouver-qui-a-fait-quoi.md) | Retrouver qui a fait quoi |
| [F05](parcours/vendre/F05-enregistrer-un-client.md) | Enregistrer un client |
| [F06](parcours/vendre/F06-vendre-une-offre-et-la-faire-vivre.md) | Vendre une offre et la faire vivre |
| [F07](parcours/vendre/F07-emettre-une-facture.md) | Émettre une facture |
| [F08](parcours/vendre/F08-encaisser-un-reglement-recu-par-ailleurs.md) | Encaisser un règlement reçu par ailleurs |
| [F09](parcours/argent/F09-voir-ce-qui-mest-du-et-relancer.md) | Voir ce qui m'est dû et relancer |
| [F10](parcours/argent/F10-retrouver-ou-relancer-un-paiement.md) | Retrouver ou relancer un paiement |
| [F11](parcours/argent/F11-traiter-une-exception-dargent.md) | Traiter une exception d'argent |
| [F12](parcours/argent/F12-corriger-une-erreur-decriture.md) | Corriger une erreur d'écriture |
| [F13](parcours/argent/F13-comprendre-dou-vient-largent.md) | Comprendre d'où vient l'argent |
| [F14](parcours/livraisons/F14-brancher-une-application-cliente.md) | Brancher une application cliente |
| [F15](parcours/livraisons/F15-diagnostiquer-un-echec-de-livraison.md) | Diagnostiquer un échec de livraison |
| [F16](parcours/livraisons/F16-connecter-whatsapp-et-envoyer-des-messages.md) | Connecter WhatsApp et envoyer des messages |
| [F17](parcours/livraisons/F17-regler-lespace-client.md) | Régler l'espace client |
| [F18](parcours/piloter/F18-extraire-les-donnees-dun-projet.md) | Extraire les données d'un projet |
| [F19](parcours/piloter/F19-piloter-par-les-indicateurs.md) | Piloter par les indicateurs |

Les parcours sont regroupés en **cinq familles** :

1. **[Se connecter et gérer les accès](parcours/acces/)** — entrer, prouver qui vous êtes,
   décider qui peut faire quoi, retrouver ce qui s'est passé.
2. **[Vendre et encaisser](parcours/vendre/)** — le client, l'offre, l'abonnement, la
   facture, l'encaissement reçu par ailleurs.
3. **[Suivre et reprendre l'argent](parcours/argent/)** — les créances, un paiement
   bloqué, une exception, une correction, la lecture du grand livre.
4. **[Brancher et surveiller les livraisons](parcours/livraisons/)** — brancher une
   application cliente, diagnostiquer un échec, WhatsApp, l'espace client.
5. **[Extraire et piloter](parcours/piloter/)** — sortir les données, regarder les
   courbes.

---

## Comment lire un parcours

Chaque parcours suit la même forme, pour que vous sachiez toujours où vous en êtes :

| Section | Ce qu'elle vous donne |
|---------|----------------------|
| **Ce qu'il faut savoir avant** | les règles qui surprennent, et qui font échouer la manœuvre si on les ignore |
| **Les étapes** | le chemin exact, avec le **nom des boutons tel qu'il est écrit à l'écran** |
| **Ce que vous voyez** | chaque colonne et chaque état, expliqués |
| **Si ça ne marche pas** | le message affiché, ce qu'il signifie, ce qu'il faut faire |
| **Et ensuite** | le parcours voisin, pour continuer sans chercher |

Deux conventions, appliquées partout :

- **`→ Ce qui se passe :`** — l'encadré qui explique **ce que le logiciel a fait**, juste
  après l'étape qui l'a provoqué. Un parcours ne confond jamais *ce que vous faites* et
  *ce que la machine en fait*.
- **Les guillemets français** — tout ce qui est entre `« »` est un mot **écrit sur un
  écran**. Si le produit renomme un bouton, le guide le suit : un test de la build
  échoue tant que le guide cite un mot que le produit n'affiche plus.

---

## La référence technique

Dans [`reference/`](reference/) : les dix chapitres qui expliquent **le comment**, pour
ceux qui doivent toucher au produit. Ils sont écrits pour des techniciens — ils parlent
de portée, d'identifiant, de cas limite — et ne sont pas rendus dans la console.

| Chapitre | Ce qu'il couvre |
|----------|----------------|
| [01 — Connexion et sécurité](reference/01-connexion-securite.md) | Connexion, code à 6 chiffres, rôle, invitation, journal d'audit |
| [02 — Organisations et projets](reference/02-organisations-projets.md) | Qui est le client, qu'est-ce qu'un projet, qui voit quoi |
| [03 — Clés d'API et webhooks](reference/03-cles-api-webhooks.md) | Clés d'API, points de livraison, secrets, applications de prestataire, sessions WhatsApp |
| [04 — Commerce](reference/04-commerce.md) | Clients, offres, abonnements, factures, encaissements hors ligne |
| [05 — Argent](reference/05-argent.md) | Paiements, réconciliation, exceptions, corrections |
| [06 — Portefeuilles, notifications et exports](reference/06-portefeuille-notifications.md) | Portefeuilles, messages, usage, exports |
| [07 — Portail client](reference/07-portail-client.md) | L'espace du client : abonnement, factures, paiement, quotas |
| [08 — API pour l'intégrateur](reference/08-api-integrateur.md) | Le contrat d'appel, les refus, les limites |
| [09 — Comprendre l'argent](reference/09-comprendre-l-argent.md) | Le grand livre, les portefeuilles, la chaîne de l'argent |
| [10 — Ce que le Core ne fait pas](reference/10-ce-que-le-core-ne-fait-pas.md) | Les écarts assumés, ce qui n'existe pas encore |
| [11 — Les suppléments de quota](reference/11-supplements-de-quota.md) | Acheter des unités en plus d'un quota, sans renouveler l'abonnement — pour la période payée |

Pour l'API, il y a mieux que ce manuel : le [**guide d'intégration**](../integration/) et
le schéma des routes (`openapi/v1.json`). Pour les décisions, les [**ADR**](../adr/).

---

## Ce que ce manuel n'est pas

- Ce n'est **pas** la documentation de l'API : elle est dans
  [`docs/integration/`](../integration/).
- Ce n'est **pas** l'explication des choix de conception : ils sont dans
  [`docs/adr/`](../adr/), un document par décision.
- Ce n'est **pas** un catalogue de rubriques : chaque parcours est une **tâche**, de sa
  première étape à la dernière.
