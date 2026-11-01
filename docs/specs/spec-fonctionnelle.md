> Conversion Markdown de [`source/Spec_Fonctionnelle_Core_System.docx`](source/Spec_Fonctionnelle_Core_System.docx), générée par `tools/docs/docx_to_markdown.py`.
> En cas d'écart, le document Word fait foi ; régénérer après toute mise à jour.

# Enterprise Core System — Spécification Fonctionnelle

Architecture / Conseil logiciel

Version 1.0 — Septembre 2026

## 1. Introduction

### 1.1 Objet du document

Ce document décrit ce que fait l’Enterprise Core System (ci-après « le Core ») du point de vue métier et fonctionnel : les capacités offertes, les acteurs, les parcours, les règles de gestion et les critères d’acceptation. Il ne décrit pas comment c’est implémenté ; cela relève de la Spécification Technique, document séparé.

### 1.2 Positionnement

Le Core est un socle de capacités transversales consommé par des applications :

- des produits internes de l’entreprise (SaaS, ERP, plateformes grand public, sites, plateformes transactionnelles) ;
- des applications de clients externes qui consomment certaines capacités via API publique.
Le principe fondateur : une capacité est construite une fois, sécurisée, observée, documentée, puis consommée par plusieurs produits. Le Core ne contient jamais le métier spécifique d’un produit.

Le Core agit comme un hub d’orchestration et d’adaptation. Lorsqu’une application a besoin d’un paiement, d’une notification ou d’un versement, elle appelle une API stable du Core. C’est le Core — et non l’application — qui connaît, appelle et normalise les providers externes (MTN MoMo, Orange Money, fournisseurs SMS/email, etc.). Changer ou ajouter un provider se fait dans le Core, sans modifier ni redéployer les applications consommatrices.

### 1.3 Ce que le Core n’est pas

- Ce n’est pas un monolithe métier qui absorbe les règles de tous les produits.
- Ce n’est pas un annuaire central de tous les utilisateurs finaux de toutes les applications.
- Ce n’est pas une base de données partagée dans laquelle les produits vont lire directement.

## 2. Glossaire et concepts clés

| Terme | Définition |
| --- | --- |
| Organization | Entité propriétaire dans le Core : une entreprise cliente, une entité interne, un partenaire. Contient des Projects. |
| Project | Une application, plateforme ou intégration concrète qui consomme le Core. Porte les credentials et la configuration. Unité d’isolation principale. |
| Capability | Une capacité fonctionnelle activable pour un Project (Paiement, Notification, Wallet, Billing, Storage…). |
| Party (Account Holder) | Référence légère vers un acteur économique (client, marchand, agent, partenaire, end-user…) manipulé par le Core. Identifié par un external_ref fourni par le produit. Ne contient pas de données personnelles. |
| End-user | Utilisateur final d’une application. Reste dans le produit. N’est jamais authentifié par le Core et n’y est stocké que sous forme de Party léger, et seulement si nécessaire. |
| Provider | Fournisseur externe intégré par le Core : opérateur mobile money, fournisseur SMS/email, acquéreur carte, etc. |
| Adapter | Composant du Core qui traduit une capacité générique vers l’API concrète d’un provider. |
| Wallet | Compte de valeur rattaché à un Party, adossé au ledger. |
| Ledger | Grand livre à écritures immuables ; source de vérité financière. |
| Payment Intent | Intention de paiement créée par un produit, orchestrée par le Core vers un provider. |
| Fee / Commission | Frais ou commission calculés par un moteur de règles configurables. |
| Webhook | Notification signée envoyée par le Core à une application pour l’informer d’un événement. |
| Sandbox / Live | Environnements isolés : sandbox pour tester sans argent réel, live pour la production. |

## 3. Acteurs et personas

### 3.1 Opérateur plateforme (interne)

Employé de l’entreprise qui administre le Core via le Core Admin. Gère les organisations, projets, providers, règles de frais, réconciliation, supervision. Identité complète dans le Core, authentification forte (MFA), permissions fines et actions auditées.

### 3.2 Développeur / équipe produit interne

Consomme le Core pour bâtir un produit interne. Interagit via API, SDK, dashboard développeur et documentation. Ne réimplémente pas les capacités transversales.

### 3.3 Client externe (organisation cliente)

Entreprise tierce disposant de sa propre Organization et de ses Projects. Consomme des capacités (typiquement Paiement) via API publique, credentials sandbox/live et webhooks. N’accède jamais aux données internes non nécessaires.

