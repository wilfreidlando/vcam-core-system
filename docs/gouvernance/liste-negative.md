# Liste négative vivante

Ce que le Core a **refusé** d'absorber, et pourquoi. Un refus est aussi
précieux qu'une acceptation (gouvernance §4.2, §12) : il sert de jurisprudence
pour les demandes futures similaires.

Chaque entrée renvoie vers l'ADR qui trace la décision (utiliser la skill
`nouvelle-adr` pour l'écrire).

## D'ores et déjà exclu par la spécification fonctionnelle (§9)

Ce socle de refus est posé par la spécification elle-même, avant toute demande
concrète — il n'a pas besoin d'ADR individuel tant qu'aucun cas limite ne se
présente :

- Gestion des chambres et réservations d'un hôtel.
- Gestion pédagogique d'un établissement.
- Catalogue métier spécifique à un client.
- Workflows métier propres à un client.
- Règles de pricing propres à un produit, quand elles ne sont pas transverses.
- Processus et UI métier spécifiques à un produit.
- Modèles métier propres à un secteur.

## Refus tranchés au cas par cas

_Aucune entrée pour l'instant. Ajouter une ligne à chaque refus du comité
d'architecture, la plus récente en haut._

| Date | Capacité demandée | Produit demandeur | Porte(s) ayant échoué | ADR |
| ---- | ------------------ | ------------------ | ---------------------- | --- |
| — | — | — | — | — |

## Comment utiliser cette liste

- Avant de statuer sur une demande, vérifier si une entrée similaire existe
  déjà ici — la réponse est peut-être déjà écrite.
- Un refus n'est pas définitif dans l'absolu : si un deuxième produit réel
  exprime le même besoin plus tard, la porte n°1 (transversalité) peut être
  réévaluée. La ligne existante reste, une nouvelle décision peut la
  compléter ou la réviser via un nouvel ADR qui la référence.
