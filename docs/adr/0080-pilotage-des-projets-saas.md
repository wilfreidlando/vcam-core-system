# ADR-0080 — Pilotage des projets SaaS : la console de portefeuille

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D5 contrat · D7 organisationnelle
- **Amende** : ADR-0045 / ADR-0062 (listings console)

## Contexte

La console livrée (`packages/admin`) sert l'**exploitation technique** :
audit, file des lettres mortes, réconciliation de statut, listings génériques,
création d'organisations, de projets et d'applications prestataire.

Elle ne répond pas aux questions d'un responsable de produit SaaS : combien
de clients actifs, combien en essai, combien en impayé, quel revenu récurrent,
combien encaissé ce mois, combien nous doit-on, qui va expirer, quel projet
progresse ou décline, et ce projet est-il correctement branché ?

## Décision

### 1. Trois niveaux de vue, filtrés par la portée d'accès (ADR-0069)

| Niveau | Pour qui | Contenu |
|---|---|---|
| **Portefeuille** | rôle de plateforme ; `org_owner`/`org_admin`/`org_finance` (leur organisation) | un tableau des projets accessibles avec leurs indicateurs clés et leur tendance |
| **Projet** | membres de l'organisation du projet | indicateurs détaillés, onboarding, santé technique, raccourcis d'action |
| **Client** | `org_support`/`org_finance` et plus | fiche client : abonnements, périodes, factures, paiements, usage, portail (ouvrir une session de support en lecture) |

### 2. Indicateurs, définis une fois

| Indicateur | Définition | Source |
|---|---|---|
| Clients actifs | clients ayant ≥ 1 abonnement `trialing`, `active` ou `past_due` | `billing` |
| Essais · Impayés · En pause | abonnements par statut | `billing` |
| **Revenu récurrent mensuel (MRR)** | Σ des prix des abonnements `active` et `past_due`, normalisés au mois (jour ×30/n, semaine ×30/7n, mois /n, année /12n), **par devise**, sans conversion | `billing` |
| Nouveaux · Perdus (mois) | abonnements activés · passés `canceled`/`expired` dans le mois | `billing` |
| Taux d'attrition | perdus du mois / actifs au début du mois | `billing` |
| **Encaissé** (période) | Σ débits des comptes `clearing:*` et `manual_receipts:*` du projet | **grand livre** |
| **Créances** | Σ soldes `customer_receivable:*` ; ventilées 0–30 / 31–60 / > 60 jours | **grand livre** + échéances des factures |
| Exceptions d'argent ouvertes | nombre et montant par sévérité (ADR-0077) | `payment` |
| Usage | top compteurs, clients au-delà de 80 % | `billing` |
| Santé technique | lettres mortes webhook, paiements bloqués, taux d'erreur API, dernier appel | `webhook`, `payment`, métriques |

Les montants **se lisent dans le grand livre**, jamais dans un compteur
applicatif : c'est ce qui rend un chiffre de recette opposable.

### 3. Historique : instantanés quotidiens

```sql
CREATE TABLE billing_daily_metrics (
  project_id      uuid NOT NULL,
  day             date NOT NULL,
  currency        char(3) NOT NULL,
  mrr_minor       bigint NOT NULL,
  active_customers integer NOT NULL,
  trialing        integer NOT NULL,
  past_due        integer NOT NULL,
  new_subscriptions integer NOT NULL,
  churned         integer NOT NULL,
  collected_minor bigint NOT NULL,
  receivables_minor bigint NOT NULL,
  computed_at     timestamptz NOT NULL DEFAULT now(),
  PRIMARY KEY (project_id, day, currency)
);
```

Calculé chaque nuit (et recalculable sur une plage) par un job idempotent. Les
tableaux de bord lisent les instantanés pour les courbes et le temps réel pour
le jour courant — aucune requête lourde sur les tables transactionnelles.

### 4. Onboarding d'un projet : une liste de contrôle calculée

Pour chaque projet, la console affiche l'état de branchement :

- clé d'API émise et utilisée récemment ;
- endpoint de webhook enregistré **et** test de livraison réussi ;
- application prestataire de paiement enregistrée ;
- au moins un plan publié ;
- portail personnalisé et `allowed_return_urls` renseignées ;
- session de canal de notification liée (si le projet notifie) ;
- attribution obligatoire des usages activée dès qu'un plan publié porte une
  limite (ADR-0074 §6).

Un projet `active` à qui il manque un élément obligatoire pour ce qu'il
utilise est signalé.

### 5. Les actions sont celles des ADR, pas des raccourcis

La console n'invente aucune opération : elle expose celles décidées ailleurs
(clients ADR-0070, catalogue ADR-0071, abonnements ADR-0072 §6, factures et
encaissements hors ligne ADR-0073, exceptions ADR-0077, versements ADR-0079,
clés ADR-0082, export ADR-0088), avec leurs permissions et leurs audits.

### 6. Ce que la console ne fait pas

- Pas d'indicateurs métier d'un projet (« nombre d'élèves ») : le projet les
  calcule chez lui, ou les exprime comme compteurs d'usage.
- Pas de graphiques techniques dupliqués : métriques et traces restent dans la
  pile d'observabilité (ADR-0085) ; la console y renvoie.
- Pas de conversion de devises : chaque indicateur monétaire est **par devise**.

## Alternatives écartées

- **Calcul à la volée sur les tables transactionnelles.** Rejeté : coûteux à
  l'échelle, et un historique recalculé change quand les données changent.
- **Un outil de BI externe branché sur la base.** Rejeté : accès direct au
  schéma interne (contraire à ADR-0088), cloisonnement par organisation à
  refaire.
- **Revenu lu dans les factures plutôt que le grand livre.** Rejeté : une
  facture n'est pas un encaissement ; seul le grand livre dit ce qui est entré.

## Conséquences

- `billing` expose `Contracts\ProjectMetrics` ; `ledger` expose les lectures
  par période (ADR-0076 §4).
- `admin` gagne les écrans Portefeuille, Projet, Client, Exceptions, Créances.
- Chaque bloc doit correspondre à une décision réelle de l'exploitant ; un
  bloc qui n'en sert aucune est retiré.

## Vérification

- Tests des définitions d'indicateurs sur un jeu de données de référence
  (MRR avec intervalles mixtes, attrition, créances par ancienneté).
- Test de cloisonnement : un `org_viewer` de l'organisation A ne voit aucun
  chiffre de l'organisation B, y compris agrégé.
- Test : recalcul d'un instantané passé → résultat identique (idempotence).