### 3.4 End-user (utilisateur final d’un produit)

Client d’une boutique e-commerce, voyageur sur une plateforme de tourisme, propriétaire utilisant un ERP, etc. Il interagit uniquement avec le produit, jamais directement avec le Core. Le Core ne le connaît que via un Party, créé à la demande.

## 4. Modèle d’identité fonctionnel

C’est la décision structurante du système. Trois plans d’identité strictement séparés.

### 4.1 Plan 1 — Identité plateforme (opérateurs)

Les opérateurs internes ont une identité complète dans le Core (Core Admin). Volume faible. Authentification forte, rôles et permissions détaillés.

### 4.2 Plan 2 — Identité machine (applications consommatrices)

Chaque produit ou client = une Organization → un ou plusieurs Projects. C’est le Project qui s’authentifie au Core, via :

- des API keys (serveur-à-serveur) ;
- des clients OAuth2 avec scopes (accès plus fin, clients externes) ;
- des paires de credentials sandbox et live distinctes.
Volume borné (dizaines à centaines), pas des millions.

### 4.3 Plan 3 — Parties (références d’acteurs, créées à la demande)

Un Party représente un acteur économique manipulé par le Core (celui qui possède un wallet, reçoit une commission, est bénéficiaire d’un versement). Un Party contient :

- le project_id auquel il appartient ;
- un external_ref = l’identifiant de l’utilisateur dans le produit ;
- un type (customer, end_user, merchant, agent, partner, platform, provider, beneficiary…) ;
- un statut KYC optionnel ;
- des métadonnées minimales strictement nécessaires.
Règles fondamentales :

1. Le end-user ne s’authentifie jamais contre le Core. Le produit authentifie son propre utilisateur, puis appelle le Core avec ses credentials machine, en indiquant « j’agis pour le compte du Party external_ref = X ».
1. Le Core ne stocke aucune donnée personnelle du end-user (ni nom, ni email, ni mot de passe).
1. Un Party est créé lazy : seulement au premier moment où l’utilisateur a besoin d’un artefact Core (un wallet, être payé, être bénéficiaire d’une distribution).
Conséquence : une plateforme de tourisme comptant 500 000 utilisateurs mais dont seulement 3 000 réalisent des opérations financières ne crée que 3 000 Parties dans le Core, pas 500 000. La base reste légère, la surface de conformité (RGPD/données personnelles) réduite, et il n’y a aucun problème de synchronisation d’annuaire.

### 4.4 Option — IdP embarqué par projet

Pour un produit qui souhaite déléguer l’authentification de ses utilisateurs au Core (par exemple un petit outil interne qui ne veut pas construire son auth), le Core peut exposer un fournisseur d’identité (OIDC) activable projet par projet. C’est une option opt-in, jamais le comportement par défaut. Le modèle par défaut reste : les users restent dans le produit.

## 5. Capacités fonctionnelles

Chaque capacité est activable par Project. Toutes ne sont pas nécessaires à tous les produits.

### 5.1 Identity & Access (plateforme)

- Gestion des Organizations et Projects.
- Émission et révocation d’API keys et de clients OAuth2, avec scopes.
- Séparation sandbox / live.
- Rôles et permissions pour les opérateurs plateforme (RBAC).
- Invitations et gestion des accès opérateurs.
- Journalisation des connexions et événements de sécurité.

### 5.2 Organizations, Projects & Tenancy

- Une Organization possède plusieurs Projects.
- Un Project active un ensemble de capacités et possède ses credentials et environnements.
- Les données de configuration et d’usage sont isolées par Organization / Project.
- Un client externe dispose de sa propre Organization et de ses applications.

### 5.3 Parties & Wallets

- Création lazy de Parties par référence externe.
- Wallets rattachés à un Party ; plusieurs wallets possibles (par devise).
- Dépôts, retraits, transferts, réservations (holds).
- Historique complet des mouvements.
- Solde adossé au ledger (source de vérité).
- Le type de propriétaire du wallet dépend de l’application : parfois le client d’un SaaS (un propriétaire qui paie un abonnement ERP), parfois chaque end-user d’une plateforme grand public. Le mécanisme est identique : le propriétaire est toujours un Party.

### 5.4 Payment orchestration

