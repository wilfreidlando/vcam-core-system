# ADR-0088 — Réversibilité : tout ce qu'un projet produit est lisible et exportable

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D2 couplage · D5 contrat

## Contexte

Un projet — et a fortiori une organisation tierce — doit pouvoir **quitter**
la plateforme ou archiver ses données sans dépendre du schéma interne. Les
modules ne se parlent que par `Contracts/` (G3c) et le contrat public est
contrôlé (G9), mais l'API publique n'offre pas de **listing** de la plupart des
objets (les listings existent seulement dans la console), et aucun export par
projet n'existe.

## Décision

### 1. L'unique interface est le contrat public

Aucun accès direct à la base n'est fourni à un projet, ni documenté. Aucune
réponse ne contient de structure interne.

### 2. Tout objet public est listable par curseur

`GET /v1/{customers, subscriptions, invoices, payments, payouts, usage,
notifications, webhook-endpoints}` :

- pagination par curseur opaque (`cursor`, `limit` ≤ 100), ordre stable
  `(created_at, id)` ;
- filtres simples et indexés (`status`, `customer_ref`, `created_from`,
  `created_to`) ;
- réponse `{data, next_cursor}`.

### 3. Export par projet

- `POST /v1/exports` (console `org_owner`/`org_admin`, ou clé avec portée
  `exports:write`) crée un export asynchrone ; `GET /v1/exports/{id}` donne
  l'état et un lien de téléchargement **signé**, valable 15 minutes.
- Contenu : JSON Lines par type d'objet, au **format du contrat public**, plus
  les transactions du grand livre du projet et sa balance à la date de
  l'export.
- Chaque export est audité (qui, quand, quoi) ; le fichier est chiffré au
  repos et supprimé après 7 jours.

### 4. Parcours de sortie

1. export complet ; 2. révocation des clés ; 3. désactivation des endpoints de
webhook ; 4. passage du projet en `archived` (ADR-0069) ; 5. conservation selon
ADR-0083. Les écritures du grand livre ne sont jamais supprimées.

## Alternatives écartées

- **Accès en lecture à la base pour l'export.** Rejeté : fige le schéma
  interne et contourne le cloisonnement.
- **Pagination par numéro de page.** Rejeté : instable sous insertion, coûteuse
  sur de grandes tables.
- **Export synchrone.** Rejeté : délai et mémoire imprévisibles pour un gros
  projet.

## Conséquences

- Nouvelle portée `exports:write` (ADR-0082).
- Les ports de lecture des modules gagnent une variante paginée par curseur.

## Vérification

- Test : listing parcouru en entier pendant des insertions concurrentes →
  aucun doublon, aucune omission parmi les objets antérieurs au début.
- Test : lien d'export expiré → `403`.
- Test : export d'un projet ne contient aucun objet d'un autre projet.
