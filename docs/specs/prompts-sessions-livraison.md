# Prompts de session — livlots parallèles du Core VCam

Chaque bloc ci-dessous est **autonome** : collez-le tel quel dans une session
Claude Code neuve, dans le dépôt `/home/wilfreid/Documents/Vcam/vcam-core-system`.

## Avant de lancer : comment paralléliser sans se marcher dessus

Deux sessions ne peuvent pas commiter sur `develop` en même temps. **Chaque
session travaille sur sa propre branche**, issue de `develop` :

```bash
cd /home/wilfreid/Documents/Vcam/vcam-core-system
git fetch origin && git checkout -b lot-13 develop     # ou audit-sec, lot-12, lot-15…
```

Puis, à la fin, la session fait **un** commit sur sa branche et **ne pousse
jamais** (la règle vaut pour tous). La fusion se fait après, lot par lot, dans
l'ordre des dépendances.

**La base de test `core_testing` est partagée avec les autres sessions.**
Jamais de `migrate:fresh`, `migrate:reset` ou `migrate:rollback` à la main
dessus. Chaque session utilise sa base :

```bash
sg docker -c "docker compose exec -T -e DB_DATABASE=core_testing_<suffixe> app vendor/bin/pest"
```

> Historique utile : le 2026-10-28, un workflow multi-agents a échoué d'un bout
> à l'autre sur `EAI_AGAIN` (le DNS n'atteignait pas l'API). Si une session
> multi-agents échoue pareil, elle ne peut pas être réactivée : relance le
> travail en local, agent par agent, ou dans plusieurs sessions.

---

## Bloc commun — à lire avant chaque prompt

```
Dépôt : /home/wilfreid/Documents/Vcam/vcam-core-system — socle partagé
Laravel 13 / PHP 8.4, architecture hexagonale, un module par domaine dans
packages/* (Domain / Application / Infrastructure / Presentation / Contracts).

LIS D'ABORD, dans cet ordre :
  1. CLAUDE.md — règles dures, commandes, état par lot
  2. docs/architecture/vue-ensemble.md — vue d'ensemble, garde-fous
  3. docs/adr/README.md — index des ADR et leurs statuts
  4. docs/specs/plan-delivery-mvp.md — le lot qui t'est confié (§P3) et le
     chemin critique (§P4)

RÈGLES DURES (jamais d'exception sans ADR) :
  - Domain/ et Contracts/ sont du PHP pur : ni Laravel, ni Eloquent, ni PDO.
  - Un module n'atteint un autre module que par son Contracts/.
  - ledger ne dépend d'aucun module ; core/support ne persiste rien.
  - Toute route qui bouge de l'argent exige un Idempotency-Key (G7).
  - Aucun événement hors de l'outbox transactionnel (G8).
  - Montants en bigint minor units + currency char(3), jamais de float.
  - Toute route console à identifiant a un cas IDOR (G15) ; toute lecture
    console prend un AccessScope en paramètre REQUIS (G16).
  - Un ADR accepté ne se réécrit pas : on le remplace par un nouvel ADR et on
    met à jour le statut de l'ancien.
  - Ne pousse jamais. Commite sur ta branche, un seul commit, message
    conventionnel : feat(...)/fix(...)/docs(...) — une ligne, puis le corps.

COMMANDES (tout est Docker-first, Docker rootless → passe par sg docker) :
  sg docker -c "make lint-check"      # Pint
  sg docker -c "make stan"            # PHPStan niveau 10
  sg docker -c "make arch"            # Deptrac + tests d'architecture
  sg docker -c "make guard-test"      # les garde-fous doivent mordre
  sg docker -c "make openapi"         # regénère openapi/v1.json et admin.json
  sg docker -c "docker compose exec -T -e DB_DATABASE=core_testing_<suffixe> \
      app vendor/bin/pest --parallel"

AVANT DE COMMITER : pint --test, phpstan, deptrac, la suite complète verte,
guard-test vert, openapi régénéré sans rupture (oasdiff), et la suite de
tests de ton lot verte. Une suite rouge n'est jamais « known » : corrige-la.

Tu écris le code toi-même. N'utilise un sous-agent que pour une revue ou un
balayage, pas pour écrire la fonctionnalité.

À la fin : un rapport court — ce qui est livré, ce qui est vérifié par quel
test, ce qui reste, et le SHA du commit.
```

---

## S1 — Sécurité : ADR-0081 S-11 (CSP, HSTS, cookies `__Host-`)