- Création d’une Payment Intent par le produit (montant, devise, méthode souhaitée, référence produit, clé d’idempotence).
- Le Core choisit le provider adéquat via règles de routage et appelle l’adaptateur correspondant.
- Gestion des callbacks/webhooks provider, vérification et rapprochement de statut.
- Retry contrôlé, timeout, gestion des échecs, opérations compensatoires (refunds) lorsque supportées.
- Réponse immédiate au produit et notification asynchrone par webhook signé et endpoint de consultation de statut (les trois modes).
- Le produit ne connaît jamais la logique interne de chaque provider.

### 5.5 Payouts & distribution

- Versements vers des bénéficiaires (Parties de type merchant, agent, partner…).
- Distribution d’une transaction entre plusieurs acteurs : montant bénéficiaire, frais plateforme, commission agent, commission partenaire, frais provider.
- Traçabilité complète des distributions appliquées.

### 5.6 Fee & Commission Engine

- Règles configurables dépendant du produit, de l’opération, du montant, de la devise, de l’acteur, du provider, du pays ou d’un contrat.
- Pourcentage, montant fixe, ou combinaison, avec minimum/maximum.
- Priorité des règles, versionnement et date d’effet.
- Simulation avant activation.
- Historisation de la règle exacte appliquée à chaque transaction.
- Les frais ne sont jamais codés en dur dans les applications.

### 5.7 Billing & Subscriptions

- Products, plans, pricing.
- Souscriptions, essais, renouvellements, upgrade/downgrade.
- Entitlements et limites (« cette organisation possède-t-elle cette capacité ? »).
- Usage metering.
- Factures et états de paiement lorsque nécessaire ; coupons/promotions selon besoin.
- Le Core privilégie les entitlements plutôt que le couplage à des noms de plans, pour éviter que les produits dépendent de la structure tarifaire.

### 5.8 Notification orchestration

- Templates de notification versionnés.
- Canaux email, SMS, WhatsApp, push, in-app (selon providers configurés).
- Le produit demande « envoie cette notification » ; le Core choisit et appelle le provider.
- Préférences utilisateur, canaux et priorités.
- Retry, dead-letter handling, suivi du statut d’envoi, historique et audit.
- Notifications transactionnelles et événementielles (ex. payment.completed, subscription.expiring).

### 5.9 Storage & Media

- Stockage objet compatible S3, métadonnées et ownership.
- Signed URLs, contrôle d’accès, organisation par projet/environnement.
- Cycle de vie (suppression, expiration), traitement média si nécessaire.

### 5.10 Audit & Compliance

- Enregistrement des opérations sensibles : acteur, action, ressource, horodatage, contexte/IP (lorsque approprié), valeurs avant/après pour les changements critiques, corrélation, source.
- Consultable par les opérateurs autorisés.

### 5.11 Events & Webhooks

- Modèle d’événements standardisé décrivant des faits métier ou techniques (payment.completed, wallet.credited, subscription.renewed, payout.failed…).
- Webhooks signés vers les applications, avec idempotence, retry, backoff, historique, statut et replay contrôlé.

### 5.12 Developer Platform

- API versionnées, documentation OpenAPI, portail développeur.
- Gestion des API keys et clients OAuth, scopes, quotas, rate limiting.
- Environnements sandbox / live.
- Gestion des webhooks côté consommateur.
- SDK (PHP prioritaire) lorsque leur valeur est démontrée.

## 6. Parcours fonctionnels clés

### 6.1 Un produit interne encaisse un paiement mobile money

1. Le end-user choisit de payer dans l’application produit.
1. Le produit appelle l’API Paiement du Core (montant, devise, méthode, référence, clé d’idempotence), authentifié par ses credentials de Project.
1. Le Core calcule les frais applicables, choisit le provider (MTN ou Orange selon la méthode/le numéro), et déclenche la collecte via l’adaptateur.
1. Le provider traite et notifie le Core (callback) ; le Core vérifie le statut.
1. Le Core enregistre les écritures financières et publie payment.completed ou payment.failed.
1. Le produit reçoit un webhook signé et met à jour son propre état métier (commande, réservation…).
1. Le Core envoie les notifications éventuelles et conserve les traces pour audit et réconciliation.

### 6.2 Un client externe intègre le paiement

