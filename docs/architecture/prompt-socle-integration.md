# Prompt de passation — socle d'intégration générique

> Ce fichier est le prompt à donner à l'agent qui a conçu le guide
> `docs/architecture/guides/architecture-integration-externe.md`, pour qu'il
> démarre la réalisation du socle générique. Conservé dans le dépôt comme
> trace de la passation.

---

Tu as écrit `docs/architecture/guides/architecture-integration-externe.md`
(l'avis d'architecte du 2026-09-14 sur l'intégration des providers externes).
Le Tech Lead a tranché sur ce que tu proposais : **lis d'abord, dans l'ordre**,

1. `docs/adr/0026-integration-providers-externes.md` — la décision qui retient
   une partie de ton guide et en rejette une autre (résumé : pas de mode
   `idle` à faux succès, pas de table `support.adapter_logs`, pas de DSL de
   templates avant d'avoir vu deux providers réels).
2. `docs/adr/0031-socle-integration-en-parallele-du-lot-2.md` — l'amendement
   qui te concerne directement : **le socle générique est avancé, à construire
   maintenant, en parallèle du Lot 2 (Money Core)**, pas après.
3. Le bandeau en tête de ton propre guide (mis à jour) — il indique
   précisément ce qui est retenu vs rejeté, section par section.
4. `docs/adr/0020-shared-kernel-support.md` et `packages/support/README.md` —
   les règles du shared kernel où ce socle va vivre.
5. `CLAUDE.md` à la racine — les règles dures et les commandes du projet.

## Ta mission

Construire `Core\Support\Integration` : le socle HTTP sortant générique que
**tout** futur adaptateur provider (paiement au Lot 3, notification au Lot 5)
consommera, sans jamais faire d'appel HTTP lui-même.

### Ce qui est dans le périmètre maintenant

- `ExternalApiCaller` — le point d'appel unique. Pipeline : résolution config
  → vérification `enabled` (kill switch) → circuit breaker → authentification
  → signature → en-tête d'idempotence si fourni → log structuré corrélé
  (avant l'appel) → envoi HTTP → classification de la réponse → log structuré
  corrélé (après l'appel) → retour.
- `AuthProvider` et ses stratégies : Bearer, Basic, ApiKey (au minimum). Une
  interface + une implémentation par stratégie.
- `RequestSigner` : au moins HMAC-SHA256. Interface d'abord, pour que Lot 3
  puisse ajouter RSA/AWS SigV4 sans toucher au socle.
- `RetryPolicy`, `TimeoutPolicy`, `CircuitBreakerPolicy` — value objects
  typés, pas des tableaux associatifs.
- `ProviderConfig` — VO qui regroupe les politiques ci-dessus pour un appel
  donné (base URL, auth, timeouts, retry, signature, kill switch `enabled`).
  Ne connaît aucun nom de provider concret.
- `PayloadRedactor` — masque les secrets (`Authorization`, tokens, etc.) avant
  qu'un payload ou des headers n'atteignent un log.
- Vérification de signature des webhooks entrants (la partie générique :
  comparaison HMAC timing-safe, détection de clock skew) — le mapping du
  payload vers un événement canonique reste spécifique à chaque provider et
  n'est PAS dans ce périmètre.
- Tests unitaires de chaque composant, avec un transport HTTP mocké (pas
  d'appel réseau réel dans la suite `unit`).

### Ce qui n'est PAS dans le périmètre (laisse-le au Lot 3)

- `RequestTemplate` / `ResponseTemplate`, `EndpointCatalog`,
  `ProviderTemplateDefaults` — le DSL déclaratif. Rejeté pour maintenant :
  ADR-0026 explique pourquoi (besoin de deux providers réels pour être
  calibré correctement).
- Tout adaptateur concret (`MtnMomoAdapter`, `OrangeMoneyAdapter`…) et tout
  littéral de statut provider.
- Le bus de saga (`ContinueOperation`/`CompensateOperation`).
- **Le mode `idle` à succès simulé** — explicitement rejeté (ADR-0026) :
  risque financier direct. Le kill switch (`enabled=false`) doit **rejeter
  proprement** (exception typée / réponse d'erreur), jamais renvoyer un faux
  succès.
- Toute table de persistance (`adapter_logs` ou équivalent) — le suivi des
  appels passe par les logs structurés corrélés, pas par une écriture en
  base (garde-fou G3d : le shared kernel ne persiste rien).

### Contraintes non négociables (vérifiées par la CI)

- **G6c** : seules les classes sous `Core\Support\Integration` ont le droit
  de référencer `Illuminate\Support\Facades\Http`, Guzzle, ou tout client
  HTTP PSR — vérifié par
  `tools/PhpStan/Architecture/OutboundHttpIsCentralised.php`. La règle
  t'exempte déjà (exclusion sur ce namespace précis) ; ne renomme pas le
  namespace sans mettre à jour la règle en conséquence.
- **G3d** : aucune classe de `core/support` ne référence Eloquent, `DB`,
  `Schema`, `Redis` — vérifié par
  `tools/PhpStan/Architecture/SharedKernelStaysTechnical.php`.
- Aucun secret en clair dans un log — c'est le rôle du `PayloadRedactor`, pas
  une option.
- PHPStan niveau 10 (`phpstan.neon.dist`), Pint (`pint.json`), Deptrac
  (`deptrac-modules.yaml` + `deptrac-layers.yaml`) doivent tous rester verts.

### Où et comment

- Code dans `packages/support/src/Integration/` — pas un nouveau module,
  `core/support` existe déjà (composer.json, service provider).
- Suis le style déjà en place dans `packages/support/src/` (`Clock.php`,
  `Identifier.php`, `Http/ProblemDetails.php`) : interfaces + implémentations
  concrètes, `final readonly class` pour les VO, docblocks qui expliquent le
  *pourquoi* d'un choix non évident, pas ce que le code fait déjà de façon
  lisible.
- Toute commande passe par `make` / Docker (voir `CLAUDE.md`) : `make stan`,
  `make arch`, `make test`, `make ci`, `make guard-test`.
- Avant de finir : `make ci` et `make guard-test` doivent être verts — le
  second injecte une vraie violation de chaque garde-fou actif et vérifie le
  rejet ; si tu ajoutes un composant qui **devrait** être protégé par une
  règle qui n'existe pas encore, dis-le au lieu de le construire sans
  garde-fou.

### En terminant

- Mets à jour `packages/support/README.md` (ligne du socle : « en cours » →
  « Livré », avec le chemin exact des fichiers).
- Mets à jour `CLAUDE.md`, section « Ce qui n'est PAS encore construit ».
- Si tu prends une décision structurante non couverte par les ADR existants
  (ex. le choix exact de la stratégie de circuit breaker, le format du VO
  `ProviderConfig`), trace-la en ADR (gabarit `docs/adr/0000-modele.md`,
  prochain numéro disponible après 0031) plutôt que de la laisser implicite
  dans le code.
- Ne touche à aucun module métier (`ledger`, `wallet`, `payment`,
  `notification`…) — ce socle est autonome par construction, c'est tout
  l'intérêt de l'avoir avancé.
