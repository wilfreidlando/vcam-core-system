# Avis d'architecte sur la structure du Core System

**Auteur** : architecte logiciel senior Laravel, mission de prise de connaissance
**Date** : 2026-09-14
**Cible** : Tech Lead et agents en charge de l'implémentation des lots
**Statut** : avis non-contraignant — **traité le 2026-09-14**. Les décisions
prises point par point sont dans
[reponse-avis-architecte-2026-09-14.md](reponse-avis-architecte-2026-09-14.md) ;
en cas de divergence, les ADR de `docs/adr/` prévalent sur ce document.

---

## 1. Verdict global

Le squelette posé est de **classe entreprise**, sensiblement au-dessus de la
médiane des projets Laravel hexagonaux que j'ai croisés en dix ans. Les
décisions structurantes (deux Deptrac, framework-purity du Domain, registre de
garde-fous inactifs, money bigint, outbox + idempotency anticipés) sont solides
et bien articulées. Quelques points méritent d'être tranchés avant d'écrire la
première vraie classe de domaine (Lot 1) — sinon, le coût du changement sera
exponentiel.

---

## 2. Ce qui est excellent

| # | Force | Pourquoi c'est important |
| --- | --- | --- |
| F1 | **Ledger au plus bas, zéro dépendance inter-modules** | Personne ne peut pousser une dépendance sur ton actif financier le plus critique. Rare. |
| F2 | **Deux Deptrac (modules + couches)** | Beaucoup de projets font l'un ou l'autre, rarement les deux bien. La règle « Presentation peut utiliser Domain » est inhabituelle mais pragmatique (énums domaine exposés en réponse API). |
| F3 | **Registre des garde-fous inactifs avec `GuardCoverageTest`** | Discipline rare : une règle qu'on croit active et qui ne mord pas est *pire* qu'aucune règle. Vous avez internalisé ça. |
| F4 | **PHPat pour la framework-purity du Domain** au-delà de Deptrac (qui est lexical). Combiné à Larastan, c'est l'étalon-or pour un Domain pur PHP en écosystème Laravel. |
| F5 | **Money = `bigint` minor units + bcmath/gmp + Brick/Money** | Choix non-négociable pour de la finance, verrouillé dès le Dockerfile (`--data-checksums` PG18, ADR-0017). |
| F6 | **Makefile miroir exact de la CI** + lefthook < 10 s | Pas de divergence local/CI — la principale source de « ça marchait chez moi ». |
| F7 | **Valkey au lieu de Redis 8** (AGPL) | Décision juridique correcte pour un propriétaire financier. |
| F8 | **`CanonicalStatusTest` et `IdempotencyTest` déjà câblés en Pest avec `markTestSkipped`** | Quand le premier code arrivera, la CI mord automatiquement — pas d'oubli. |

---

## 3. Ce qui mérite discussion avant Lot 1

### 3.1 Quinze modules au jour 0 — risque de sur-modularisation prématurée

Aucun `src/Domain/*.php` n'est encore commité, mais le graphe de 15 modules
est déjà gravé dans le marbre (Deptrac + tests). Vous dessinez les frontières
*avant* d'avoir observé la cohésion réelle. C'est courageux, mais l'inconvénient
majeur : un module mal calibré devient un contrat (`Contracts/` publié), et
refactorer un contrat coûte cher.

**Question à trancher** : y a-t-il eu une *event storming* ou un atelier de
découverte qui justifie le découpage actuel, ou est-ce une projection *a priori* ?

**Recommandation** : retarder la création des modules vides (`Events`,
`Webhook`, `Notification`, `Billing`, `Storage`, `DeveloperPlatform`, `Admin`)
jusqu'à ce que le besoin concret émerge. Les laisser dans Deptrac tant qu'ils
n'existent pas, c'est geler des frontières hypothétiques.

### 3.2 `Admin` au sommet du graphe — anti-pattern connu

`Admin` dépend de *tout*. C'est un god-module de fait. Vous le savez (« by
design » dans `deptrac-modules.yaml`), mais c'est le terreau classique du
module « qui lit les tables des autres » et qui casse la règle G3 dans la
pratique.

**Mitigation à inscrire dans la convention** : forcer `Admin` à lire via les
`Contracts/` (read models) exclusivement, jamais via Eloquent cross-context.
À vérifier par un test d'architecture dédié (pas seulement par Deptrac, qui ne
peut pas distinguer « utilise un read model » de « utilise un Eloquent »).

### 3.3 `Support` comme *shared kernel* — piège du « junk drawer »

Tous les modules y ont accès, et lui n'accède à rien. C'est exactement le
profil du module qui finit par contenir `StringHelper`, `Json`, `Clock`, *et*
aussi une classe métier par accident.

