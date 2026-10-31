# Le guide des parcours — la console et le portail, expliqués par l'usage

Ce guide est écrit pour **quelqu'un qui ne connaît pas le logiciel**. Il ne présente
ni des API, ni des choix d'architecture, ni des noms de tables. Il répond à trois
questions seulement :

- **Je veux faire quoi ?** — une situation, une tâche.
- **Par où je commence ?** — le chemin exact dans la console, avec le nom des boutons.
- **Qu'est-ce que je vois ?** — chaque colonne, chaque état, expliqué en français.

Chaque parcours est **autonome** : on peut le lire, l'imprimer, le transmettre à un
collègue, sans le reste du guide.

> Le guide est aussi **dans la console** : un lien « Guide » dans la barre latérale
> ouvre les mêmes parcours (`/admin/guide`). Les fichiers d'ici sont la source ; la
> console les rend. Une seule source, donc rien ne peut diverger.
> (Décision : [ADR-0113](../../adr/0113-le-guide-est-dans-la-console.md).)

---

## Choisissez votre situation

| # | Parcours | Vous voulez… | Famille |
|---|----------|-------------|---------|
| [F01](acces/F01-se-connecter-et-confirmer-mon-identite.md) | Se connecter et confirmer mon identité | ouvrir la console le matin | 1 |
| [F02](acces/F02-confirmer-une-action-qui-deplace-de-largent.md) | Confirmer une action qui déplace de l'argent | valider une facture, un reçu, une correction | 1 |
| [F03](acces/F03-inviter-un-collegue-et-lui-donner-ses-droits.md) | Inviter un collègue et lui donner ses droits | ajouter quelqu'un à l'équipe | 1 |
| [F04](acces/F04-retrouver-qui-a-fait-quoi.md) | Retrouver qui a fait quoi | chercher l'origine d'un acte | 1 |
| [F05](vendre/F05-enregistrer-un-client.md) | Enregistrer un client | créer une fiche client | 2 |
| [F06](vendre/F06-vendre-une-offre-et-la-faire-vivre.md) | Vendre une offre et la faire vivre | abonner, changer d'offre, mettre en pause, arrêter | 2 |
| [F07](vendre/F07-emettre-une-facture.md) | Émettre une facture | facturer une période | 2 |
| [F08](vendre/F08-encaisser-un-reglement-recu-par-ailleurs.md) | Encaisser un règlement reçu par ailleurs | enregistrer un virement ou un paiement en espèces | 2 |
| [F09](argent/F09-voir-ce-qui-mest-du-et-relancer.md) | Voir ce qui m'est dû et relancer | suivre les créances | 3 |
| [F10](argent/F10-retrouver-ou-relancer-un-paiement.md) | Retrouver ou relancer un paiement | un paiement est bloqué | 3 |
| [F11](argent/F11-traiter-une-exception-dargent.md) | Traiter une exception d'argent | l'argent ne s'équilibre pas | 3 |
| [F12](argent/F12-corriger-une-erreur-decriture.md) | Corriger une erreur d'écriture | une écriture est fausse | 3 |
| [F13](argent/F13-comprendre-dou-vient-largent.md) | Comprendre d'où vient l'argent | lire le grand livre, les portefeuilles | 3 |
| [F14](livraisons/F14-brancher-une-application-cliente.md) | Brancher une application cliente | clés, livraisons, prestataire | 4 |
| [F15](livraisons/F15-diagnostiquer-un-echec-de-livraison.md) | Diagnostiquer un échec de livraison | un événement n'est pas arrivé | 4 |
| [F16](livraisons/F16-connecter-whatsapp-et-envoyer-des-messages.md) | Connecter WhatsApp et envoyer des messages | envoyer un message au client | 4 |
| [F17](livraisons/F17-regler-lespace-client.md) | Régler l'espace client | le portail du client | 4 |
| [F18](piloter/F18-extraire-les-donnees-dun-projet.md) | Extraire les données d'un projet | sortir les données | 5 |
| [F19](piloter/F19-piloter-par-les-indicateurs.md) | Piloter par les indicateurs | savoir comment va le projet | 5 |

---

## Les cinq familles

1. **[Se connecter et gérer les accès](acces/)** — entrer dans la console, prouver qui
   vous êtes, décider qui peut faire quoi, retrouver ce qui s'est passé. *F01 à F04.*
2. **[Vendre et encaisser](vendre/)** — le client, l'offre, l'abonnement, la facture,
   l'encaissement reçu par ailleurs. *F05 à F08.*
3. **[Suivre et reprendre l'argent](argent/)** — les créances, un paiement bloqué, une
   exception, une correction, et la lecture du grand livre. *F09 à F13.*
4. **[Brancher et surveiller les livraisons](livraisons/)** — brancher une application
   cliente, diagnostiquer un échec, WhatsApp, l'espace client. *F14 à F17.*
5. **[Extraire et piloter](piloter/)** — sortir les données, regarder les courbes.
   *F18 et F19.*

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

## Ce que ce guide n'est pas

- Ce n'est **pas** la documentation de l'API : elle est dans
  [`docs/integration/`](../../integration/), et le schéma des routes dans
  `openapi/v1.json`.
- Ce n'est **pas** l'explication des choix de conception : ils sont dans
  [`docs/adr/`](../../adr/), un document par décision.
- Ce n'est **pas** un catalogue de rubriques : chaque parcours est une **tâche**, de sa
  première étape à la dernière.

La documentation technique du produit — celle qui sert aux intégrateurs et aux
exploitants du Core — reste dans **[`../reference/`](../reference/)**. Elle y est
classée par sujet, pas par usage ; ce guide est classé par usage, pas par sujet.