```
TÂCHE : livrer l'exigence S-11 d'ADR-0081 — « Protection navigateur (console,
portail) : CSP stricte, frame-ancestors 'none', HSTS, cookies __Host- Secure
HttpOnly SameSite, CSRF synchronisé » — et son test des en-têtes en CI.

CONTEXTE : ADR-0081 §S-11 est aujourd'hui le plus gros écart entre le modèle
de menace et le code : ni CSP, ni HSTS, ni cookies __Host-, ni test
d'en-têtes. Le lot 17 (bascule) exige que S-11 soit satisfaite.

LIS : docs/adr/0081-modele-de-menace-et-exigences-de-securite.md (ligne S-11),
bootstrap/app.php, config/, app/Http/Middleware/, les middlewares de session
et de cookie existants, .github/workflows/ci.yml.

LIVRE :
  1. un middleware qui pose les en-têtes de sécurité sur toutes les réponses
     HTTP (console et API) : Content-Security-Policy stricte et sans
     wildcard, X-Frame-Options: DENY + frame-ancestors 'none',
     Strict-Transport-Security, X-Content-Type-Options: nosniff,
     Referrer-Policy, Permissions-Policy ;
  2. les cookies de session et de CSRF (__Host- prefixe, Secure, HttpOnly,
     SameSite) ;
  3. un test Pest qui vérifie les en-têtes réels sur une route de console et
     sur une route /v1 — pas une assertion sur une constante.
  4. le test tourne dans le pipeline CI (le fichier de workflow existe déjà).

GARDE-FOUS : ne casse pas les pages de la console (les vues Blade chargent des
assets locaux — la CSP doit les autoriser explicitement, pas en 'unsafe-inline'
global). Vérifie que le SSO et le callback webhook ne sont pas cassés par la
CSP.

LIVRE : la contrainte S-11 est satisfaite et prouvée par un test.
```

## S2 — Sécurité : ADR-0081 S-19 (OWASP ZAP dans le pipeline)

```
TÂCHE : livrer l'exigence S-19 d'ADR-0081 — « analyse de base OWASP ZAP sur le
staging à chaque déploiement » — sous forme d'étape de pipeline.

CONTEXTE : S-19 est le second écart de sécurité majeur. Le dépôt de
déploiement est distinct (vcam-infra-deploy) ; le workflow de déploiement
dans ce dépôt doit au minimum porter l'étape ZAP contre le staging, avec les
secrets gérés par le gestionnaire de secrets de la CI (jamais en clair,
jamais dans le dépôt).

LIS : docs/adr/0081-... (§S-19), .github/workflows/, le Makefile (ci,
staging-deploy), docs/specs/plan-delivery-mvp.md §P5 (critères de sortie).

LIVRE :
  1. un job CI « zapprobe » qui lance le scan de base (baseline) OWASP ZAP
     contre le staging, et qui échoue sur toute alerte de sévérité haute ou
     critique ;
  2. les secrets (URL, jeton) lus depuis les secrets CI, jamais en clair ;
  3. la documentation : où le rapport est publié, comment un faux positif se
     déclare, et à qui appartient la décision.

NOTE : le scan contre le staging suppose un staging qui tourne. Si le staging
n'est pas joignable depuis la CI, livre le job avec une condition claire et
documente ce qui reste à faire — ne simule pas un succès.

LIVRE : le job existe, échoue vraiment sur une alerte, et le README dit
comment on l'exploite.
```

## S3 — Revue de sécurité du lot 11

```
TÂCHE : passer le lot 11 (facture + encaissement) en revue de sécurité, et
corriger ce que la revue trouve.

CONTEXTE : le plan exige que CHAQUE lot passe une revue de sécurité (skill
security-review) avant fusion. Le lot 11 a passé pint, PHPStan, deptrac, la
suite complète (1385 tests) et guard-test — mais pas la revue de sécurité.
C'est le seul critère de sortie invérifiable du lot.

PÉRIMÈTRE : tout ce que le lot 11 a touché —
  packages/billing/src/Application/UseCase/{PayInvoice,AllocateInvoicePayment,
    FailInvoicePayment,RecordManualReceipt,ApproveManualReceipt}*.php
  packages/billing/src/Presentation/Http/Controllers/PayInvoiceController.php
  packages/billing/src/Presentation/Listeners/InvoicePaymentStatusListener.php
  packages/billing/database/migrations/2026_10_27_171000_*.php
  app/Http/Controllers/Admin/BillingInvoiceReceiptsController.php
  packages/payment/src/Application/UseCase/StartPaymentUseCase.php
  packages/payment/src/Domain/Model/Payment.php (le `purpose`)

Cherche en particulier : le contournement de la clé d'idempotence par un
autre projet (G13), l'imputation d'un montant ou d'une devise falsifiés, la
ré-entrabilité de l'imputation, le refus d'un reçu hors-ligne par un compte
d'une autre organisation, la fuite de données personnelles dans les vues
console ou les audits, et le rejeu d'un `payment.status_changed`.

LIVRE : un rapport des constats (sévérité, fichier, scénario), puis les
corrections, puis un test par constat. Si un constat n'est pas corrigeable
ici, écris-le dans un ADR ou une note explicite — ne le laisse pas implicite.
```

