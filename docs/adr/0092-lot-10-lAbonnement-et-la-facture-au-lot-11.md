# ADR-0092 — Lot 10 : ce que le contrat d'abonnement peut faire avant la facture

- **Statut** : Accepté
- **Date** : 2026-10-27
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27), sur proposition de
  l'implémentation (lot 10)
- **Amende** : ADR-0072 (abonnements), ADR-0073 (factures), ADR-0070 (clients),
  ADR-0071 (catalogue)
- **Dérives concernées** : D5 contrat · D6 intégrité financière · D7 organisationnelle

## Contexte

Le plan de delivery découpe le commerce en deux lots : le **lot 10** livre le
client, le catalogue et le contrat d'abonnement (ADR-0070, ADR-0071, ADR-0072) ;
le **lot 11** livre les factures, l'encaissement et les reçus (ADR-0073). Les deux
ADR ne sont pourtant pas séparables : ADR-0072 dit que la première facture d'un
abonnement payant est créée **dans la même transaction** que l'abonnement, et que
la transition `incomplete → active` se produit « facture payée ».

Le lot est donc livré avec une **dépendance circulaire** : l'abonnement parle de
facture, la facture parle d'abonnement. Il faut choisir — soit on livre la facture
dans le lot 10 (et le lot 11 se réduit à la gestion du recouvrement, ce qui
contredit le découpage ratifié), soit on livre l'abonnement **sans** sa facture et
on écrit noir sur blanc ce que le contrat peut faire dans l'intervalle.

La seconde option est la seule compatible avec le découpage, à condition d'être
explicite : un module livré à moitié doit dire ce qu'il ne fait pas.

## Décision

### 1. La facture appartient au lot 11 — une période existe avant sa facture

`subscription_periods.invoice_id` reste **nul** tant qu'ADR-0073 n'est pas livré.
Une période `paid` est créée, elle n'est pas encore facturée : le fait de paiement
et le document de facturation sont deux choses, et seul le second est un document
comptable.

**Conséquence** : en lot 10, un abonnement payant **ne peut pas devenir `active`
par un paiement**. Le chemin public qui mène à `active` est la période
**complimentary** — un acte d'exploitant, gratuit, motivé, sans facture
(ADR-0072 §6). C'est déjà le chemin prévu par l'ADR pour « le mois offert ».

### 2. Le fait de paiement est un **champ** de la période, pas un couple

`subscription_periods.paid_at` est la vérité unique : la période est-elle payée ?
Un seul cas d'usage l'écrit — `ConfirmSubscriptionPeriodPayment` — ce qui évite
qu'une période soit « payée » par un chemin et « impayée » par un autre.

Ce cas d'usage **ne déplace aucun argent**. L'argent est l'affaire d'ADR-0073
(facture, imputation au grand livre, encaissement hors ligne à quatre vues). En
lot 10, l'acte est une **déclaration** — la console déclare qu'une période a été
réglée — et le lot 11 la remplace par l'encaissement réel, qui bouge le grand
livre et exige l'approbation d'un second compte.

### 3. Les actes de l'exploitant sont audités par le contrôleur de console

ADR-0072 §6 veut que les gestes de l'exploitant soient « des actes audités ». Or le
module `billing` **ne dépend pas** du module `audit` (graphe de modules ratifié,
ADR-0068), et ajouter cette arête serait une modification du graphe.

Le dépôt a déjà un précédent : l'enregistrement d'une application provider est
audité **par le contrôleur admin**, pas par le cas d'usage, précisément parce que
`payment` ne peut pas dépendre d'`audit` (ADR-0052). Le lot 10 suit ce précédent :
les gestes d'exploitation (pause, reprise, annulation, mois offert, changement de
plan) sont audités par le contrôleur de console, qui vit hors des modules. La trace
publique de ces gestes passe par les événements de l'outbox.

### 4. `billing_settings` : quatre réglages, pas plus

Un enregistrement par projet : le **fuseau** (défaut `Africa/Douala` — ADR-0071
§4), la **devise par défaut**, le **délai de préparation du renouvellement**
(7 jours) et le **délai d'expiration d'un abonnement incomplet** (72 heures).
Les réglages des lots 11 à 14 (seuil d'encaissement manuel, correspondance des
compteurs, durée de conservation) viendront avec les ADR qui les introduisent : un
réglage sans usage est une hypothèse qui vieillit.

### 5. Les fonctionnalités sont validées **par format**, jamais par liste

Le Core ne connaît aucun nom de fonctionnalité (ADR-0071 §3, ADR-0090). Le nom
d'une fonctionnalité et le nom d'un compteur sont validés par
`^[a-z0-9_.]{1,64}$` ; le type est l'un des deux types de la v1 (`boolean`,
`limit`). Une fonctionnalité nommée « students » est le vocabulaire d'un projet,
pas celui du Core.

### 6. Le prix est figé sur l'abonnement, et la version publiée est immuable

L'abonnement copie le prix, la devise et l'intervalle de la version du plan au
moment de la signature (ADR-0071 §Conséquences). Publier une nouvelle version ne
touche pas les abonnements en cours ; changer un prix est publier une nouvelle
version, jamais modifier l'ancienne. L'immuabilité est garantie **en base** par un
trigger (`plan_version_immutable`), pas seulement par le code : une ligne écrite
par n'importe quel chemin est protégée de la même façon.