**Mitigation** : un manifeste explicite de ce qui a le droit d'y vivre — et
seulement :

- `Clock` et `SystemClock`
- Génération d'identifiants (ULID, UUID v7)
- `Money` helpers (formats, arrondis)
- `Result` / `Outcome` (algebraic data types)
- `Idempotency-Key` (stockage + extraction, livré en **Lot 0** — voir §3.6)
- `Outbox` primitive (port + impl, livré en Lot 4)
- `Event` primitive (value object enveloppe)

Toute classe qui regarde une table, parle HTTP, ou contient une logique métier
n'a rien à faire dans `Support/`. Un test d'architecture qui refuse les
classes `Illuminate\*` dans `Support/Domain/` suffit.

### 3.4 CQRS n'est pas explicite

`Contracts/` contient des « read-model DTOs » d'après le commentaire Deptrac.
Donc vous êtes en CQRS-light, mais sans qu'on l'ait nommé ni qu'on ait tranché :

1. Qui possède les projections ? (`Support` ? un module `Reporting` dédié ? chaque module, en lecture ?)
2. L'outbox (G8) publie-t-il aussi les projections, ou seulement les events ?
3. Les read models vivent dans le module propriétaire ou dans un module séparé ?

**Recommandation** : trancher maintenant, parce que la première équipe qui a
besoin d'un reporting jointif improvisera une jointure cross-context et
violera G3. Proposition : chaque module expose ses read models dans son propre
`Contracts/`, et seul `Admin` agrège (via API Contracts, jamais via SQL direct).

### 3.5 Outbox : central ou par-module ?

G8 dit « transactional outbox » mais on ne sait pas si chaque module a sa table
`outbox_messages` (préférable — localité du commit) ou si une seule table
globale existe (plus simple, mais coupling temporel sur le commit cross-modules).

**Recommandation** : **outbox par module**, relay unique. Une transaction qui
touche `Wallet` + `Payment` doit pouvoir commettre chaque outbox locale
indépendamment. Le relay lit toutes les tables `outbox_messages` par polling
ou LISTEN/NOTIFY et dispatche sur un bus unique.

Schéma cible :

```
support.outbox_messages       (shared primitive, owned by Support)
ledger.outbox_messages       (uniquement events ledger)
payment.outbox_messages
wallet.outbox_messages
...
```

### 3.6 Idempotency (G7) — la pièce la plus critique, à livrer en Lot 0

Pour un système où une seconde requête réseau = double débit, l'idempotency est
*plus* prioritaire que l'outbox. Vous l'avez dans le registre, mais le pattern
de stockage (clé + request hash + réponse cachée + TTL) n'est pas encore
construit.

**Recommandation** : un *building block* `Support\Idempotency` livré en **Lot 0**,
avant même Lot 1. Middleware Laravel (`IdempotencyMiddleware`) + table
`idempotency_keys` (clé, hash du body, réponse JSON cachée, expires_at). Le
premier endpoint du Lot 3 n'aura alors qu'à appliquer le middleware — pas de
discussion possible, c'est dans le substrat.

### 3.7 Stratégie de versioning API — pas encore tranchée

Vous avez Scramble pour OpenAPI et G9 (pas de rupture sans montée de version),
mais **où vit `v2`** ?

- Header `Accept: application/vnd.core.v2+json` ?
- Préfixe d'URL `/api/v2/...` ?
- Sous-domaine `v2.api.core...` ?

**Recommandation** : préfixe d'URL, parce que (a) le plus simple à router dans
Laravel, (b) le plus visible dans les logs et la doc, (c) le seul qui marche
quand le consumer est un SDK ou un navigateur sans header custom. Réserve :
quand vous aurez 50 controllers dans `Presentation/`, le choix sera douloureux.

### 3.8 Mutation testing — l'angle mort

Pour un système financier, c'est la seule technique qui prouve que vos tests
*détectent* réellement un bug. Pest a le plugin Infection. Son absence est
notable vu le niveau d'exigence par ailleurs.

**Recommandation** : ajouter `infection/infection` en Lot 4 ou Lot 5, avec un
seuil `MSI ≥ 75 %` sur le Domain (les autres couches sont moins critiques).

### 3.9 `.deptrac.cache` versionné (52 Ko)

D'habitude on gitignore les caches d'analyseurs. Le versionner réduit le coût
du premier run, mais lie les contributeurs à votre version exacte de Deptrac.
À arbitrer consciemment — actuellement aucun `.gitignore` ne l'exclut.

---

## 4. Premier périmètre : l'architecture d'intégration externe

Le **premier périmètre fonctionnel** du Core, ce n'est pas le Domain Identity —
c'est le **socle d'intégration** sur lequel tous les providers viendront se
brancher (MTN, Orange, CinetPay, DGI, Firebase, Twilio, SendGrid, etc.). Si ce
socle est mal posé, chaque Lot 3+ réinventera son propre `Guzzle::post()` avec
ses propres bugs.