## L12 — Lot 12 : usage et quotas (chemin critique)

```
TÂCHE : livrer le lot 12 — « Usage et quotas ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (la ligne du lot 12),
docs/adr/0074-*.md, et le module billing (packages/billing) comme modèle de
module hexagonal.

CONTENU (ADR-0074) :
  - la table usage_records partitionnée, avec usage_idempotence ;
  - usage_counters, mis à jour de façon atomique dans la transaction ;
  - POST /v1/usage (une seule route, scope projects:write ou usage:write) ;
  - des entitlements (droits) attachés au plan ;
  - un port QuotaGuard côté notification (l'arête notification → billing) ;
  - des seuils franchis DANS la transaction, avec compensation en cas d'échec.

CONTRAINTES : le plan de comptes est ouvert (ADR-0076 §4) — les kinds de
comptes du module usage sont locaux au module ; bigint minor units ;
Idempotency-Key sur toute route qui écrit de l'usage ; le compteur et le
seuil ne peuvent pas se désynchroniser (invariant vérifié par un test).

DÉPENDANCE : le lot 17 (bascule) dépend du lot 12. Commence par lui.
```

## L13 — Lot 13 : portail client (chemin critique, le plus gros)

```
TÂCHE : livrer le lot 13 — « Portail client ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (la ligne du lot 13),
docs/adr/0075-*.md, et docs/adr/0084-*.md (le guide d'intégration dont le
portail dépend).

CONTENU (ADR-0075) : un module `portal` (nouveau, packages/portal) —
  - sessions créées par le projet : échange et cookie, personnalisation,
    écran de connexion directe par code (désactivé par défaut) ;
  - les écrans : abonnements, factures, paiement d'une facture, reçus,
    usage, profil ;
  - les en-têtes de sécurité testés (S-01, S-10, S-11, S-12) ;
  - l'isolation entre clients (IDOR), la protection contre la redirection
    ouverte, la force brute du code, le XSS via la personnalisation.

CONTRAINTES : le portail ne doit JAMAIS atteindre un autre module que par
son Contracts/ ; aucune donnée personnelle en clair ; le rendu du reçu
(ADR-0073 §5) doit exister — si le rendu du reçu n'est pas encore écrit,
commence par l'écrire (c'est une demi-journée) : le portail l'affiche.

Le graphe de modules change : ADR-0002 exige un ADR pour l'arête
`portal → billing`. Écris-le, et fais valider par le comité.

LIVRE : le portail rend les abonnements, les factures, le paiement d'une
facture et les reçus d'un client, sans jamais voir la donnée d'un autre.
```

## L15 — Lot 15 : exploitation

```
TÂCHE : livrer le lot 15 — « Exploitation ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (ligne du lot 15), docs/adr/0085-*.md,
docs/adr/0088-*.md, docs/adr/0090-*.md.

CONTENU : en-têtes RateLimit-* par projet, par clé et par classe ; les
métriques par projet (les dashboards Grafana et les règles d'alerte existent
déjà, il manque la dimension projet) ; le relais de l'outbox après commit
(aujourd'hui il est planifié chaque minute — ADR-0077/0078 le veulent après
commit) ; les listings publics par curseur (attention : la pagination par
curseur est DÉJÀ livrée pour toute l'API billing — vérifie ce qui manque
vraiment avant de le refaire) ; les exports par projet.

ATTENTION : S-12 (limitation de débit) est une exigence de sécurité ; elle
conditionne l'ouverture à des organisations tierces. La sonde correspondante
doit exister dans tools/guards/verify-guards.sh et `make guard-test` doit la
voir mordre.

LIVRE : le débit est réellement appliqué par projet, et les métriques portent
la dimension projet.
```

## L14 — Lot 14 : console de portefeuille

