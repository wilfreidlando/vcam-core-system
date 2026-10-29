# ADR-0099 — `fees` et `storage` non nécessaires pour le moment ; relances, codes promo et e-mail renvoyés à une demande réelle

- **Statut** : Proposé — décision du commanditaire (« fees et storage ne sont pas nécessaires
  pour le moment », 2026-10-29) ; à ratifier par le comité d'architecture
- **Date** : 2026-10-29
- **Décideurs** : commanditaire (délégation) ; comité d'architecture (ratification)
- **Dérives concernées** : D1 périmètre
- **Amende** : ADR-0048, ADR-0068

## Contexte

Le plan de livraison complet (`docs/specs/plan-livraison-complet.md`) ne peut déclarer « tous les modules
livrés » tant que deux modules restent des coquilles vides sans décision : `fees` et `storage`.
ADR-0048 les a mis hors périmètre de la production, ADR-0068 a confirmé. Un module vide dans
l'arbre laisse croire qu'il viendra ; la gouvernance (§4) demande qu'un refus soit tracé.

## Décision

### 1. `fees` et `storage` : **non nécessaires pour le moment**, hors de la version de production

Porte 1 de la gouvernance (≥ 2 produits réels qui en ont besoin) non franchie : aucun produit
n'attend un moteur de frais ni un stockage de fichiers du Core. Les paiements portent déjà leur
montant ; les fichiers que manipule le Core (reçus, exports) sont des projections ou des liens
signés, pas un produit de stockage.

- les deux modules sont inscrits dans la **liste négative** (« ne pas construire sans nouvel ADR ») : ce n'est pas un refus définitif, c'est l'absence de besoin aujourd'hui ;
- leurs coquilles restent dans l'arbre **sans rien porter** : les retirer toucherait le graphe de
  modules, Deptrac et les secteurs de test pour un gain nul. Elles sont marquées « non nécessaire pour le moment » dans
  `CLAUDE.md` et dans le plan ;
- les rouvrir exige un ADR qui nomme les deux produits demandeurs.

### 2. Relances d'impayés : hors du Core

Le Core publie `subscription.renewal_due`, `invoice.issued`, `subscription.past_due`. **Le projet
notifie** son client (par ses propres canaux, ou en appelant `POST /v1/notifications/*`). Une
relance automatique du Core serait une composition implicite entre modules, interdite par ADR-0037
et ADR-0041. Le portail permet déjà de payer en un lien.

### 3. Codes promo et remises : après un cas d'usage réel

En v1, une remise s'exprime par une **version de plan privée** au prix remisé (ADR-0071 §6). Une
table de coupons est une extension d'ADR-0090, à écrire quand un projet la demande.

### 4. E-mail et SMS : un adaptateur par ADR, à la demande

Un canal ajouté = une entrée de registre + un adaptateur + un ADR (ADR-0090). Aucun n'est construit
sans projet demandeur.

## Conséquences

- Le plan de livraison compte désormais **tous les modules** : livrés, ou refusés par cet ADR.
- Aucun code n'est écrit ni supprimé par cette décision.
- Si un produit réel demande l'un de ces éléments, le chemin est un ADR nominatif, pas un correctif.
