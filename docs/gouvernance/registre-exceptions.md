# Registre des exceptions actives

Une exception au Core (une règle métier tolérée temporairement, un accès
dérogatoire, un contrat non standard) n'est **jamais permanente par défaut**
(gouvernance §13). Chaque exception est datée, tracée par ADR, et assortie
d'une date de revue.

**Indicateur suivi** : le nombre d'exceptions actives doit tendre vers zéro. Une
exception qui perdure signale soit un manque de primitive générique à créer
proprement, soit une dérive à corriger.

## Exceptions actives

_Aucune exception active pour l'instant._

| ID | Description | Produit concerné | Accordée le | Date de revue | ADR | Statut |
| -- | ------------ | ----------------- | ----------- | -------------- | --- | ------ |
| — | — | — | — | — | — | — |

## Exceptions closes

_Historique des exceptions résolues (revenues à la normale, ou devenues une
primitive générique du Core)._

| ID | Description | Close le | Résolution |
| -- | ------------ | -------- | ---------- |
| — | — | — | — |

## Procédure

1. Avant d'accorder une exception, vérifier l'alternative préférée
   (gouvernance §13.1) : un point d'extension côté produit (calcul côté
   produit, métadonnées, réaction à un événement, webhook) plutôt qu'une
   modification du Core.
2. Si l'exception est réellement inévitable : ADR (skill `nouvelle-adr`),
   ligne ajoutée ici avec une date de revue explicite (jamais « à durée
   indéterminée »).
3. À la date de revue : soit l'exception est levée (ligne déplacée vers
   « closes »), soit elle est reconduite avec une nouvelle date de revue et
   une justification — jamais reconduite silencieusement.