```
TÂCHE : livrer le lot 14 — « Console de portefeuille ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (ligne du lot 14), docs/adr/0080-*.md.

CONTENU : les écrans Portefeuille / Projet / Client / Créances / Exceptions ;
les indicateurs MRR, attrition, encaissé, créances, exceptions, usage ; la
table billing_daily_metrics ; la liste de contrôle d'onboarding d'un projet ;
les gestes d'exploitation d'abonnement.

CONTRAINTES : G16 — toute lecture console passe par un port qui prend un
AccessScope en paramètre REQUIS ; G15 — chaque route à identifiant a son cas
IDOR ; aucune donnée personnelle en clair dans la console (ADR-0083).

DÉPENDANCE : le lot 14 dépend du lot 12 (usage). Commence par le lot 12.
```

## R1 — Le rendu du reçu (ADR-0073 §5) et le PDF (ADR-0094 §14)

```
TÂCHE : écrire le rendu du reçu (ADR-0073 §5) et trancher le PDF
(ADR-0094 §14).

CONTEXTE : le lot 11 a livré l'ENREGISTREMENT de l'encaissement hors ligne et
l'imputation, mais pas le DOCUMENT. ADR-0073 §5 exige un reçu pour chaque
montant encaissé : le montant, la date, la référence du virement ou du reçu
hors ligne, la facture qu'il solde. Le portail client (lot 13) doit
l'afficher, et un client veut un reçu qu'il puisse envoyer.

LIVRE :
  1. un reçu rendu en HTML imprimable, PRODUIT PAR PROJECTION — rien n'est
     stocké : le reçu se compose à la lecture depuis la facture et ses
     encaissements (invoice_payments + manual_receipts). Le document imprimé
     doit porter le numéro de la facture, les lignes, les montants, ce qui a
     été réglé et le solde ;
  2. une route GET /v1/invoices/{id}/receipts (scope invoices:read) et la
     vue console équivalente ;
  3. pour le PDF : ADR-0094 §14 exige un ADR DÉDIÉ qui choisit la
     bibliothèque. Écris-le (le PDF attend sa bibliothèque — aucune n'est
     dans composer.json aujourd'hui) et implémente-le, ou tranche par écrit
     que le HTML suffit pour le MVP — mais tranche, et dis-le dans la carte
     des capacités.

LIVRE : GET /v1/invoices/{id}/receipts rend un reçu juste, et la décision
PDF/HTML est prise et écrite.
```

## R2 — Le guide d'intégration (ADR-0084)

```
TÂCHE : compléter le guide d'intégration pour qu'il couvre le commerce.

CONTEXTE : ADR-0084 fait du guide d'intégration LE document unique de
l'intégration, et le lot 17 (recette bout-en-bout) l'exige. Aujourd'hui
docs/integration/guide-curl-integrateur.md (619 lignes) ne couvre ni les
clients, ni les plans, ni les abonnements, ni les factures — c'est-à-dire
exactement ce que le commerce a ajouté.

LIVRE : les sections qui manquent, avec des exemples cURL **exécutables**
(qu'un test vérifie) : créer et mettre à jour un client, créer un plan et
publier une version, créer un abonnement, lire sa facture, la payer en
ligne, enregistrer un encaissement hors ligne, lire ses reçus. Les
exemples doivent porter les vraies portées, les vrais en-têtes (dont
Idempotency-Key) et les vraies réponses.

CONTRAINTES : aucun secret en clair dans le guide ; le guide ne doit rien
promettre que le code ne fait pas.

LIVRE : un intégrateur peut créer un client, s'abonner et payer une facture
en ne lisant que le guide.
```

## L16 — Lot 16 : contrat et expérience développeur

```
TÂCHE : livrer le lot 16 — « Contrat et expérience développeur ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (ligne du lot 16),
docs/adr/0086-*.md, docs/adr/0087-*.md, docs/adr/0096-*.md (nouveau).

CONTENU :
  - la politique de version : Deprecation et Sunset, G9 versionné sur
    openapi/admin.json, énumérations ouvertes (ADR-0086) ;
  - api_version dans les événements ;
  - le changelog au tag ;
  - le registre de capacités vérifié contre l'OpenAPI, la page de démarrage
    testée par capacité, les horloges de test par projet sur le staging
    (ADR-0087) ;
  - le module developer-platform sort du scaffold : il tient le registre
    machine, la route et la page (ADR-0096 diffère son entrée en production
    JUSQU'À ce lot — c'est donc à toi de le construire maintenant).

CONTRAINTES : le graphe de modules change (developer-platform entre) — ADR
requis (ADR-0002). Le registre de capacités est vérifié par un TEST, pas par
une documentation.
```

