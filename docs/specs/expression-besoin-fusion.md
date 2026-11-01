# Expression du besoin — une plateforme unique pour gérer des projets SaaS

> **Document de transition** (ADR-0068 §6) : il peut nommer les dépôts
> voisins. Il est retiré du core après l'extinction de la plateforme
> transitoire.

- **Date** : 2026-10-24 · **Statut** : **ratifié** le 2026-10-27 · **Réf.** : ADR-0068 à ADR-0091

## 1. Pourquoi

L'entreprise édite et opère des produits SaaS, et veut pouvoir en héberger
pour d'autres organisations. Chaque produit a besoin des mêmes briques :
identifier, vendre (clients, plans, abonnements, factures), mesurer et limiter
l'usage, servir un espace client, encaisser et compter l'argent, notifier,
rendre compte.

Aujourd'hui ces briques sont réparties entre :

- le **core** (`vcam-core-system`) : identité machine, paiement, wallet, grand
  livre, notification, webhook, audit, console — livré et durci, mais sans
  commerce ni pilotage ;
- la **plateforme transitoire** (`v_cam-services-center`) : portail client,
  abonnements, quotas, SSO, encaissement, back-office — sur une autre pile,
  avec des mécanismes cassés ou non sécurisés ;
- chaque **application**, qui refait sa part (quotas, accès, encaissements
  déclarés à la main).

Résultat : deux réponses à chaque question (qui est client ? qui a payé ?
combien reste-t-il ?), et aucun endroit où l'argent est compté en entier.

## 2. Pour qui

- **Les organisations propriétaires de projets SaaS** — l'entreprise et des
  organisations tierces — qui veulent piloter leurs projets : clients, revenu,
  impayés, usage.
- **Les projets SaaS** (applications) — qui veulent brancher le commerce et les
  services sans rien reconstruire, et sans que le core connaisse leur métier.
- **Les clients des projets** — qui veulent payer, télécharger leurs reçus et
  suivre leur usage seuls.
- **Le personnel de la plateforme** — une console, un audit, une vue de
  portefeuille.

## 3. Ce que le besoin n'est pas

- Porter du code d'un dépôt à l'autre.
- Faire du core un ERP ou une comptabilité générale.
- Gérer les utilisateurs finaux des applications ou leurs données métier.

## 4. Critères de réussite

1. Une seule source de vérité par notion : organisation, projet, clé, client,
   plan, abonnement, facture, usage, argent.
2. Toute somme est au grand livre, cloisonnée par projet, rapprochable.
3. Un nouveau projet SaaS — interne ou d'une organisation tierce — se branche
   **sans modifier le core**.
4. Aucune donnée d'une organisation n'est visible d'une autre ; contrôles
   automatisés en CI.
5. La plateforme transitoire disparaît, sans règle résiduelle.
6. Le core reste conforme : `make ci`, `make guard-test`, `make arch`,
   `make stan` verts ; aucun contrat rompu sans nouvelle version majeure.

## 5. En une phrase

> Donner à toute organisation un moyen **unique, générique et sûr** de vendre,
> mesurer, encaisser et piloter ses projets SaaS — sans que le projet connaisse
> un prestataire ou une table du core, et sans que le core connaisse le métier
> d'un seul projet.
