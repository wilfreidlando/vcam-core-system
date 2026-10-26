# ADR-0068 — Cible : une plateforme multi-tenant de projets SaaS, en trois piliers

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D1 périmètre · D2 couplage · D5 contrat · D6 intégrité financière · D7 organisationnelle
- **Amende** : ADR-0048 (`billing` et `developer-platform` entrent en production ; `fees` et `storage` restent des scaffolds)

## Contexte

Le core livre aujourd'hui des **services techniques** à des projets : identité
machine, paiement, wallet, ledger, notification, webhook, audit, console
opérateur (ADR-0001 à ADR-0067). Il ne sait pas répondre aux questions qu'un
**éditeur de logiciels SaaS** se pose chaque jour sur chacun de ses projets :

- qui sont les clients de ce projet, et lesquels paient ?
- à quel plan sont-ils abonnés, jusqu'à quand, et que leur reste-t-il ?
- combien ce projet a-t-il encaissé, combien lui doit-on, combien rapporte-t-il
  chaque mois ?
- quelles sommes sont entrées, où sont-elles, et le compte est-il juste ?

Une plateforme transitoire répond à une partie de ces questions, sur une autre
pile et avec un modèle fragile (ADR-0089). Chaque projet SaaS qui arrive refait
donc sa facturation, ses quotas et son espace client — c'est exactement la
duplication que le core existe pour supprimer.

Le besoin exprimé est double :

1. le core doit **gérer des projets SaaS de bout en bout**, pas seulement leur
   fournir des appels techniques ;
2. il doit le faire pour **n'importe quelle organisation** propriétaire de
   projets — l'entreprise elle-même comme une organisation tierce — avec un
   cloisonnement strict, et en restant **générique** : aucun concept métier
   d'un projet dans le core.

## Décision

### 1. Trois piliers, trois contextes bornés

| Pilier | Question à laquelle il répond | Modules |
|---|---|---|
| **Services de plateforme** | « De quoi mon projet a-t-il besoin pour fonctionner ? » | `identity`, `audit`, `events`, `webhook`, `notification`, `payment`, `developer-platform`, `admin` |
| **Grand livre** | « Où est l'argent, et le compte est-il juste ? » | `ledger`, `wallet`, `parties`, `payout` (nouveau) |
| **Commerce SaaS** | « Qui paie quoi, jusqu'à quand, et que consomme-t-il ? » | `billing` (clients, catalogue, abonnements, factures, usage), `portal` (nouveau, portail client hébergé) |

Les trois piliers ne se parlent que par `Contracts/` et par événements de
l'outbox (G3c, G8). Aucun pilier ne lit la table d'un autre.

### 2. Le vocabulaire est fixé une fois pour toutes

| Terme | Définition | Où il vit |
|---|---|---|
| **Organisation** | Entité propriétaire de projets (l'entreprise, ou une organisation tierce) | `identity` |
| **Projet** | Un produit SaaS servi par la plateforme. C'est l'unité de cloisonnement (`project_id`) de toutes les données | `identity` |
| **Compte console** | Une personne qui se connecte à la console : personnel de la plateforme ou membre d'une organisation (ADR-0069) | `identity` |
| **Client** | La partie qui **paie** un projet : une entreprise ou un particulier, déclaré par le projet (ADR-0070) | `billing` |
| **Utilisateur final** | Une personne qui utilise le produit du projet sans être son client payeur. **Jamais connue du core** | le projet |
| **Partie** | Un acteur économique d'un wallet, sans identité (ADR-0033) | `parties` |

### 3. Ce que la plateforme fait pour un projet SaaS

1. **Identifier** : organisation, projet, clés d'API à portée limitée, comptes
   console scopés (ADR-0069, ADR-0082).
2. **Vendre** : clients, catalogue de plans versionnés, abonnements par
   périodes, factures, encaissement en ligne ou hors ligne (ADR-0070 à
   ADR-0073).
3. **Mesurer et limiter** : usage idempotent, quotas atomiques, droits par
   fonctionnalité (ADR-0074).
4. **Servir le client** : portail client hébergé, personnalisable par projet
   (ADR-0075).
5. **Compter** : chaque somme entrée ou sortie est écrite au grand livre,
   rapprochée, et versée si besoin (ADR-0076 à ADR-0079).
6. **Piloter** : console de portefeuille par organisation et par projet —
   revenu récurrent, encaissé, créances, usage, santé technique (ADR-0080).
7. **Durcir et exploiter** : modèle de menace, débit, observabilité, contrat
   versionné, catalogue, réversibilité, évolutivité (ADR-0081 à ADR-0090).

### 4. Ce que la plateforme ne fait pas

- Elle ne connaît **aucun concept métier** d'un projet (élèves, chambres,
  réservations…). Un plan porte des **fonctionnalités nommées par le projet**,
  pas par le core.