## L18 — Lot 18 : rapprochement prestataire

```
TÂCHE : livrer le lot 18 — « Rapprochement prestataire » (niveaux B et C).

LIS : docs/specs/plan-delivery-mvp.md §P3 (ligne du lot 18), docs/adr/0078-*.md,
et le module ledger (packages/ledger) — en particulier l'invariant horaire
invariants:check et la table reconciliation_runs.

CONTENU : les capacités listTransactions et balance sur les adaptateurs
(aujourd'hui seul MyCoolPay existe — l'adaptateur MTN MoMo et Orange Money
restent à construire) ; les écrans de rapprochement.

NOTE : ce lot ne conditionne pas la bascule. Il dépend d'un prestataire réel :
si l'adaptateur nécessaire n'existe pas, livre d'abord la capacité sur
MyCoolPay et trace ce qui reste.
```

## L19 — Lot 19 : versements sortants

```
TÂCHE : livrer le lot 19 — « Versements sortants ».

LIS : docs/specs/plan-delivery-mvp.md §P3 (ligne du lot 19), docs/adr/0079-*.md.

CONTENU : un nouveau module `payout` ; le port et l'adaptateur de versement
avec un environnement de test du prestataire ; le callback dédié ; le
balayage des versements en attente ; les plafonds ; la double validation ;
la destination chiffrée.

CONTRAINTES : double validation (S-15 s'applique ici, contrairement à
l'encaissement hors ligne qu'ADR-0095 a exonéré) ; destination chiffrée au
repos (ADR-0083) ; Idempotency-Key sur toute route d'argent (G7) ; le
versement ne part pas sur un compte qui n'a pas été validé.

NOTE : ce lot ne conditionne pas la bascule — c'est le candidat naturel au
report.
```

## H1 — Hygiène : les en-têtes d'ADR (le sweep qui a échoué)

```
TÂCHE : aligner les en-têtes des ADR sur le modèle docs/adr/0000-modele.md.

CONTEXTE : l'audit de livraison a trouvé que 45 ADR n'ont pas de champ
« Décideurs » (le modèle l'exige) et que 32 ADR amendés ne mentionnent pas
leur amendeur dans leur propre ligne « Statut » — ce que docs/adr/README.md
interdit explicitement.

TRAVAIL, fichier par fichier, en-têtes seulement (entre le titre et la
première section « ## Contexte ») :
  1. s'il manque « - **Décideurs** : », insère-la juste APRÈS la ligne
     « - **Date** : », avec la valeur :
       - ADR 0068 à 0090  ->  comité d'architecture (ratifié le 2026-10-27)
       - ADR 0091 à 0099  ->  déjà présente, ne pas toucher
       - autres           ->  l'auteur de l'ADR (décideur non documenté dans
         l'ADR d'origine)
     N'invente jamais un nom de personne ou de comité absent du dépôt ;
  2. si docs/adr/README.md nomme un ou plusieurs ADR qui amendent ce fichier,
     ajoute à la fin de la ligne « - **Statut** : … » : « — amendé par
     ADR-XXXX » (les numéros issus de l'index, séparés par « / »).

NE TOUCHE À RIEN D'AUTRE : ni le corps, ni les titres, ni la mise en forme.

VÉRIFIE : « git diff docs/adr/ » ne doit montrer que des lignes d'en-tête.
```

---

## Ordre recommandé

| # | Prompt | Pourquoi là |
| - | ------ | ----------- |
| 1 | **S1** + **S2** + **S3** | La sécurité conditionne la bascule : S-11 et S-19 ne sont pas livrées du tout, et le lot 11 n'a jamais passé sa revue |
| 2 | **L12** | Il bloque le lot 17 (bascule) et le lot 14 |
| 3 | **L13** | Le plus gros morceau du chemin critique — démarre-le en premier, il est long |
| 4 | **R1** | Le portail (L13) affiche des reçus : R1 le précède |
| 5 | **R2** | La recette du lot 17 a besoin du guide |
| 6 | **L15**, **L14**, **L16** | La voie parallèle |
| 7 | **L18**, **L19** | Hors chemin critique — reportables |
| 8 | **H1** | Hygiène, à tout moment |

Les prompts **S1**, **S2**, **R2** et **H1** ne touchent pas au commerce : ils
peuvent tourner en parallèle de tout le reste. **L12** et **L13** ne doivent
pas tourner en même temps s'ils modifient le même graphe de modules : dans ce
cas, fais **L12 d'abord** (9 j de charge contre 13), et donne à **L13** la
branche de L12 comme base.
