# ADR-0089 — Absorption de la plateforme transitoire : correspondance, sort des mécanismes, extinction

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D2 couplage · D7 organisationnelle

## Contexte

Une plateforme transitoire, sur une autre pile (Laravel 11, PHP 8.2,
MariaDB 10.7) et une autre base, porte aujourd'hui : un portail client, des
abonnements et quotas, un proxy de notifications, un encaissement direct chez
un agrégateur, deux mécanismes de SSO et un back-office. Faits vérifiés
(détail dans `docs/specs/inventaire-verifie-fusion.md`) :

- deux flux d'authentification concurrents : un jeton à usage unique
  fonctionnel, et un flux JWT **cassé** (comparaison d'un secret chiffré, refus
  systématique) ;
- une synchronisation de clients vers l'application **rompue** (chemin,
  en-têtes et corps incompatibles) ;
- un callback de paiement **non signé** qui active des abonnements ;
- une table de paiements sans montant, ni statut, ni devise (blob de requête) ;
- des quotas décrémentés à la main ;
- un mot de passe par défaut identique pour tous les opérateurs.

Ce document de décision ne nomme pas les dépôts ; les documents de transition
le font.

## Décision

### 1. Rien n'est porté : chaque brique est absorbée, recodée ou retirée

| Brique transitoire | Sort | Cible |
|---|---|---|
| Application (clé, secret, scopes, IP) | recodée | `Project` + clés à portée (ADR-0082) |
| Client d'une application | recodé | `customers` (ADR-0070) |
| Catalogue d'abonnements et de forfaits | recodé | `plans` + `plan_versions` (ADR-0071) |
| Pivots datés client ↔ abonnement | recodés | `subscriptions` + `subscription_periods` (ADR-0072) |
| Table de paramètres (dates + quotas en colonnes nulles) | recodée | périodes (ADR-0072) + compteurs d'usage (ADR-0074) |
| Paiements (blob) | retirés | paiements du core (ADR-0073, ADR-0076) |
| Encaissement direct + callback non signé | retirés | `payment` (ADR-0051, ADR-0077) |
| Portail client | recodé | `portal` (ADR-0075) |
| Back-office | recodé | console `admin` (ADR-0080) |
| Jeton à usage unique (SSO) | recodé | sessions de portail créées par le projet (ADR-0075) |
| Flux JWT, refresh tokens, `jti` révoqués | **retirés sans remplacement** | — (ADR-0084 §5) |
| Synchronisation de clients vers l'application | **retirée** | inversion : le projet déclare ses clients (ADR-0084) |
| Proxy de notifications, service de notification factice | retirés | `/v1/notifications/*` existants |
| Alertes d'expiration par e-mail | recodées | événements `subscription.*`, `usage.*` (le projet notifie) |
| Opérateurs (2FA par code en clair) | recodés | comptes console + MFA TOTP (ADR-0069) |
| Référentiels géographiques | retirés | restent dans les applications |

### 2. Aucune reprise de données

Décision de gestion à confirmer par la direction : **aucune donnée de la
plateforme transitoire n'est à conserver**. La bascule se fait à blanc : clés
réémises, clients redéclarés par les projets, plans recréés. L'historique
financier transitoire n'est pas repris — et ne pourrait pas l'être fidèlement
(paiements sans montant ni statut).

Si la direction ne confirme pas, un plan de reprise **partielle** est appliqué
(clients et plans seulement, jamais les paiements), décrit dans le plan de
bascule.

### 3. Extinction pilotée par la recette, pas par une date

1. **Gel** de la plateforme transitoire (lecture seule).
2. **Bascule** projet par projet : clés réémises, clients déclarés,
   abonnements créés, webhooks testés ; **recette bout-en-bout verte**.
3. **Coexistence bornée** (14 jours sans appel métier).
4. **Coupure** : DNS redirigé, secrets retirés.
5. **Retrait** : dépôt archivé, base exportée puis supprimée, secrets
   révoqués chez tous les fournisseurs.

Les secrets de la plateforme transitoire ne coexistent jamais avec ceux du
core : réémission puis révocation.

## Alternatives écartées

- **Fusionner les dépôts.** Rejeté : deux piles, deux bases, deux conventions.
- **Garder la plateforme transitoire comme portail.** Rejeté : deux sources de
  vérité sur les clients, les abonnements et l'argent.
- **Réparer le flux JWT.** Rejeté : plus faible que le jeton à usage unique et
  sans usage réel.
- **Reconstituer les paiements depuis le blob.** Rejeté : produirait un
  historique faux.

## Conséquences

- Les documents de transition (expression du besoin, cadrage, inventaire,
  correspondance, plan de bascule et d'extinction) sont les seuls à nommer les
  dépôts ; ils sont retirés du core après l'extinction.
- Les défauts relevés dans les applications voisines (cloisonnement
  fail-open, permissions non appliquées côté API, mot de passe temporaire dans
  une réponse d'API, oracle d'existence de tenant) sont **signalés** dans le
  cadrage et corrigés par leurs équipes — hors périmètre du core.

## Vérification

- Recette bout-en-bout verte **avant** et **après** la bascule.
- Liste des secrets à révoquer entièrement cochée avant la clôture.
