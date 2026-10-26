# Plan de bascule et d'extinction de la plateforme transitoire

> **Document de transition** (ADR-0068 §6). Décision : ADR-0089. Exécuté au
> lot 17 du plan de delivery.

- **Date** : 2026-10-24 · **Version** : 1.0 · **Statut** : **ratifié** le 2026-10-27

## 1. Hypothèse de départ

**Aucune donnée de `v_cam-services-center` n'est à conserver** — décision de
gestion à **confirmer** par la direction (cadrage §7.4). La bascule est donc
« à blanc » : on reconstruit dans le core, on ne transfère pas.

## 2. Préalables (avant le temps 0)

- Lots 10 à 13 livrés et recettés sur le staging.
- Pour chaque application cliente : organisation et projet créés, clés émises
  (portées choisies), endpoint de webhook enregistré **et testé**, application
  prestataire enregistrée, plans publiés, portail configuré
  (`allowed_return_urls`), session de canal liée si l'application notifie.
- Défauts bloquants de l'application corrigés par son équipe (cloisonnement
  fail-open en priorité — cadrage §6).
- TTL DNS du domaine transitoire abaissé à 300 s.

## 3. Variante si la direction refuse la bascule à blanc

Reprise **partielle**, scriptée et rejouable :

| Objet | Repris | Comment |
|---|---|---|
| Clients | oui | export CSV → `PUT /v1/customers/{ref}` (idempotent) |
| Plans | oui | recréés via l'API catalogue, prix convertis en unités mineures |
| Abonnements en cours | oui, comme **périodes offertes** jusqu'à leur date de fin (`kind = complimentary`, motif « reprise ») | aucune fausse facture ni faux paiement |
| Paiements, jetons, journaux | **non** | données inexploitables ou sans valeur |

Rapprochement : nombre de clients et d'abonnements actifs égal des deux côtés,
contrôlé par un script, signé par la direction.

## 4. Les cinq temps

| Temps | Action | Critère de sortie |
|---|---|---|
| **0 — Gel** | plateforme transitoire en lecture seule (plus de création ni modification) | aucune écriture métier depuis 7 jours |
| **1 — Bascule** | application par application : nouvelle clé déployée, clients déclarés, abonnements créés, webhooks consommés, ancien secret révoqué | recette bout-en-bout verte (spec fonctionnelle §F8, R-15) avec l'application réelle |
| **2 — Coexistence** | la plateforme transitoire reste joignable en lecture seule pour les opérateurs | 14 jours sans aucun appel métier entrant |
| **3 — Coupure** | redirection DNS vers une page d'information ; secrets retirés de son environnement | 7 jours sans trafic |
| **4 — Retrait** | dépôt archivé en lecture seule ; base exportée, chiffrée, puis supprimée ; secrets révoqués chez tous les fournisseurs | liste §5 entièrement cochée |

La bascule est pilotée par la **recette**, pas par une date. La recette est
exécutée **avant** et **après** la bascule de chaque application.

## 5. Secrets à révoquer

| Secret | Où | Quand |
|---|---|---|
| Secrets d'application (`applications.api_secret`) | base transitoire | temps 1, à l'émission de la clé du core |
| Clé partagée `SAAS_API_KEY` de l'application de gestion | environnement de l'application | temps 1, remplacée par la clé du core |
| Identifiants de l'agrégateur de paiement utilisés par la plateforme | environnement transitoire + console de l'agrégateur | temps 3 |
| Identifiants de base de données | hébergeur | temps 4 |
| Clé d'application Laravel, secrets SMTP, jetons divers | environnement transitoire | temps 3 |
| Entrées DNS | registraire | temps 4 |

## 6. Rôles

| Rôle | Temps 0 | Temps 1 | Temps 2 | Temps 3 | Temps 4 |
|---|---|---|---|---|---|
| Personnel de la plateforme | gèle | crée organisations, projets, clés ; accompagne | surveille | coupe | archive, révoque |
| Équipe de l'application | — | déploie la clé, déclare les clients, consomme les webhooks | corrige | — | — |
| Direction | confirme l'hypothèse §1 | signe la recette | — | — | signe la clôture |

## 7. Risques

| Risque | Parade |
|---|---|
| Une application appelle encore la plateforme transitoire | métriques d'accès pendant la coexistence ; contact avant coupure |
| Un secret oublié | liste §5 cochée, temps 4 non clos sinon |
| Cache DNS | TTL abaissé à l'avance |
| Recette dépendante d'un vrai numéro et d'une vraie session de messagerie | session créée par l'exploitant, numéro de l'équipe, simulateur de paiement |

## 8. Clôture

- [ ] Recette verte avant et après bascule pour chaque application.
- [ ] 14 jours sans appel métier, 7 jours sans trafic.
- [ ] Tous les secrets révoqués.
- [ ] Documents de transition retirés du core (fin de la dérogation, ADR-0068 §6).
