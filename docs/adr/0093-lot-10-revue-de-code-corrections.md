# ADR-0093 — Lot 10 : ce que la revue de code a corrigé

- **Statut** : Accepté
- **Date** : 2026-10-27
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27), sur proposition de
  l'implémentation (revue de code du lot 10)
- **Amende** : ADR-0025 (idempotence HTTP), ADR-0070 (clients), ADR-0071
  (catalogue), ADR-0072 (abonnements), ADR-0083 (données personnelles)
- **Dérives concernées** : D5 contrat · D6 intégrité financière · D7 organisationnelle

## Contexte

Le lot 10 (module `billing`) a été soumis à une revue de code adversariale :
deux lectures indépendantes du cœur métier et de la surface d'exposition, sur
le code livré. La revue a trouvé des défauts **réels** — pas des préférences
de style — que les tests existants ne voyaient pas :

- une passe de l'ordonnanceur qui s'arrêtait sur un contrat en échec, laissant
  tous les autres sans renouvellement ;
- une reprise d'abonnement qui violait la contrainte d'exclusion des périodes
  et rendait le contrat impossible à reprendre ;
- une déclaration de paiement qui pouvait réactiver un contrat impayé ;
- un changement de plan immédiat impossible pendant les jours qui précèdent
  l'échéance ;
- un verrou optimiste absent de l'écriture du client ;
- des données personnelles journalisées en clair et affichées en clair dans la
  console ;
- une clé d'idempotence bloquée à jamais après un échec.

Un ADR accepté ne se réécrit pas : les corrections dont la **décision** change
sont consignées ici, et les codes qui ne contredisent aucun ADR sont corrigés
sans nouvelle décision.

## Décision

### 1. La pause est permise depuis tout état non final (amende ADR-0072 §2)

La table des transitions d'ADR-0072 §2 ne mentionne la pause que depuis
`active` et `past_due`. Le code l'autorise depuis `incomplete` et `trialing`
aussi — et c'est **le code qui a raison** : suspendre l'essai d'un client est
un geste d'exploitant ordinaire, et la spécification fonctionnelle §8.1 le
confère explicitement au rôle de support.

La table est donc étendue : `incomplete`, `trialing`, `active` et `past_due`
peuvent tous devenir `paused`. `canceled` et `expired` restent finaux.

### 2. Une passe de l'ordonnanceur isole l'échec d'un contrat (amende ADR-0072 §8)

Une passe examine des contrats, **un par un**, chacun dans sa transaction. Un
contrat qui ne peut pas être avancé — une course avec une autre passe, un
réglage qui ne s'applique plus — est **compté et journalisé**, et la passe
continue avec le suivant.

La raison est simple : la liste est triée par échéance. Un contrat qui échoue
en tête de liste échouerait à **chaque** passe, et aucun contrat ne serait
plus jamais renouvelé. Un ordonnanceur qui s'arrête à mi-parcours est un
ordonnanceur qui abandonne.

### 3. Seule la période **courante** et **facturée** peut être déclarée réglée (amende ADR-0092 §2)

La déclaration de paiement porte sur la période que le contrat est en train
de vivre, et seulement si elle est facturée. Une période d'essai ou une
période offerte est **gratuite** : elle n'est pas réglée, et son motif — celui
de l'exploitant — n'est jamais écrasé par une référence de virement.

Déclarer le règlement d'une période ancienne réactivait un contrat dont la
période courante restait impayée : le client était servi gratuitement.

### 4. L'effacement est final pour l'upsert aussi (amende ADR-0070 §1)

Un client effacé ne peut plus être écrit. L'upsert le réactivait
silencieusement, en remettant un nom, un courriel et un téléphone chiffrés
sur une ligne volontairement vidée, sans qu'aucun événement ne le dise.

L'upsert public crée un client **qu'il n'existe pas** ; il ne réveille pas
un client effacé. Le client effacé reste dans l'historique, et c'est tout.

### 5. Une version de plan retirée le reste (amende ADR-0071 §2)