- Elle ne cloisonne pas les **données internes** des applications ; elle leur
  fournit l'identité du client et ses droits (ADR-0084).
- Elle n'est pas un fournisseur d'identité pour les **utilisateurs finaux**
  (ADR-0084).
- Elle ne fait pas de comptabilité générale de l'organisation : le grand livre
  est celui des flux que la plateforme voit, par projet.

### 5. Graphe de modules cible

Modification de `tests/Architecture/ModuleGraph.php` (comité, ADR-0002) :

```php
'billing'      => ['support', 'identity', 'payment', 'ledger', 'events'],          // inchangé
'notification' => ['support', 'identity', 'parties', 'events', 'billing'],         // + billing (ADR-0074)
'portal'       => ['support', 'identity', 'billing', 'payment', 'notification', 'audit'], // nouveau (ADR-0075)
'payout'       => ['support', 'identity', 'ledger', 'wallet', 'parties', 'events', 'payment'], // nouveau (ADR-0079)
'admin'        => [ /* existant */ ..., 'payout', 'portal' ],
```

Aucun cycle : `billing` ne dépend ni de `notification`, ni de `portal`, ni de
`payout`. `ledger` reste sans dépendance (G2).

### 6. Phase documentaire d'abord

Aucun code, aucune migration avant que les ADR de cette série soient
**acceptés par le comité**. Les documents de transition (cadrage, inventaire,
correspondance, plan de bascule) sont les seuls autorisés à nommer les dépôts
voisins ; tous les autres décrivent des rôles.

La série a été **ratifiée par le comité d'architecture le 2026-10-27**. Sept de
ces ADR (0069, 0076, 0077, 0078, 0081, 0082, 0083) avaient déjà leur code
livré et conforme au moment de la ratification : le code a précédé la décision
plutôt que l'inverse, ce qui n'est pas la séquence prévue ci-dessus. Le
comité a donc **vérifié la conformité du code livré** aux sept décisions
concernées avant de les ratifier, et a consigné les écarts constatés dans les
ADR eux-mêmes (section « Mise en œuvre » d'ADR-0069 pour le lot 8) plutôt que
de les laisser au code. Les huit contrats publics que le code avait introduits
sans décision écrite sont rétrospectivement consignés par ADR-0091. Le reste de
la série reste à construire, dans l'ordre du plan de delivery.

## Alternatives écartées

- **Garder le core purement technique et laisser chaque projet faire son
  commerce.** Rejeté : chaque projet SaaS refait facturation, quotas et espace
  client — la duplication la plus coûteuse de l'entreprise, et la moins sûre.
- **Un produit « commerce » séparé, hors du core.** Rejeté : il aurait besoin
  du paiement, du grand livre, de l'identité des projets et des notifications du
  core — donc d'un couplage réseau sur tout, avec deux sources de vérité sur
  l'argent.
- **Un module unique « SaaS » qui mélange clients, argent et services.**
  Rejeté : les trois piliers ont des rythmes, des invariants et des surfaces
  d'attaque différents. Les séparer rend chacun testable et remplaçable.
- **Réservé aux projets de l'entreprise.** Rejeté : le coût marginal d'une
  organisation tierce est le cloisonnement (ADR-0069), déjà nécessaire entre
  projets internes. Fermer la porte maintenant forcerait une refonte plus tard.

## Conséquences

- `billing` et `developer-platform` quittent le statut de scaffold ; `portal`
  et `payout` sont créés. `fees` et `storage` restent hors périmètre.
- Les artefacts du graphe doivent concorder (G1, G1b) :
  `ModuleGraph.php`, `deptrac-modules.yaml`, les `composer.json` de module et
  racine, la règle PHPStan `ModulesMeetOnlyThroughContracts`.
- Le guide d'intégration devient le document unique d'un projet : identité,
  clés, clients, plans, abonnements, factures, usage, portail, paiement,
  notifications, webhooks.
- Le plan de delivery (addendum 2026-10-24) découpe la cible en lots ; aucun
  lot ne commence sans l'acceptation des ADR qu'il réalise.
