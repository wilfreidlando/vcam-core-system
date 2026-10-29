# Carte des capacités — la plateforme de projets SaaS, état par capacité

- **Date** : 2026-10-28 · **Version** : 2.4 · **Statut** : référence produit
  (ne construit rien)
- **Réf.** : ADR-0068 à ADR-0092 · plan de delivery, addendum du 2026-10-24

Légende : **●** livré · **◐** décidé (ADR accepté), lot à construire · **○** point
d'extension prévu (ADR-0090), non planifié · **✗** refusé (liste négative).

La série ADR-0068 à ADR-0090 a été **ratifiée le 2026-10-27** ; ADR-0091 consigne
les décisions que le code avait introduites sans ADR. Les lots 8, 9, 10 et 11
sont **livrés** : leurs lignes sont passées de **◐** à **●**. Les lots 12 et 13 le sont aussi depuis le 2026-10-29. Le plan pour tout terminer : [plan-livraison-complet.md](plan-livraison-complet.md).

## 1. Services de plateforme

| Capacité | État | Où |
|---|---|---|
| Organisations, projets | ● | `identity` |
| Cycle de vie d'un projet (`active` / `suspended` / `archived`) | ● (lot 8) | ADR-0069 |
| Console scopée par organisation, rôles `org_*`, invitations | ● (lot 8) | ADR-0069 |
| Comptes console ouverts par jeton d'activation, jamais par un mot de passe choisi par le système | ● (lot 9) | ADR-0081 S-07 |
| Clés d'API — environnement unique (pas de sandbox/live), chaque clé portée | ● | ADR-0066 |
| Clés multiples à portée, rotation, restriction réseau | ● (lot 8) | ADR-0082 |
| Paiement : canal, routage, MyCoolPay, idempotence, réconciliation de statut | ● | ADR-0038, ADR-0051, ADR-0067 |
| Vérification du montant confirmé | ● (lot 9) | ADR-0077 |
| Notifications multicanal, WhatsApp (WAHA) | ● | ADR-0041, ADR-0057 |
| Adaptateurs SMS, e-mail | ○ | ADR-0090 |
| Webhooks signés, retry, DLQ, rejeu, test | ● | ADR-0040, ADR-0063 |
| Rotation du secret de webhook (double signature) | ● (lot 8) | ADR-0082 |
| Audit | ● ; immuabilité en base et chaîne d'empreintes quotidiennes ● (lot 8) | ADR-0081 S-16 |
| Limitation de débit par projet, clé, classe | ◐ lot 15 | ADR-0085 |
| Métriques par projet, objectifs de service | ◐ lot 15 | ADR-0085 |
| Contrat versionné, dépréciation, changelog | ● (`/v1`, G9) ; politique ◐ lot 16 | ADR-0028, ADR-0086 |
| Catalogue de capacités, pages de démarrage testées | ◐ lot 16 | ADR-0087 |
| Horloges de test (staging) | ◐ lot 16 | ADR-0087 |
| Listings publics par curseur, export par projet | ◐ lot 15 | ADR-0088 |
| OAuth 2.0 pour intégrations tierces | ○ | ADR-0090 |
| Inscription libre-service d'organisations, SDK | ✗ (pour l'instant) | ADR-0087 |

## 2. Commerce SaaS

