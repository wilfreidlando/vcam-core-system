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

Une ligne par refus du comité d'architecture, la plus récente en haut. Les
lignes au statut **proposé** attendent la décision du comité (ADR-0068 à
ADR-0090) ; elles deviennent des refus une fois l'ADR accepté.

| Date | Capacité demandée | Demandeur | Porte(s) ayant échoué | ADR | Statut |
| ---- | ------------------ | --------- | ---------------------- | --- | ------ |
| 2026-10-24 | Cloisonnement des données internes des applications (scope de tenant générique) | fusion de la plateforme transitoire | 2 (générique) — le Core ne connaît pas les tables d'un projet ; un filtre générique ne peut pas être correct. Le Core fournit l'identité du client et ses droits ; le projet cloisonne, en fail-closed | [0084](../adr/0084-contrat-d-integration-d-un-projet.md) | proposé |
| 2026-10-24 | Fournisseur d'identité pour les utilisateurs finaux (compte unique entre applications, jetons émis au nom des utilisateurs) | fusion de la plateforme transitoire | 1 (aucun besoin démontré par deux projets) + 3 (exposition) — condition de réexamen écrite | [0084](../adr/0084-contrat-d-integration-d-un-projet.md) | proposé |
| 2026-10-24 | Flux JWT de la plateforme transitoire (émission, rafraîchissement, révocation de jetons) | fusion de la plateforme transitoire | 3 — cassé, plus faible que le jeton à usage unique, sans usage réel ; retiré sans remplacement | [0089](../adr/0089-absorption-de-la-plateforme-transitoire.md) | proposé |
| 2026-10-24 | Inscription libre-service des organisations, SDK, espace communautaire | direction | 1 — aucune demande démontrée ; le registre de capacités en est la base | [0087](../adr/0087-catalogue-de-capacites-et-experience-developpeur.md) | proposé |
| 2026-10-24 | Microservices et plugins chargés dynamiquement par projet | — | 3 — coût et surface d'attaque ; les frontières de modules et les registres typés suffisent | [0090](../adr/0090-evolutivite-et-points-d-extension.md) | proposé |

## Comment utiliser cette liste

- Avant de statuer sur une demande, vérifier si une entrée similaire existe
  déjà ici — la réponse est peut-être déjà écrite.
- Un refus n'est pas définitif dans l'absolu : si un deuxième produit réel
  exprime le même besoin plus tard, la porte n°1 (transversalité) peut être
  réévaluée. La ligne existante reste, une nouvelle décision peut la
  compléter ou la réviser via un nouvel ADR qui la référence.
- Ce qui n'est **pas encore construit** mais prévu comme point d'extension
  (prorata, remises, facturation à l'usage, prélèvement récurrent, domaine
  personnalisé du portail) n'est **pas** un refus : voir ADR-0090 §2.