### 7. `customers` a une liste blanche de colonnes — garde-fou **G4b**

ADR-0070 l'exige : toute nouvelle colonne de `customers` exige un ADR. La liste
blanche est un test de schéma (`tests/Schema/CustomersColumnsTest.php`), et le
test vérifie en plus que les valeurs personnelles (email, téléphone) sont
chiffrées au repos — un `email` en clair échoue le build.

### 8. La plane publique déclare ses corps dans des `FormRequest`

Les mutations de l'API publique (`PUT /v1/customers/{externalRef}`,
`POST /v1/plans`, `POST /v1/plans/{code}/versions`, `POST /v1/subscriptions`,
`POST /v1/subscriptions/{id}/cancel`, `…/change-plan`,
`POST /v1/customers/{externalRef}/erase`) reçoivent un **`FormRequest`**, comme
les modules `payment`, `notification`, `wallet` et `webhook` le font déjà.

Deux raisons, pas une : le corps est **documenté** (une description et un
exemple par champ — la doc `/docs/api` est le premier contact du client), et
l'export OpenAPI ne produit **aucun avertissement** (une validation écrite
dans le contrôleur force l'exportateur à deviner le type d'un paramètre qu'il
ne peut pas construire). Les règles sont **inchangées** : le déplacement de
la déclaration ne change ni le contrat HTTP ni le code de sortie.

### 9. La console de la facturation est **portée par le projet**

Un client, une offre et un abonnement appartiennent à un projet : il n'existe
pas de liste globale de clients. La console les atteint donc depuis la fiche
du projet, comme elle atteint les wallets et les endpoints webhook.

L'entrée de la barre latérale (« Facturation ») envoie d'abord vers la liste
des projets, et la fiche du projet porte les trois liens. Une entrée qui
mènerait à une page que le compte ne peut pas ouvrir n'existe pas : le
rôle doit donc tenir `projects:read` **avec** la permission de facturation.

Conséquence sur les rôles d'organisation : `org_finance` et `org_support` —
les rôles que la spécification fonctionnelle §8.1 désigne pour les clients,
les abonnements et les factures — portent désormais `projects:read`. Sans
cela, `subscriptions:write` serait un droit que personne ne pourrait
exercer.

### 10. Une ressource absente : 404 pour une lecture, redirection pour un geste

La console a deux réponses pour « cette ressource n'existe pas ici » :

- une **lecture** répond **404** (page « Client introuvable. ») ;
- un **geste** répond la **redirection « introuvable »** de la console, avec
  l'erreur affichée et l'opérateur ramené à sa page (l'idiome de la rotation
  d'une clé d'API, ADR-0069 §2).

Jamais 403 : un 403 confirmerait que la ressource existe ailleurs
(ADR-0081 S-04). La console ne dit jamais *où* elle est.

Le harnais IDOR (G15) porte cette distinction : une lecture doit répondre
404, un geste doit rediriger sans message de succès.

## Alternatives écartées

- **Livrer la facture avec l'abonnement (lot 10 élargi).** Rejeté : la
  numérotation, les documents, les encaissements et les reçus forment un lot à
  part entière (ADR-0073, 12 j). Prévenir la décision de découpage sans livrer
  la moitié du lot 11 ne l'amènerait pas.
- **Un module `invoices` séparé.** Rejeté pour la v1 : la facture et l'abonnement
  partagent la même unité de compte (le client d'un projet) et la même période.
  Les séparer créerait une arête circulaire (`billing → invoices → billing`).
- **Un drapeau « sans facture » dans le code.** Rejeté : un drapeau se contourne.
  L'absence de facture est un **état du schéma** (`invoice_id` nul), lisible par
  tout le monde, pas une convention.
- **Faire de la déclaration de paiement un faux encaissement** (une écriture
  « acquittée » au grand livre). Rejeté : c'est exactement le paiement fictif
  qu'ADR-0072 refuse.

## Conséquences

- Un abonnement payant créé en lot 10 reste `incomplete` (ou `trialing`) jusqu'à
  la facturation ; il peut devenir `active` par une période complimentary.
- `ConfirmSubscriptionPeriodPayment` est le **point de couture** que le lot 11
  remplace : l'implémentation change, l'appel ne change pas.
- Le garde-fou **G4b** entre au registre des garde-fous actifs et sa sonde est
  exercée par `make guard-test`.

## Vérification

- Test : une période `paid` porte `invoice_id` nul, et le contrat n'est pas
  `active` sans paiement ni période complimentary.
- Test : la déclaration de paiement écrit `paid_at` et n'écrit **aucune** ligne
  au grand livre.
- Test : le trigger refuse la modification du prix d'une version publiée.
- Test : `make guard-test` rejette une colonne ajoutée à `customers`.
- Test : la console ouvre ses trois pages chez son propre tenant
  (`tests/Feature/Admin/BillingConsoleWebFlowTest.php`) — un 500 sur la page
  des offres est détecté.
- Test : le harnais IDOR exige 404 pour les lectures de la facturation et la
  redirection « introuvable » pour les gestes.
- Test : `scramble:export --path=openapi/v1.json` ne produit **aucun**
  avertissement.