| Capacité | État | Où |
|---|---|---|
| Clients d'un projet (identité minimale chiffrée) | ● (lot 10) | ADR-0070 |
| Plans et versions immuables, fonctionnalités nommées par le projet | ● (lot 10) | ADR-0071 |
| Abonnements : essai, périodes, renouvellement, impayé, grâce, pause, annulation | ● (lot 10) | ADR-0072 |
| Changement de plan à l'échéance ou immédiat (sans prorata) | ● (lot 10) | ADR-0072 §5 |
| Console de facturation : clients, offres, abonnements d'un projet | ● (lot 10) | ADR-0092 §4 |
| Renouvellement des périodes par ordonnanceur (`billing:advance-subscriptions`) | ● (lot 10) | ADR-0072 §4 |
| Déclaration du paiement d'une période (constat, **aucun mouvement d'argent**) | ● (lot 10) ; refuse une période dont la facture n'est pas soldée ● (lot 11b) | ADR-0092 §2, ADR-0094 §8 |
| Prorata | ○ | ADR-0090 |
| Remises, codes promo | ○ (en v1 : plan privé remisé) | ADR-0071 §6 |
| Factures, numérotation, documents HTML | ● (lot 11a) — le PDF attend sa bibliothèque (ADR-0094 §14) | ADR-0073, ADR-0094 |
| Paiement d'une facture en ligne, tentatives multiples | ● (lot 11b) | ADR-0073 §2 |
| Encaissement hors ligne, validation explicite | ● (lot 11b) — ADR-0095 a retiré les quatre yeux | ADR-0073 §4, ADR-0095 |
| Reçus | ◐ — le lot est livré, le **rendu** du reçu reste à écrire | ADR-0073 §5 |
| Prélèvement récurrent | ○ | ADR-0090 |
| Usage idempotent, quotas atomiques, seuils | ● (lot 12) | ADR-0074 |
| Décompte automatique des notifications | ● (lot 12) | ADR-0074 §5 |
| Facturation à l'usage | ○ (le journal en est la source) | ADR-0090 |
| Portail client hébergé, personnalisable (tableau de bord, offres, paiement, renouvellement) | ● (lot 13) ; connexion directe par code ◐ lot 17 | ADR-0075, ADR-0097, ADR-0098 |
| Domaine personnalisé du portail | ○ | ADR-0090 |
| Facturation des projets par la plateforme elle-même | ○ | ADR-0090 |

## 3. Grand livre

| Capacité | État | Où |
|---|---|---|
| Double entrée, somme nulle et immuabilité en base, soldes matérialisés | ● | ADR-0005, ADR-0033 |
| Wallets, transferts à somme nulle, sorties enregistrées | ● | ADR-0065 |
| Toute somme écrite, plan de comptes par projet | ● (lot 9) | ADR-0076 |
| Compte de règlement par prestataire et reclassement motivé | ● (lot 9) | ADR-0076 §2 |
| Isolation inter-projets en base (G13) | ● (lot 9) | ADR-0076 §5 |
| Lectures par période, balance de vérification, relevés | ● (lot 9) | ADR-0076 §4 |
| Corrections d'exploitation à quatre vues (contre-passation) | ● (lot 9) | ADR-0076 §6 |
| Exceptions d'argent, résolution à quatre yeux | ● (lot 9) | ADR-0077 |
| Contrôles d'invariants horaires | ● (lot 9) | ADR-0078 |
| Registre des rapprochements (niveau A) | ● (lot 9) | ADR-0078 §5 |
| Rapprochement relevé et solde du prestataire | ◐ lot 18 | ADR-0078 |
| Versements sortants | ◐ lot 19 | ADR-0079 |
| Commissions et barèmes (`fees`) | ○ | ADR-0090 |

## 4. Pilotage et sécurité

| Capacité | État | Où |
|---|---|---|
| Console de portefeuille : MRR, attrition, encaissé, créances, exceptions, usage | ◐ lot 14 — indicateurs, portefeuille et fiche client livrés ; instantanés quotidiens et courbes à faire | ADR-0080 |
| Liste de contrôle d'onboarding d'un projet | ◐ lot 14 | ADR-0080 §4 |
| Modèle de menace, exigences S-01 à S-20 | ● (modèle écrit, lot 8) ; les contrôles sont vérifiés à chaque lot | ADR-0081 |
| Garde-fous G13, G14, G15, G16 | ● — les quatre sont actifs et vérifiés par `make guard-test` | ADR-0081, ADR-0069 |
| Chiffrement des données personnelles, effacement, conservation | ● (chiffrement et index aveugle, lot 8) ; ● (lot 10) (effacement, conservation) | ADR-0083 |
| Cloisonnement des données internes des applications | ✗ | ADR-0084 |
| Fournisseur d'identité des utilisateurs finaux | ✗ (condition de réexamen écrite) | ADR-0084 |