1. Création d’une Organization cliente et d’un Project/application.
1. Activation de la capacité Paiement.
1. Génération des credentials sandbox et live.
1. Configuration des endpoints webhook du client.
1. Le client teste en sandbox, puis passe en live.
1. Le client consulte ses transactions via API et dashboard, et réconcilie via la plateforme.
Le client ne dépend jamais de la base de données du Core.

### 6.3 Un end-user d’une plateforme de tourisme reçoit un wallet et est crédité

1. Un end-user (guide, hôte, prestataire) doit recevoir des fonds.
1. Au premier besoin, le produit demande au Core la création lazy d’un Party (external_ref = l’ID de l’utilisateur dans le produit) et d’un wallet.
1. Une transaction crédite le wallet du Party via des écritures au ledger.
1. Le end-user n’existe dans le Core que sous cette forme légère ; son profil complet reste dans le produit.

### 6.4 Un propriétaire ERP paie son abonnement SaaS

1. Le produit SaaS déclare un plan et une souscription pour l’organisation du propriétaire.
1. À l’échéance, le Core orchestre le prélèvement (via la capacité Paiement) et met à jour l’état de la souscription.
1. Les entitlements de l’organisation reflètent l’accès aux fonctionnalités.
1. Le produit interroge les entitlements (« cette organisation a-t-elle droit à X ? ») plutôt que de coder en dur un nom de plan.

### 6.5 Distribution multi-acteurs (commission agent)

1. Une transaction implique un montant à distribuer.
1. Le Fee & Commission Engine calcule le plan de distribution : montant bénéficiaire, frais plateforme, commission agent, frais provider.
1. Le Core enregistre l’ensemble des écritures dans une seule opération cohérente et distribue vers les wallets/parties concernés.
1. La règle exacte appliquée est historisée pour audit.

## 7. Règles de gestion

### 7.1 Argent

- Toute opération financière significative est représentée par des écritures immuables au ledger.
- Le solde est une vue dérivée/matérialisée, jamais un simple champ mis à jour librement.
- Les montants sont manipulés en unités mineures entières associées à une devise ; aucune arithmétique en virgule flottante.
- Chaque opération monétaire porte une clé d’idempotence : rejouer la même requête ne crée jamais de double mouvement.

### 7.2 Frais et commissions

- Calculés exclusivement par le moteur de règles, jamais dans les applications.
- La règle appliquée à une transaction est figée et historisée (versionnement + date d’effet).

### 7.3 Idempotence et fiabilité

- Les paiements, versements, crédits/débits de wallet et livraisons de webhooks sont idempotents.
- Les callbacks provider sont protégés contre le rejeu.

### 7.4 Séparation sandbox / live

- Les données, credentials et effets sandbox sont totalement isolés du live.
- Aucun mouvement d’argent réel en sandbox.

## 8. Rôles et permissions

### 8.1 Rôles opérateur plateforme (indicatif)

| Rôle | Portée typique |
| --- | --- |
| Platform Admin | Accès complet, gestion des opérateurs, configuration critique. |
| Finance Operator | Transactions, wallets, ledger, réconciliation, frais/commissions. |
| Support Operator | Consultation transactions, statuts, relance de notifications. |
| Provider Manager | Configuration des providers et du routage. |
| Read-only / Auditor | Lecture seule, audit logs. |

Les actions critiques (modification de règles de frais, configuration provider live, remboursements) exigent des permissions dédiées et sont auditées.

### 8.2 Accès des applications consommatrices

Les Projects accèdent aux capacités via scopes (ex. payments:write, wallets:read, notifications:send). Un client externe n’obtient que les scopes strictement nécessaires.

## 9. Ce qui n’entre PAS dans le Core

Pour éviter la dérive vers un fourre-tout métier, restent dans les produits :

- la gestion des chambres et réservations d’un hôtel ;
- la gestion pédagogique d’un établissement ;
- le catalogue métier spécifique d’un client ;
- les workflows métier propres à un client ;
- les règles de pricing propres à un produit lorsqu’elles ne sont pas transverses ;
- les processus et l’UI métier spécifiques ;
- les modèles métier propres à un secteur.
Le Core fournit les primitives (paiement, wallet, notification, entitlement…), jamais le domaine métier.

Un comité d’architecture et des critères d’entrée décident ce qui peut rejoindre le Core.

## 10. Exigences non-fonctionnelles (exprimées côté métier)

