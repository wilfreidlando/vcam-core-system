# ADR-0026 — Principes d'intégration des providers externes

- **Statut** : Accepté — principes maintenant ; construction au Lot 3 à partir de MTN et Orange
- **Date** : 2026-09-14
- **Dérives concernées** : D3, D4

## Contexte

Un second avis d'architecte
([`guides/architecture-integration-externe.md`](../architecture/guides/architecture-integration-externe.md))
propose un « mini-framework d'intégration » complet — configuration
déclarative par provider, appelant HTTP central, templates de requête/réponse,
catalogue d'endpoints, quatre familles de mappers, bus de saga, journal
`AdapterLog` persistant, commandes `core:adapter:*` — à livrer entièrement au
Lot 0, avant tout provider réel.

## Décision

**Retenu maintenant** (principes, garde-fou actif, pas de code de capacité) :

- Anti-corruption layer : aucune app ne parle jamais directement à un provider ;
  aucun vocabulaire provider ne franchit son adaptateur ([ADR-0007](0007-ports-et-adaptateurs-providers.md)).
- Un seul point d'appel HTTP sortant pour tout le Core —
  `Core\Support\Integration` — qui portera timeouts, retries bornés,
  rédaction des secrets dans les logs, propagation du `correlation_id` et
  respect de l'`Idempotency-Key`. Garde-fou **G6c** actif dès maintenant :
  aucune classe hors de ce namespace ne peut ouvrir de connexion HTTP
  (`Http`, Guzzle, `Psr\Http\Client`) — vérifié par
  `tools/PhpStan/Architecture/OutboundHttpIsCentralised.php`.
- Un registre de providers (`ProviderRegistry::gatewayFor($code)`) plutôt que
  des `match`/`switch` dans le code applicatif.
- Un kill switch par provider (`enabled` en configuration), sans redéploiement.
- Un pipeline unique pour les callbacks entrants : vérification de signature
  d'abord, déduplication par identifiant fourni par le provider, puis mapping
  vers un événement canonique.
- Tests de contrat par adaptateur (`Http::fake()`, vérification de la forme
  exacte de l'appel).

**Construit au Lot 3**, pas au Lot 0, et à partir des deux providers réels du
MVP (MTN MoMo, Orange Money) — pas conçu par anticipation :

- L'appelant HTTP central lui-même (`Core\Support\Integration\ExternalApiCaller`).
- Tout système de templates déclaratifs de requête/réponse et le catalogue
  d'endpoints. Un DSL conçu avant d'avoir vu deux formats d'API réels a de
  fortes chances d'être mal calibré ; deux providers réels donnent la matière
  pour distinguer ce qui est mutualisable de ce qui ne l'est pas.
- Le bus de saga (`ContinueOperation`/`CompensateOperation`) : utile quand un
  premier flux réellement asynchrone existe (paiement mobile money avec
  callback, Lot 3) ; conçu à ce moment-là.
- Les commandes `core:adapter:*`.

**Rejeté**, dérogeant explicitement au guide d'intégration :

- **Le mode `idle` qui renvoie un succès simulé** (`simulated: true`) en dehors
  de la sandbox. Pour un système où l'objectif n°1 est l'intégrité financière
  ([ADR-0005](0005-ledger-double-entree-immuable.md)), faire croire à un produit
  qu'un paiement a réussi alors qu'aucun appel n'a eu lieu est un risque
  disproportionné par rapport au bénéfice (bascule de maintenance sans
  redéploiement). La spécification fonctionnelle §10 exige déjà une
  « dégradation gracieuse » avec « bascule ou rejet propre, jamais de double
  débit » — jamais de faux succès. Un provider en maintenance est **rejeté
  proprement** (503, statut `failed` ou `pending` selon le point d'échec), pas
  simulé. La bascule à chaud entre deux providers réels reste possible via le
  routeur et le kill switch, sans passer par un faux succès.
- **La table générique `support.adapter_logs`.** Elle contredirait
  [ADR-0020](0020-shared-kernel-support.md)/G3d (aucune persistance dans le
  shared kernel) et stockerait des corps de requête bruts (numéros de
  téléphone, jetons) — une extension non maîtrisée de la surface PII (dérive
  D3). Les tentatives d'appel provider sont tracées par le module propriétaire
  qui en a besoin (`Payment`, `Notification`), avec rédaction systématique des
  secrets et sans PII au-delà de ce que la Party autorise déjà.
- **L'exposition des journaux d'appel provider aux applications**
  (`GET /payments/{id}/logs`). Contredit l'invariant n°1 du guide
  lui-même : « une app métier ne sait jamais qu'un provider externe existe ».
- **Les providers hors périmètre MVP** (CinetPay, DGI, Firebase, Twilio,
  SendGrid) : aucun besoin réel aujourd'hui — porte n°1 de la gouvernance.

## Alternatives écartées

- **Framework complet au Lot 0** (la proposition du guide) : fige un DSL de
  templates et un bus de saga avant d'avoir intégré un seul provider réel ;
  sort du périmètre approuvé de la Phase 0 (zéro implémentation métier) et
  retarde le Lot 1 de plusieurs semaines pour un pari architectural non validé.
- **Un adaptateur = un `Http::post()` local, pas de socle commun** : chaque
  intégration réinvente auth/retry/log/rédaction ; un bug corrigé sur MTN
  resterait présent sur Orange. D'où le garde-fou G6c dès maintenant, même sans
  implémentation.

## Conséquences

- Le Lot 3 doit produire, à partir de MTN et Orange, un
  `Core\Support\Integration\ExternalApiCaller` réellement mutualisé — sa
  conception s'appuie sur l'ADR présent mais son détail (templates ou non,
  catalogue ou non) reste ouvert et sera lui-même tracé par ADR au moment de
  l'écrire.
- `docs/architecture/guides/architecture-integration-externe.md` reste comme
  référence de patterns étudiés, annoté du statut de chaque proposition ; en
  cas de divergence, le présent ADR prévaut.
