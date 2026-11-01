# ADR-0103 — L'URL de retour du paiement : une liste blanche par projet, ou une redirection ouverte assumée

- **Statut** : Proposé
- **Date** : 2026-10-30
- **Décideurs** : comité d'architecture (dérives D1 périmètre · D5 contrat)
- **Dérives concernées** : D1 périmètre · D5 contrat

## Contexte

L'audit de sécurité du 2026-10-29 (annexe C, n° 18) a relevé que `POST /v1/payments/paylink`
accepte un `return_url` borné au schéma `https://` mais **sans restriction d'hôte** :

```php
'return_url' => ['nullable', 'url', 'starts_with:https://'],
```

La valeur est transmise au prestataire, qui redirige le navigateur du client vers elle.
C'est une **redirection ouverte** : un tiers peut envoyer la personne qui paie vers une page
de phishing qui imite la confirmation du paiement.

Le registre proposait de réemployer la liste blanche du portail (`allowed_return_urls`, dans
`portal_settings`), que `PortalSettings::allowsReturnUrl()` applique déjà — origine exacte
(schéma, hôte, port) et préfixe de chemin sur frontière de segment.

Trois faits changent l'analyse du problème :

1. **Le point d'entrée n'est pas public.** `return_url` n'est accepté que par
   `POST /v1/payments/paylink`, authentifié par une **clé API du projet** portant la portée
   `payments:write`. Il n'existe aucune route anonyme qui accepte une URL de retour.
2. **Celui qui peut la fixer est le projet.** La clé appartient au projet ; l'adversaire doit
   donc déjà détenir un accès métier au compte du client (clé volée, intégrateur malveillant,
   ou le projet lui-même qui détourne son propre client). Le gain de l'attaque est un
   phishing sur une personne que le projet a déjà le droit de rediriger vers une page de son
   choix — ce qu'il peut déjà faire par n'importe quel moyen.
3. **Le module `payment` ne peut pas lire la liste du portail.** Le graphe de modules
   interdit `payment → portal` (`portal → payment` existe déjà, ADR-0075). Le seul module
   commun à `payment`, `portal` et `billing` est `identity`, qui ne possède aujourd'hui
   aucun réglage par projet. Transposer la liste dans `identity` — ou la dupliquer dans une
   table `payment_settings` — déplacerait ou dupliquerait une donnée possédée par `portal`,
   pour une menace dont l'attaquant a déjà l'accès métier.

## Décision

**Le résiduel est assumé, et la raison est écrite : la `return_url` du paiement est une
destination choisie par le projet, pas une URL fournie par un visiteur.**

La règle `starts_with:https://` reste en place : elle ferme les schémas exotiques
(`javascript://`, `data:`…) que le filtre d'URL de PHP laisse passer, et qui se
réfléchiraient sur la page de retour du prestataire. C'est le risque technique réel, et il
est fermé.

L'allowlist par projet n'est **pas** transposée au paiement dans ce lot. Elle reste au
portail, où elle a un sens : la page de connexion directe du portail est publique, et
`return_url` y est le point de sortie d'une session ouverte par un lien à usage unique.

**La condition de réévaluation est explicite.** Si un jour le Core expose une route de
**creation de paiement accessible à un utilisateur final** — un paiement dans le navigateur du
client, sans clé API du projet — l'allowlist par projet redevient obligatoire, le jour même.
Ce n'est pas une échéance, c'est un déclencheur.

**Ce qui est tracé pour que la décision ne se perde pas** : la liste `allowed_return_urls`
reste le mécanisme de référence ; le code du portail et son test le démontrent ; la règle
`starts_with:https://` du paiement est testée ; et le registre des écarts (annexe C, n° 18)
porte cette analyse.

## Alternatives écartées

**Transposer `allowed_return_urls` dans `identity` pour la partager.** — Rejeté : `identity`
est le module des organisations, des projets et des comptes. Lui faire porter une liste
d'origines de redirection déplace une donnée possédée par `portal` vers un module qui n'a
rien à y faire, pour une menace dont l'adversaire a déjà l'accès métier. Le coût
architectural (migration de donnée possédée, nouveau port, arbitrage de responsabilité) n'est
pas justifié par le risque résiduel.

**Dupliquer la liste dans une table `payment_settings` (comme `billing_settings`).** — Rejeté
pour la même raison, et avec un défaut supplémentaire : deux listes à maintenir, qui
divergeraient. Le dépôt préfère une source unique (ADR-0090 §1 : « configuration par projet
en base », un point d'extension par capacité).

**Supprimer `return_url` du paiement.** — Rejeté : c'est un besoin réel du commerce. Un
intégrateur qui encaisse chez lui a besoin de savoir où renvoyer le client après le
paiement. Le retirer casserait l'API publique sans gain de sécurité mesurable.

**Accepter n'importe quel `http://` en local.** — Rejeté : le registre du paiement impose
`https` (ADR-0081 S-06). Le paiement ne doit jamais exposer son secret en clair sur le
réseau.

## Conséquences

**Ce qui reste vrai** : un projet qui le souhaite peut rediriger ses propres clients vers
n'importe quel hôte `https://` après un paiement. C'est un choix du projet, pas une
fuite.

**Ce qui est fermé** : les schémas exotiques (`javascript://`, `data:`) sont refusés par la
règle `starts_with:https://`, et une URL non-http est refusée par la règle `url` de
Laravel. C'est le risque technique réel, et il est couvert par un test.

**Ce qui est tracé** : l'analyse est dans ce ADR et dans le registre ; la liste blanche du
portail reste le mécanisme de référence pour toute redirection exposée publiquement.

**Point de réévaluation** : l'apparition d'une route de paiement accessible à un utilisateur
final, sans clé API du projet, rend l'allowlist obligatoire. Ce déclencheur est écrit dans
ce ADR pour ne pas dépendre de la mémoire de l'équipe.