**Principe fondamental** : le Core est un **intermédiaire**. Apps métier ↔
Core ↔ providers. Aucune app ne parle jamais directement à MTN, CinetPay ou
Firebase. Le Core possède **toute la volatilité** côté provider et expose
aux apps un **contrat canonique immuable** (REST versionnée + webhooks +
événements). C'est la discipline Anti-Corruption Layer appliquée au flux
d'intégration.

Un document dédié est livré séparément :
[`guides/architecture-integration-externe.md`](guides/architecture-integration-externe.md).

Il pose **quatre piliers transverses** + **trois patterns d'orchestration**
qui doivent être livrés **avant** le premier adapter concret :

**Pilliers :**

1. **Templates de configuration provider** — `ProviderConfig` value object +
   `config/providers.php` qui uniformise URL, environnement, credentials,
   timeouts, retry policy, circuit breaker pour *tous* les providers.

2. **Service d'appel HTTP central** — `ExternalApiCaller` injecté partout,
   qui encapsule authentification, signature HMAC, retries avec backoff
   exponentiel, logging structuré avec rédaction de secrets, propagation du
   `trace_id`, respect de l'`Idempotency-Key`. Aucun adapter ne fait
   `Http::post()` directement.

3. **Templates de requête et de réponse** — `RequestTemplate`,
   `ResponseTemplate` et leurs `*Spec`. La forme de chaque appel HTTP est
   *décrite* dans une classe PHP (path avec placeholders, sources des
   headers, shape du body, statut, extractions), pas codée en dur. 70 % du
   travail d'un nouvel adapter devient déclaratif.

4. **Famille de Mappers typés** — `RequestMapper`, `ResponseMapper`,
   `StatusMapper`, `WebhookMapper`. Interfaces partagées, implémentations
   par adapter, règle PHPStan G6 garantit qu'aucun statut brut provider ne
   franchit la frontière.

**Patterns d'orchestration :**

5. **Anti-Corruption Layer (ACL)** — Apps ne voient jamais le provider.
   Tests d'architecture (`AppsNeverSpeakProviderTest`) verrouillent la
   vocabulary integrity.

6. **AdapterLog persistant** — Chaque appel provider est persisté en base
   (`support.adapter_logs`) avec payload envoyé, réponse, headers, latence,
   statut, erreurs. C'est la mémoire du Core — debug trivial, audit
   financier, conformité.

7. **Saga asynchrone** — `ContinueOperation` / `CompensateOperation` +
   handlers `AsMessageHandler`. Les opérations longues (paiement async,
   notification délivrée tardivement) sont des sagas : chaque handler fait
   une partie, dispatche la suivante, déclenche un rollback en cas
   d'échec.

8. **Dev tools** — Commandes artisan `core:adapter:make`, `core:adapter:list`,
   `core:adapter:check` pour scaffolder et vérifier la complétude d'un
   provider.

> **Note** : le chiffrement symétrique du payload (AES sur le corps de la
> requête) n'est **pas** un pilier — c'est un cas rare, traité comme une
> extension de l'`AuthProvider`. Par défaut, TLS + signature HMAC suffisent
> pour 95 % des providers.

---

## 5. Résumé

| ✅ Forces | ⚠️ À trancher avant Lot 1 |
| --- | --- |
| Hexagonal strict, deux Deptrac, framework-purity du Domain | Périmètre de `Support` (manifeste explicite) |
| Ledger isolé, money bigint, outbox + idempotency anticipés | Outbox par-module vs central |
| CI = Makefile, lefthook rapide, quality gates stricts | Stratégie de versioning API |
| Registre de garde-fous inactifs — discipline rare | CQRS / read models : propriétaire ? |
| Valkey, PG18 data-checksums, Brick/Money | Mutation testing (Lot 4+) |
| `CanonicalStatusTest` et `IdempotencyTest` câblés en skip | `Admin` doit lire via `Contracts/` exclusivement |
| | Idempotency en building block **Lot 0** |

**Recommandation prioritaire** : livrer en **Lot 0** l'idempotency, l'outbox,
et **le socle d'intégration externe complet** (4 piliers : config templates +
`ExternalApiCaller` + templates de requête/réponse + mappers + 3 patterns
d'orchestration : ACL + `AdapterLog` persistant + saga asynchrone), parce que
c'est le substrat sans lequel les Lots 2 et 3 seront construits sur du sable.

Lot 0 n'est pas un luxe : c'est le pré-requis technique et architectural
pour qu'aucun adapter métier ne réinvente la roue, et que les apps
métier voient un contrat canonique stable dès le premier jour.

---

*Document à discuter en revue d'architecture avant Lot 1.*