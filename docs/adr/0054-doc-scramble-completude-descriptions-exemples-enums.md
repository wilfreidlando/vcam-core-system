# ADR-0054 — La doc Scramble OpenAPI doit être aussi complète que l'API elle-même : descriptions, exemples, choix bornés

- **Statut** : Accepté — **point 5 (garde-fou) non livré** : constat du 2026-10-05, `tests/Architecture/OpenApiCompletenessTest.php` n'a jamais existé dans l'historique git ; seuls le snapshot `openapi/v1.json` et `make openapi` (points 1 à 4) sont en place
- **Date** : 2026-09-22
- **Décideurs** : équipe Core (auto-imposé, déclencheur : constat empirique pendant l'audit de `/docs/api` contre l'API réelle)
- **Dérives concernées** : D5 contrat, D2 couplage

## Contexte

Audit manuel par `curl` de chaque endpoint documenté dans `/docs/api` (doc
générée par `dedoc/scramble` 0.13.43) contre l'API réelle, mené pendant le
correctif de doc du commit `6e37ff2`. Constats :
- **Aucun attribut de payload de requête n'a de description** dans la doc.
  Seules deux descriptions existaient (toutes deux ajoutées manuellement par
  les auteurs des règles : `url` dans `RegisterWebhookEndpointRequest`,
  `callback_url` dans `StartPaymentRequest`). Pour les 25 autres champs
  documentés, la valeur, l'unité, la sémantique et les bornes métier étaient
  invisibles côté lecteur.
- **Aucun attribut n'a d'exemple** de valeur attendue. Un intégrateur ne
  sait pas si `amount` est en centimes ou en unités majeures, si
  `customer_phone_number` est en E.164 ou en format local, etc.
- **Les champs à choix fini ne sont pas bornés dans la doc** : `channel`
  (`mtn_momo`, `orange_money`, `card` côté paiement ; `whatsapp`, … côté
  notification), `flow` (`payin`/`paylink`), `events` (types d'événements
  émis par l'outbox). Un intégrateur peut soumettre une valeur non supportée
  sans avertissement préalable côté doc.
- Le seul champ borné correctement aujourd'hui est `flow` (via
  `Rule::enum(PaymentFlow::class)` dans `StartPaymentRequest`).

Ces manques violent D5 (contrat : un consommateur a besoin de la doc pour
intégrer sans lire le code), et matérialisent une **dette systématique** : la
doc reflète le strict minimum du typage statique, pas le contrat métier.

Scramble supporte nativement les trois mécanismes qui comblent ces manques,
mais aucun n'est utilisé systématiquement :
1. **Description** : commentaire PHPDoc (`/** ... */`) ou commentaire `//`
   *juste au-dessus* de chaque entrée du tableau `rules()` de la
   `FormRequest` — Scramble les parse via PHP-Parser
   (`RulesNodes::makeFromStatements()`) et les colle dans `description` du
   champ correspondant. Les commentaires inline `// url: ...` dans le corps
   de `rules()` ne fonctionnent pas ; seul le commentaire immédiatement
   précédent l'item de tableau est lu.
2. **Exemple** : tag `@example` dans le même PHPDoc. Le tag est extrait par
   `ExamplesExtractor::extract()` et la valeur est typée (string/int/bool/
   JSON décodé) avant d'être posée sur `Type::examples()`. Le format
   `@example "mtn_momo"` produit `"mtn_momo"` ; `@example 1000` produit
   `1000` (int).
3. **Choix bornés** : `Rule::in(['a', 'b'])` produit un `enum: ['a', 'b']` ;
   `Rule::enum(SomeBackedEnum::class)` produit un enum typé avec valeurs.

L'ADR est rendue nécessaire par le constat qu'**aucun des trois n'est utilisé
systématiquement** : la première fois qu'on en a besoin sur un nouvel
endpoint, c'est au bon vouloir de l'auteur. La gouvernance anti-dérive
(§4) considère qu'une règle qu'on doit se rappeler d'appliquer n'est pas
une règle — c'est un souhait.

## Décision

**Toute modification d'un endpoint HTTP existant ou toute création d'un nouvel
endpoint HTTP doit publier dans `/docs/api` (et `/docs/admin` pour le plan
opérateur) un schéma de payload aussi complet que ce qui suit, et cette
complétude est exigée par un garde-fou dédié.**

1. **Description obligatoire pour chaque attribut** de payload (corps de
   requête, paramètres de requête, paramètres de chemin, paramètres
   d'en-tête). Forme : commentaire PHPDoc `/** ... */` *immédiatement
   au-dessus* de l'entrée correspondante dans `rules()` (pour les
   `FormRequest`) ou dans la déclaration de la propriété (pour les DTO
   lus directement par Scramble). Une description doit dire ce que la
   valeur **représente**, pas paraphraser le nom du champ.
2. **Exemple obligatoire pour chaque attribut** (sauf les secrets, qui sont
   omis et marqués `writeOnly` côté doc). Forme : tag `@example` dans le
   même PHPDoc, valeur typée. Les exemples doivent être **réalistes** :
   valeurs qui passeraient la validation métier, pas des valeurs
   volontairement invalides. Pas de `<UUID>` ou `<value>`.