Le trigger d'immuabilité refuse déjà de modifier les termes d'une version
publiée ou retirée, et de repasser une version publiée à l'état `draft`. Il
laisse passer `retired → published` : une version retirée pouvait revenir à
l'état publié, et les contrats signés sur elle retrouvaient un prix qui n'était
plus le leur. `retired` est final.

### 6. La console n'affiche jamais une donnée personnelle en clair (amende ADR-0083 §3)

ADR-0083 §3 dit « masqués par défaut ; démasquage = action auditée ». Le lot 10
n'offre **aucun** mécanisme de démasquage : la console affiche donc la valeur
courante **masquée**, et le champ de saisie repart vide — un champ laissé vide
conserve la valeur, un champ rempli la remplace.

La donnée personnelle est chiffrée au repos (ADR-0083 §2) et ne sort jamais en
clair d'une page de console. Le démasquage, quand il viendra, sera une action
auditée ; il n'existe pas dans ce lot, donc il n'y a rien à démasquer.

### 7. Une clé d'idempotence libérée quand la requête échoue (amende ADR-0025)

Une clé d'idempotence est une promesse sur une requête **réussie**. Quand la
route échoue — 404, 409, 422 — elle n'a produit aucun effet : la clé est
libérée et l'appelant peut réessayer avec la même clé.

La clé restait verrouillée à jamais (`completed_at` jamais écrit) et chaque
reprise d'une requête échouée répondait 409 « déjà en cours » — définitivement.

### 8. Le verrou optimiste du client est dans l'écriture (amende ADR-0070 §2)

L'écriture d'un client porte la version attendue dans son `WHERE`
(`UPDATE … WHERE id = ? AND version = ?`). Le contrôle en mémoire ne suffit
pas : deux déclarations concurrentes de la même référence pouvaient passer
toutes les deux, et la seconde écrasait la première pendant que la version
n'augmentait que d'un cran — l'ETag renvoyé ne disait rien de la perte.

C'est la discipline du repository des abonnements, appliquée au client.

## Alternatives écartées

- **Corriger le code sans écrire d'ADR.** Rejeté : six de ces corrections
  changent une **décision** (la table des transitions, le périmètre de la
  déclaration de paiement, le sort d'une clé d'idempotence). Un code qui
  contredit un ADR accepté sans que l'ADR soit amendé est une dérive, pas une
  correction.
- **Un démasquage audité dans la console.** Rejeté pour ce lot : il faut une
  action, une permission, une trace d'audit et une politique de conservation —
  et un besoin qui n'a pas été exprimé. La console n'affiche pas la donnée ;
  le jour où quelqu'un en a besoin, l'action sera écrite.
- **Libérer la clé d'idempotence au premier échec, et la rejouer telle quelle.**
  Rejeté : le client verrait 409 sur une clé qu'il n'a jamais pu utiliser, et
  le support n'y comprendrait rien. La clé libérée est une clé **inutilisée**.

## Conséquences

- La table des transitions du code et celle d'ADR-0072 §2 ne divergent plus.
- L'ordonnanceur ne peut plus abandonner une passe à mi-parcours ; un
  contrat qui échoue est visible dans le journal et dans le rapport de la
  passe (`failed`).
- La déclaration de paiement ne peut plus réveiller un contrat impayé.
- Les données personnelles ne sont plus journalisées en clair
  (`email`, `phone`, `display_name` sont désormais des clés sensibles du
  `core/support`) ni affichées en clair dans la console.

## Vérification

- Test : une reprise d'abonnement après la préparation d'un renouvellement
  aboutit (la période suivante est décalée **avant** que la courante ne soit
  prolongée).
- Test : la déclaration du règlement d'une période qui n'est pas la période
  courante est refusée.
- Test : un changement de plan immédiat réussit même quand le renouvellement a
  déjà été préparé.
- Test : un client effacé refuse toute nouvelle déclaration.
- Test : une version de plan retirée ne peut pas repasser à l'état publié.
- Test : le journal HTTP ne contient ni courriel ni téléphone en clair.
- Test : la console n'affiche ni courriel ni téléphone en clair.
- Test : une requête en échec libère sa clé d'idempotence, et le même appel
  réussit avec la même clé.