- Disponibilité : chemin de paiement visé à 99,9 % ; dégradation gracieuse si un provider tombe (bascule/rejet propre, jamais de double débit).
- Traçabilité : toute opération sensible est explicable et corrélable de bout en bout.
- Sécurité : moindre privilège, isolation stricte entre organisations/projets, secrets jamais en clair, signatures de webhooks, rate limiting.
- Évolutivité : ajouter un provider, un canal de notification ou un plan ne doit pas impacter les applications existantes.
- Réversibilité : un produit peut cesser d’utiliser une capacité sans casser les autres.

## 11. Critères d’acceptation du MVP

Le MVP est considéré comme réussi lorsque :

1. Une application interne peut créer une Organization, un Project et générer des credentials sandbox/live.
1. Cette application peut initier un paiement (MTN MoMo et Orange Money, XAF, Cameroun) sans connaître les providers, uniquement via l’API du Core.
1. Le Core calcule et applique des frais configurés par règle, et historise la règle appliquée.
1. Les écritures financières sont enregistrées au ledger, avec solde matérialisé cohérent et idempotence vérifiée.
1. Un webhook signé payment.completed / payment.failed est reçu par l’application, avec retry en cas d’échec.
1. Un wallet peut être créé lazy pour un Party et crédité/débité de façon cohérente.
1. Ajouter un second provider ne nécessite aucune modification des applications consommatrices.
1. Toutes les opérations sensibles apparaissent dans l’audit, corrélées par identifiant.
1. Un scénario de migration en shadow mode démontre la cohérence entre ancienne et nouvelle implémentation avant bascule.

Fin de la Spécification Fonctionnelle — Version 1.0

---

# Addendum 1.0 — Plateforme multi-tenant de projets SaaS (2026-10-24)

> **Addendum daté, statut proposé.** Le document Word
> `source/Spec_Fonctionnelle_Core_System.docx` reste la référence des §1 à §11
> ci-dessus. Cet addendum décrit les capacités décidées par les ADR-0068 à
> ADR-0090 (tous proposés). Il ne nomme aucun produit.

## F1. Objet

