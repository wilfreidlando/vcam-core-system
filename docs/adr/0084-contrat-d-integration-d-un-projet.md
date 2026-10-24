# ADR-0084 — Le contrat d'intégration d'un projet : ce que le projet fait, ce que le core fait

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D1 périmètre · D2 couplage · D3 données/PII · D5 contrat

## Contexte

Un projet SaaS qui se branche doit savoir précisément :

- qui crée ses tenants et ses clients ;
- comment il apprend qu'un client a payé, expiré ou dépassé sa limite ;
- qui authentifie ses utilisateurs ;
- qui cloisonne ses données.

Le dispositif transitoire faisait écrire la plateforme dans l'application
(synchronisation de clients vers l'application, désactivation de tenant par
appel entrant) — chaînage aujourd'hui rompu, et modèle où deux systèmes se
disputent la vérité.

## Décision

### 1. Le projet possède son domaine ; le core possède le commerce

| Sujet | Propriétaire | Mécanisme |
|---|---|---|
| Tenants, utilisateurs finaux, rôles internes, données métier | **le projet** | chez lui |
| Clients payeurs (copie minimale), plans, abonnements, factures, usage, argent | **le core** | API + webhooks |
| Authentification des utilisateurs finaux | **le projet** | chez lui |
| Cloisonnement des données internes du projet | **le projet** | chez lui, **fail-closed** (§4) |

Le core n'appelle **jamais** l'application pour créer ou modifier quelque chose
chez elle. Il émet des événements ; l'application décide.

### 2. Séquence de branchement d'un client

```
1. Le projet crée son tenant chez lui (son code, son administrateur).
2. PUT /v1/customers/{external_ref}            → le core connaît le client
3. POST /v1/subscriptions {customer_ref, plan_code, trial_days?}
4. (paiement par le portail ou POST /v1/invoices/{id}/pay)
5. webhooks : subscription.activated, invoice.paid, usage.threshold_reached, subscription.past_due, subscription.expired…
6. Le projet applique la conséquence chez lui (accès, bandeau, blocage).
```

### 3. Les droits sont lus, les événements déclenchent

- **Source de vérité** : `GET /v1/customers/{ref}/entitlements` — état de
  l'abonnement, fonctionnalités, compteurs. Réponse cachable 60 s par le
  projet (`Cache-Control: private, max-age=60`, `ETag`).
- **Déclencheurs** : les webhooks. Livraison **au moins une fois**, ordre non
  garanti : chaque événement porte l'`id` (déduplication), `occurred_at` et la
  `version` de la ressource ; un projet ignore un événement dont la version est
  inférieure à celle qu'il connaît, et relit la ressource en cas de doute.
- Un projet qui rate des événements se resynchronise par la lecture : aucune
  perte d'état possible.

### 4. Exigence imposée au projet : cloisonnement fail-closed

Le core ne peut pas filtrer les tables d'un projet. Il exige, dans le guide
d'intégration et la liste de contrôle d'onboarding (ADR-0080 §4) :

- toute requête dont le tenant n'est pas résolu est **refusée**, jamais
  traitée sans filtre ;
- la colonne de tenant d'un utilisateur n'est jamais nullable pour un
  utilisateur de tenant ;
- les jobs et commandes portent explicitement leur tenant ;
- les droits d'un client sont vérifiés via `entitlements` avant toute action
  facturable.

### 5. Le core n'est pas un fournisseur d'identité pour les utilisateurs finaux

Il ne stocke ni ne vérifie leurs identifiants ; il n'émet pas de jeton à leur
nom. Le portail client (ADR-0075) authentifie le **client payeur** dans le
seul portail. Point de réévaluation : si au moins deux projets demandent un
compte unique entre applications, un ADR étudiera un fournisseur OpenID
Connect dédié — avec son propre modèle de données et de menace.

### 6. Le projet s'authentifie par sa clé, c'est tout

`Authorization: Bearer pk_…` (portées, ADR-0082). Aucun en-tête maison, aucun
secret partagé supplémentaire, aucune liste d'IP imposée (optionnelle par
clé). Le projet vérifie la signature de chaque webhook (secret par endpoint,
horodatage, fenêtre de 5 min).

## Alternatives écartées

- **Le core crée le tenant dans l'application.** Rejeté : le core ne connaît
  pas le modèle métier du projet ; deux écrivains du même domaine divergent.
- **Le projet interroge le core à chaque requête.** Rejeté : charge et latence ;
  la lecture cachée + les événements suffisent.
- **Le core filtre les données du projet (scope générique).** Rejeté : un
  filtre sur des tables inconnues ne peut pas être correct.
- **Le core émet des jetons pour les utilisateurs finaux.** Rejeté : en ferait
  un annuaire d'utilisateurs, hors périmètre et à forte exposition.

## Conséquences

- Le guide d'intégration est réécrit autour de cette séquence, avec des
  exemples `curl` exécutés en CI contre une instance réelle.
- Toutes les charges utiles d'événements de `billing` portent `version`.
- La liste de contrôle d'onboarding vérifie qu'un endpoint de webhook est
  enregistré et testé avant l'activation de la facturation d'un projet.

## Vérification

- Recette bout-en-bout avec une application réelle : tenant → client →
  abonnement → paiement → webhook → accès ouvert ; puis expiration → accès
  fermé.
- Test : événements livrés dans le désordre → l'état final du projet de
  référence (client de test) est correct grâce à `version`.
