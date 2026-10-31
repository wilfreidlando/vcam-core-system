# 10 — Ce que le Core ne fait pas

*Ce chapitre est la référence technique des écarts assumés du Core. Aucun parcours ne le remplace : c'est le document à lire avant de promettre une fonctionnalité.*

Un manuel qui ne dit pas ce qui manque ment par omission. Ce chapitre liste les **écarts
assumés** : ce que le produit a décidé de ne pas faire, et pourquoi. Le mot important est
*assumé* — ce ne sont pas des bugs en attente, ce sont des décisions.

---

## 1. Les modules hors périmètre

Trois modules existent dans l'arborescence mais **n'ont jamais été construits**. Ce sont
des coquilles vides : rien à configurer, rien à installer, aucune route.

| Module | Ce qu'il aurait fait | Pourquoi il n'existe pas |
|---|---|---|
| `storage` | stockage de fichiers | Refusé : la décision est de ne pas ajouter de capacité de stockage tant qu'un besoin concret n'existe pas. Les fichiers passent aujourd'hui par des URL chez le client. |
| `fees` | moteur de frais (commission du prestataire, frais de service) | Refusé : la facturation des frais est une règle métier spécifique à un produit. Le Core ne la contient pas. |
| `developer-platform` | auto-service développeur (création de projet, documentation par clé) | Différé : la cible plateforme SaaS l'a validé, mais sa construction a été repoussée. Le module n'a jamais été écrit. |

> **Si un client vous demande « le Core prend-il une commission sur chaque paiement ? »,
> la réponse est non.** Les frais du prestataire ne sont pas comptabilisés : quand le
> prestataire les publie, ils ne sont pas repris dans le grand livre.

Le module `payout` (versements sortants) est dans la même situation : il est décrit dans
une décision d'architecture, il n'est pas construit.

---

## 2. Les comptes déclarés mais jamais écrits

L'écran de correction d'exploitation propose onze types de compte. **Deux d'entre eux
n'ont jamais été écrits par le code** :

| Type | Ce qu'il serait | Pourquoi il est vide |
|---|---|---|
| `payout_in_transit` | les versements en cours d'exécution | le module `payout` n'est pas construit |
| `provider_fees` | les frais retenus par le prestataire | le module `fees` n'est pas construit |

Le type `adjustment` (`« Compte de contrepartie des corrections »`) est, lui, **déclaré
mais jamais créé** : une correction choisit toujours **deux** types de comptes qui existent
déjà. Il n'apparaît pas dans la liste proposée par l'écran.

> **Concrètement :** si vous cherchez un montant dans un compte `provider_fees`, il n'y en
> a pas. Ce n'est pas une écriture manquante.

---

## 3. Le champ « bloqué » d'un portefeuille

Le solde d'un portefeuille a deux champs : **disponible** et **bloqué**. Le champ « bloqué »
existe en base et dans le modèle, mais **aucun mécanisme ne le renseigne** : il vaut
toujours zéro.

Il n'y a **pas de séquestre**, pas de gel de fonds, pas de retenue temporaire. Un virement
qui bloque de l'argent en attendant une confirmation n'existe pas.

> **Ne promettez pas à un client qu'un montant peut être « mis de côté » ou « en séquestre ».
> Ce n'est pas possible aujourd'hui.**

---

## 4. Ce que l'opérateur ne peut pas faire