Faire du Core la plateforme qui **gère des projets SaaS de bout en bout**, pour
l'entreprise comme pour des organisations tierces : identifier, vendre,
mesurer, servir le client, compter l'argent, piloter, en restant générique
(aucun concept métier d'un projet).

## F2. Acteurs

| Acteur | Description | Accès |
|---|---|---|
| **Personnel de la plateforme** | exploite la plateforme pour toutes les organisations | console, rôle de plateforme |
| **Membre d'organisation** | gère les projets de son organisation (propriétaire, administrateur, finance, support, lecture) | console, portée limitée à son organisation |
| **Serveur d'un projet** | l'application SaaS, côté serveur | API `/v1`, clés à portée |
| **Client d'un projet** | la partie qui paie le projet (entreprise ou particulier) | portail client |
| **Utilisateur final** | utilise l'application sans en être le payeur | **aucun** : inconnu du Core |
| **Prestataire** | encaisse, verse, livre les messages | callbacks authentifiés |

## F3. Capacités, par pilier

### F3.1 Services de plateforme (existants, étendus)

- Organisations, projets (cycle `active` / `suspended` / `archived`), comptes
  console scopés par organisation (ADR-0069).
- Plusieurs clés d'API nommées par projet, portées choisies, rotation sans
  coupure, restriction réseau (ADR-0082).
- Paiement, notification, webhook, audit : inchangés, plus la vérification du
  montant confirmé (ADR-0077) et la double signature pendant la rotation d'un
  secret de webhook (ADR-0082).
- Catalogue de capacités et pages de démarrage testées (ADR-0087).

### F3.2 Commerce SaaS (nouveau)

| Capacité | Ce que le projet peut faire | ADR |
|---|---|---|
| Clients | déclarer, mettre à jour, suspendre, effacer, exporter ses clients | 0070, 0083 |
| Catalogue | créer des plans, publier des versions (prix, devise, intervalle, essai, délai de grâce, fonctionnalités), archiver | 0071 |
| Abonnements | abonner un client (avec ou sans essai), annuler, changer de plan ; lire l'état et la prochaine échéance | 0072 |
| Factures | factures de période automatiques, factures manuelles, paiement en ligne, encaissement hors ligne, documents | 0073 |
| Usage et quotas | déclarer un usage, appliquer une limite, lire droits et compteurs ; décompte automatique des notifications | 0074 |
| Portail client | ouvrir le portail d'un client ; laisser le client payer, télécharger, suivre son usage, changer de plan | 0075 |

### F3.3 Grand livre (étendu)

- Toute somme est écrite, dans un plan de comptes par projet (ADR-0076).
- Exceptions d'argent (écart de montant, surpaiement…) traitées à quatre yeux
  (ADR-0077).
- Rapprochement : invariants internes, relevé et solde du prestataire
  (ADR-0078).
- Versements sortants (ADR-0079).

### F3.4 Pilotage

Console de portefeuille : clients actifs, essais, impayés, revenu récurrent
mensuel par devise, attrition, encaissé, créances par ancienneté, exceptions,
usage, santé technique, onboarding du projet (ADR-0080).

## F4. Parcours fonctionnels

### F4.1 Une organisation tierce met un projet SaaS en service

1. Le personnel de la plateforme crée l'organisation et invite son
   propriétaire (jeton d'activation).
2. Le propriétaire active son compte (mot de passe + MFA), crée le projet,
   invite ses membres.
3. L'administrateur émet une clé « serveur complet » et une clé « portail
   uniquement », enregistre son application prestataire, un endpoint de
   webhook (test réussi), publie deux plans, personnalise le portail.
4. La liste de contrôle d'onboarding est verte ; le projet est en service.

### F4.2 Un client s'abonne avec un essai, puis paie

1. Le projet crée son tenant chez lui, puis `PUT /v1/customers/AE0001`.
2. `POST /v1/subscriptions {customer_ref: AE0001, plan_code: pro, trial_days: 14}`
   → `trialing` ; webhook `subscription.created`.
3. À J−7 de la fin d'essai : facture de la première période ;
   `subscription.renewal_due` ; à J−3 : `subscription.trial_will_end`.
4. Le projet ouvre le portail pour le client ; le client paie par mobile
   money.
5. Paiement confirmé avec le bon montant → facture payée → à la fin de
   l'essai, l'abonnement passe `active` ; webhooks `invoice.paid`,
   `subscription.activated`.

### F4.3 Un client paie en avance, puis oublie le renouvellement suivant

1. Le client paie la facture de la période suivante dès sa création : la
   période suivante est couverte, aucun jour perdu.
2. Au renouvellement d'après, il ne paie pas : à l'échéance, `past_due` ; le
   projet garde l'accès pendant le délai de grâce du plan.
3. À la fin du délai de grâce : `expired` ; le projet ferme l'accès sur
   `subscription.expired`.

### F4.4 Le client paie par virement

1. La finance de l'organisation enregistre l'encaissement hors ligne
   (référence, montant, date, pièce) ; au-delà du seuil, un second membre
   valide.
2. La facture est soldée, la période couverte, l'abonnement actif ; le grand
   livre porte l'encaissement au compte `manual_receipts:bank_transfer`.

### F4.5 Un quota est atteint

1. Le plan « pro » limite `whatsapp_messages` à 1 000 par période.
2. Le projet envoie une notification avec `customer_ref` ; le Core décompte
   une unité dans la même transaction que la création de la notification.
3. À 800 : `usage.threshold_reached`. À 1 000 : l'envoi suivant reçoit
   `429 quota-exceeded`, rien n'est envoyé ni créé.
4. Le client passe au plan supérieur depuis le portail (changement à
   l'échéance, ou immédiat si le projet l'autorise).

### F4.6 Le prestataire confirme un montant différent

1. Le callback confirme 4 000 XAF pour une facture de 5 000 XAF.
2. Le paiement est `succeeded`, l'argent est écrit au compte
   `payment_exceptions`, la facture reste ouverte, une exception `high` est
   ouverte.
3. La finance impute partiellement ou rembourse hors plateforme ; un second
   membre approuve au-delà du seuil.

### F4.7 Un projet reverse de l'argent à un partenaire

1. `POST /v1/payouts` depuis le wallet du partenaire.
2. Le Core écrit d'abord (`payout_in_transit`), appelle le prestataire avec
   l'application du projet, confirme par callback, consultation ou balayage.
3. Au-delà du seuil du projet, le versement attend l'approbation d'un second
   membre.

### F4.8 Un client demande l'effacement de ses données

Le projet appelle `POST /v1/customers/{ref}/erase` : identité effacée,
abonnements annulés, portail fermé ; grand livre et factures intacts.

## F5. Règles de gestion

### F5.1 Commerce

- Un plan publié ne change jamais ; changer un prix = publier une version.
- Un abonnement fige prix, devise et intervalle de sa version.
- Les périodes d'un abonnement ne se chevauchent jamais.
- Un client a les droits de son plan si l'abonnement est en essai, actif, ou
  impayé pendant le délai de grâce (si le plan le permet) ; jamais s'il est
  suspendu.
- Pas d'activation sans argent : seuls un paiement confirmé, un encaissement
  hors ligne ou une période offerte (motivée) ouvrent une période.
- Une facture émise est immuable ; sa numérotation est sans trou par projet
  et par année.
- Un usage n'est compté qu'une fois par clé d'idempotence ; un dépassement est
  refusé **avant** l'effet.

### F5.2 Argent

- Toute somme est écrite au grand livre, cloisonnée par projet.
- Un encaissement dont le montant confirmé diffère n'est jamais imputé
  automatiquement.
- Toute opération financière manuelle au-delà d'un seuil exige deux personnes.
- Un rapprochement constate ; seule une personne corrige, par écriture
  compensatoire.

### F5.3 Ce qui reste au projet

- Ses tenants, ses utilisateurs finaux, leurs rôles et leur authentification.
- Le cloisonnement de ses données, en fail-closed.
- La forme des messages envoyés à ses clients (le Core émet des événements).
- Ses indicateurs métier.

## F6. Rôles

| Rôle | Portée | Exemples d'actions |
|---|---|---|
| Rôles de plateforme existants (ADR-0044) | toutes organisations | exploitation, sessions de canal globales, files |
| `org_owner` | son organisation | membres, archivage de projet, tout `org_admin` |
| `org_admin` | son organisation | projets, clés, webhooks, prestataires, catalogue, portail |
| `org_finance` | son organisation | factures, encaissements hors ligne, exceptions, versements, grand livre |
| `org_support` | son organisation | clients, abonnements (prolonger, suspendre avec motif) |
| `org_viewer` | son organisation | lecture |

## F7. Ce que le Core refuse

Voir `docs/gouvernance/liste-negative.md` : cloisonnement des données des
applications, fournisseur d'identité des utilisateurs finaux, flux JWT
transitoire, inscription libre-service, microservices et plugins dynamiques.

## F8. Critères d'acceptation (plan de recette)

Chaque critère est vérifié par un test automatisé ou par la recette
bout-en-bout sur le staging (horloge de test + simulateur de paiement).

| # | Critère |
|---|---|
| R-01 | Un membre de l'organisation A n'accède à aucune donnée de l'organisation B (404 partout). |
| R-02 | Une clé sans portée `payouts:write` ne peut pas verser. |
| R-03 | Le parcours F4.2 se déroule sur horloge de test, webhooks reçus dans l'ordre logique ou rejoués sans effet. |
| R-04 | Paiement anticipé (F4.3) : aucune période perdue, aucun chevauchement. |
| R-05 | Impayé puis grâce puis expiration (F4.3) aux dates attendues. |
| R-06 | Encaissement hors ligne au-dessus du seuil : n'écrit rien au grand livre tant qu'il n'est pas validé (ADR-0095 : un compte valide, l'auteur peut valider son propre reçu ; la règle des quatre yeux reste pour les exceptions d'argent et les corrections du grand livre). |
| R-07 | Limite d'usage 1, 50 requêtes simultanées : une seule admise ; même clé rejouée : comptée une fois. |
| R-08 | Notification refusée par quota : aucune notification créée, aucun appel prestataire. |
| R-09 | Montant confirmé différent : aucune imputation, exception ouverte, grand livre équilibré. |
| R-10 | Tout paiement réussi a son écriture ; balance de vérification nulle par projet et devise. |
| R-11 | Portail : lien réutilisé ou expiré refusé ; URL de retour hors liste refusée ; client A ne voit pas les factures de B. |
| R-12 | Versement en délai dépassé : reste `pending`, aucune contre-passation ; la consultation tranche. |
| R-13 | Effacement d'un client : aucune donnée personnelle restante, grand livre inchangé. |
| R-14 | Projet suspendu : `/v1` refusé, callbacks prestataires toujours traités. |
| R-15 | Recette bout-en-bout avec une application réelle : tenant → client → abonnement → notification avec quota → paiement → portail → webhook → accès ouvert puis fermé à l'expiration. |

Fin de l'addendum — 2026-10-24