3. **Choix bornés obligatoires** pour tout attribut dont le domaine de
   valeurs est connu statiquement : `Rule::in([...])` pour les énumérations
   ouvertes (liste de canaux, de types d'événements), `Rule::enum(...)`
   pour les `BackedEnum`. Les énumérations dites « ouvertes » (où
   l'ensemble croît sans changement de code, comme `NotificationChannel`)
   utilisent `Rule::in(array_keys(config('...')['default']))` synchronisé
   avec le fichier de config correspondant.
4. **Les snapshots `openapi/v1.json` et `openapi/admin.json`** sont
   régénérés par `make openapi` et la commande fait partie du pipeline
   `make ci`. Toute PR qui modifie un endpoint sans régénérer le snapshot
   fait échouer le diff de snapshot.
5. **Garde-fou** : nouveau test d'architecture dans
   `tests/Architecture/OpenApiCompletenessTest.php` qui ouvre
   `openapi/v1.json` et vérifie, pour chaque paramètre de body documenté :
   - présence d'une `description` non vide,
   - présence d'un `example` ou `examples`,
   - présence d'un `enum` si l'attribut est borné statiquement (vérifié
     contre une table de référence maintenue par le même fichier — voir
     « Structure » plus bas).
   Le test skip les attributs explicitement marqués secrets/jetons (par
   exemple `secret` retourné une seule fois à la création d'un webhook
   endpoint).

### Structure

- Le tableau de référence « attribut → enum attendu » vit dans
  `tests/Architecture/OpenApiCompletenessTest.php` lui-même, en `const`
  PHP. Pas de fichier JSON séparé : la table doit évoluer en même temps que
  le code, et un fichier JSON séparé se désynchroniserait.
- Le test charge chaque schéma référencé par un `requestBody.content."application/json".schema.$ref`
  de `openapi/v1.json` et applique les trois règles ci-dessus.
- Pour les schémas de réponse, seule la règle 1 (description) est exigée —
  pas d'exemple (les réponses sont des instantanés, pas des gabarits), pas
  d'enum (sauf `status` canonique qui est déjà géré par l'extension
  `ExceptionProblemResponseExtension`).

### Exceptions

- **Secrets et identifiants opaques** : `secret` (webhook endpoint, retourné
  une seule fois), `key_hash`, identifiants internes — pas d'exemple, pas
  de `writeOnly` (Scramble ne supporte pas `writeOnly` nativement, donc on
  omet simplement l'exemple). Cette exemption est explicite dans le test.
- **Attributs strictement techniques** que personne ne renseigne à la
  main : `Idempotency-Key` est documenté par `IdempotencyHeaderExtension`
  (extension Scramble, pas par `FormRequest`), pas concerné par ce
  garde-fou. Les en-têtes `Authorization`, `Accept`, `Content-Type` non
  plus.

## Alternatives écartées

- **Confier à la doc générée statiquement par PHPStan/Reflection** ce que
  Scramble sait déjà inférer (types primitifs, `Rule::enum(...)`) et ne rien
  demander pour le reste. Rejeté : le constat empirique montre que les
  trois mécanismes de Scramble ne sont pas appliqués aujourd'hui. Une règle
  tacite n'est pas une règle.
- **Documenter la complétude dans `CONTRIBUTING.md` plutôt qu'en garde-fou**.
  Rejeté : gouvernance §4 — une règle non outillée mécaniquement n'est
  qu'un souhait, et la dette observée aujourd'hui prouve qu'on n'applique
  pas ce genre de souhait spontanément.
- **Générer les exemples par fuzzer à partir de la suite de tests**
  (capturer les payloads réels des tests d'intégration). Intéressant mais
  rejeté pour cette ADR : les tests existants n'envoient que des payloads
  valides minimaux (souvent sans tous les champs optionnels), donc la doc
  serait plus pauvre qu'avec des exemples manuels. À reconsidérer dans une
  ADR ultérieure si la complétude manuelle devient un frein.
- **Renoncer aux `enum` dans la doc** pour les champs à domaine ouvert
  (canal de notification). Rejeté : un intégrateur doit savoir que
  `whatsapp` est le seul canal configurable aujourd'hui — même si le format
  reste ouvert (`/^[a-z][a-z0-9_]*$/`), l'ensemble effectivement routable
  est fermé. La doc doit refléter l'état du config, pas la regex de
  validation.

## Conséquences

- **Plus facile** : un intégrateur tierce lit `/docs/api` et a tout ce qu'il
  faut pour appeler l'API sans lire le code PHP. Réduction des questions
  « quel format ? » en support.
- **Plus difficile** : tout ajout d'attribut de payload demande un
  PHPDoc descriptif + `@example` à écrire, et un `Rule::in`/`Rule::enum`
  si le domaine est borné. Coût estimé : ~30 secondes par champ. C'est
  l'intention.
- **Engage** :
  - mise à jour des six `FormRequest` actuelles (Lot 3 payment,
    Lot 4 webhook, Lot 5 notification, lot 6 channel session) pour publier
    descriptions + exemples + enums là où c'est manquant ;
  - création de `tests/Architecture/OpenApiCompletenessTest.php` et
    branchement sur `make ci` ;
  - ajout d'une ligne dans `Makefile` pour régénérer les snapshots
    (`openapi:check` qui échoue si `git diff openapi/*.json` n'est pas
    vide après `make openapi`), pour qu'un contributeur ne puisse pas
    oublier de régénérer ;
  - la table de référence « enum → valeurs » dans le test doit être tenue
    à jour à chaque ajout de canal / type d'événement / flow. C'est
    exactement ce qu'on veut.
- **Point de réévaluation** : dans 6 mois, vérifier si le coût de tenue
  manuelle de la table de référence reste acceptable ou si la voie fuzzer
  (alternative écartée) devient pertinente. Si oui, ADR de remplacement.