| Geste | Pourquoi il n'existe pas |
|---|---|
| Modifier le solde d'un portefeuille depuis la console | le solde d'un portefeuille n'est mis à jour que par le module portefeuille, à partir d'écritures justifiées. La console est en **lecture seule** sur les portefeuilles. |
| Modifier une écriture au grand livre | les écritures sont immuables. Une correction **ajoute** une transaction qui contre-passe. |
| Changer le prix d'une facture émise | une facture émise est figée. Même règle pour une version d'offre publiée. |
| Modifier une clé d'API déjà créée | une clé est immuable. On la **fait tourner** ou on la **révoque**. |
| Récupérer un secret affiché une seule fois | il n'est pas stocké en clair. On en crée un nouveau. |
| Réactiver un client effacé | l'effacement est définitif. Le client se redéclare. |
| Modifier le profil du client depuis le portail | le profil du portail est en **lecture seule**. |
| Réactiver un abonnement résilié depuis le portail | il faut **souscrire à nouveau** (ou le renouveler si l'offre le permet). |

---

## 5. Ce que l'API ne fait pas

| Ce qui manque | Pourquoi |
|---|---|
| **Un endpoint « créditer un compte »** | la valeur n'entre que par un paiement confirmé ou par un transfert à deux jambes. Un crédit libre serait une porte ouverte. |
| **Un endpoint de modification d'une écriture** | même raison que la console : les écritures sont immuables. |
| **Un endpoint de modification d'une facture émise** | une facture émise est figée. |
| **Une liste des contacts d'un client** | l'e-mail et le téléphone sont masqués partout, dans la console comme dans le portail. Le projet connaît ses propres données. |
| **Un rendu de reçu** | le reçu est une **projection** de la facture et de ses encaissements. Rien n'est stocké, donc rien n'est rendu tel quel. |
| **Une « liste de tous les clients » pour un tiers** | l'isolation entre projets est structurelle : une écriture qui croise deux projets est refusée par la base. |

---

## 6. Les écarts assumés entre l'écran et son comportement

Trois écrans promettent plus que ce qu'ils font. Ce ne sont pas des bugs d'affichage : la
formulation est plus catégorique que le comportement.

| Écran | Ce que l'écran promet | Ce que le code fait |
|---|---|---|
| `« Nouvelle correction d'exploitation »` | le bandeau `« Quatre yeux, toujours »` dit que rien ne s'écrit tant qu'une seconde personne n'a pas validé | **sous le seuil de double validation**, la correction est écrite au grand livre immédiatement (le flash le dit : `« Correction proposée et écrite au grand livre : elle était sous le seuil de double validation. »`). Le seuil par défaut est `0` et le montant d'une correction est strictement positif : **en configuration standard, le bandeau est exact** — toute correction attend une seconde personne. L'écart n'apparaît que si `LEDGER_ADJUSTMENT_APPROVAL_THRESHOLD_MINOR` est configuré au-dessus de 0. |
| `« Livraisons de webhooks »` | l'URL `/admin/webhook-deliveries/dead` et le nom de la route évoquent les livraisons en échec | l'écran affiche **tout l'historique**, avec un filtre `« Statut »`. Le titre de la page est `« Livraisons de webhooks »`. |
| `« Portefeuille »` et `« Facturation par projet »` (barre latérale) | on attend une page dédiée | ces deux entrées de menu pointent toutes les deux vers `/admin/projects`. Il n'existe pas de liste globale de portefeuilles ni de facturation : les deux se pilotent **par projet**. |

Deux autres écarts, plus mineurs :

- Le rôle affiché dans la colonne `« Rôle »` de la liste des opérateurs est la **valeur
  brute** (`platform_admin`), alors que le pied de la barre latérale affiche un libellé
  lisible (`Administrateur de plateforme`).
- L'écran `« Nouvelle facture »` propose un bouton `« Créer le brouillon »` : c'est exact,
  **rien n'est émis** à ce stade. L'émission est une action séparée, avec second code.

---

## 7. Les invariants annoncés mais non contrôlés

Le contrôle automatique des invariants (toutes les heures) en vérifie **quatre** sur les
sept annoncés :

| Invariant | Contrôlé ? |
|---|---|
| Tout paiement réussi a son règlement | **oui** |
| Aucun règlement sans paiement réussi | **oui** |
| La balance du projet vaut zéro, par devise | **oui** |
| La somme des portefeuilles ne dépasse pas la somme des comptes `clearing` | **oui** |
| La somme des créances clients égale la somme du reste dû | **non** |
| Les compteurs d'usage correspondent à la somme du journal | **non** |
| Chaque facture payée a des imputations couvrant son total | **non** |

> **Les trois derniers ne sont pas contrôlés aujourd'hui.** Ne dites pas à un client « le
> Core vérifie que… » si c'est l'un de ces trois. Le contrôle de la **balance** et de
> l'**absence de valeur créée** est, lui, réel.

---

## 8. Ce que le projet décide, pas la plateforme

Plusieurs réglages appartiennent au **projet**, et vous n'êtes pas en position de les
changer seul — il faut que le client les décide.

| Réglage | Où il se décide |
|---|---|
| Les quotas et les fonctionnalités de chaque offre | l'offre, dans le catalogue du projet |
| Ce que le client peut faire seul dans le portail | `« Portail client »` → `« Ce qu'un client peut faire seul »` |
| La connexion directe par code | `« Portail client »` → `« Connexion directe par code »` (désactivée par défaut) |
| Les moyens de paiement proposés dans le portail | `« Portail client »` → `« Restreindre les moyens de paiement »` |
| Les adresses de retour du portail | `« Portail client »` → `« Adresses de retour autorisées »` |
| Les origines autorisées pour le logo et le lien d'aide | **par l'API seulement** (`PUT /v1/portal/settings`), pas par l'écran de la console |
| Le seuil de validation d'un reçu hors ligne | `« Réglages du commerce »` |

---

## 9. Ce que le Core ne fera jamais

Trois règles structurelles, qui ne sont pas des choix d'implémentation mais des principes :

1. **L'argent ne bouge pas d'un projet à l'autre.** Une écriture qui croise deux projets
   est refusée **par la base de données**, pas par le code. Un bug ne peut pas la contourner.
2. **Le passé ne se réécrit pas.** Ni une écriture, ni une facture émise, ni une version
   d'offre publiée. La seule voie est l'ajout.
3. **Aucune autorisation implicite.** Une ressource hors de votre périmètre n'est jamais
   confirmée ni refusée : elle est **introuvable**. C'est vrai de la console comme de
   l'API.

---

## 10. Où aller ensuite

| Vous voulez… | Lisez |
|---|---|
| Savoir **pourquoi** une décision a été prise | [Index des ADR](../../adr/README.md) |
| Comprendre **comment** c'est construit | [Vue d'ensemble de l'architecture](../../architecture/vue-ensemble.md) |
| Connaître les **écarts de la recette** du 2026-10-29 | [Registre des écarts](../../audit/ecarts-recette-2026-10-29.md) |
| **Brancher** un produit | [Brancher une application cliente](../../integration/brancher-une-application-cliente.md), [Guide `curl`](../../integration/guide-curl-integrateur.md), [Webhooks](../../integration/webhooks.md), [Kit Laravel](../../integration/kit-laravel/INSTALL.md) |
| **Déployer** le Core | [Fiche de déploiement](../../DEPLOIEMENT.md) |
| **Suivre** ce qui se passe en production | [Observabilité](../../operations/observabilite.md) |

---

## Repères techniques

| Sujet | Source |
|---|---|
| Modules hors périmètre | `docs/adr/0099-fees-storage-et-extensions-commerce-refuses.md`, `0096-developer-platform-reste-un-scaffold-jusqu-au-lot-16.md`, `0048-billing-storage-fees-developer-platform-hors-perimetre-production.md` |
| Le compte `wallet` et le champ « bloqué » | `packages/wallet/src/Domain/Model/WalletBalance.php` |
| Les invariants effectivement implémentés | `packages/payment/src/Application/UseCase/CheckPaymentInvariantsUseCase.php` |
| Les types de compte proposés | `app/Http/Controllers/Admin/LedgerAdjustmentsController.php` |
| Les seuils de double validation | `config/payment.php`, `config/ledger.php` |
| L'état de chaque module | `docs/architecture/vue-ensemble.md` §4 |
