# ADR-0031 — Le socle d'intégration générique est avancé, en parallèle du Lot 2

- **Statut** : Accepté — amende [ADR-0026](0026-integration-providers-externes.md) sur le séquencement uniquement
- **Date** : 2026-09-15
- **Dérives concernées** : D1, D4

## Contexte

[ADR-0026](0026-integration-providers-externes.md) reportait la construction
complète du « mini-framework d'intégration » (proposé par le second avis
d'architecte) au Lot 3, au motif qu'il fallait avoir vu deux providers réels
(MTN, Orange) pour calibrer correctement ses abstractions.

Cette décision mélangeait deux choses de nature différente :

1. **Le socle générique** — `ExternalApiCaller`, les stratégies d'authentification
   (Bearer/Basic/ApiKey), les politiques de retry/timeout/circuit breaker, la
   signature de requête (HMAC…), le kill switch, la rédaction des secrets dans
   les logs, la vérification de signature des webhooks entrants. Rien de tout
   cela ne connaît un provider particulier, et rien ne touche à l'argent —
   c'est une infrastructure pure, au même niveau que `core/support` lui-même.
2. **Le DSL déclaratif** (templates de requête/réponse, catalogue
   d'endpoints, mappers spécifiques) — celui-là a réellement besoin de deux
   formats d'API concrets pour être bien calibré.

Reporter (1) en même temps que (2) n'avait pas de justification technique :
(1) ne dépend d'aucun module métier (ni `ledger`, ni `wallet`, ni `payment`),
peut être développé et testé isolément (mock HTTP), et n'a pas besoin d'un
provider réel pour être correct.

## Décision

- Le socle générique (1) est construit **maintenant, en parallèle du Lot 2**
  (Money Core), pas après. Il vit dans `Core\Support\Integration` — cohérent
  avec ce qui était déjà anticipé dans `packages/support/README.md`
  (« Appelant HTTP sortant d'intégration ») et avec le graphe de modules :
  `support` ne dépend de rien et rien ne dépend de lui obligatoirement, donc
  cette avancée ne crée aucune dépendance vers `ledger`/`wallet`.
- Aucune persistance dans ce socle (cohérent avec **G3d** :
  `core/support` ne persiste rien). Le suivi des appels provider se fait par
  logs structurés corrélés (`Core\Support\Http\Middleware\CorrelationId`),
  pas par une table `adapter_logs` — ce point d'ADR-0026 reste inchangé et
  s'applique aussi ici.
- Le garde-fou **G6c** (seul `Core\Support\Integration` ouvre une connexion
  HTTP sortante) était déjà actif depuis la Phase 0 ; il protège ce socle dès
  sa création, pas seulement à partir du Lot 3.
- Le DSL déclaratif (2) — templates, catalogue d'endpoints, mappers, saga —
  **reste différé au Lot 3**, construit à partir de MTN et Orange réels,
  inchangé par rapport à l'ADR-0026.
- Les adaptateurs concrets (`MtnMomoAdapter`, `OrangeMoneyAdapter`) et
  l'orchestration de paiement (écriture au ledger, distribution des frais)
  **restent au Lot 3, après le Lot 2**. Un paiement confirmé par un provider
  n'a de sens que s'il peut s'écrire quelque part financièrement
  (spécification technique §10, étape 7) ; l'orchestration ne peut donc pas
  être terminée avant que le ledger n'existe. Ce n'est pas un simple choix de
  planning : c'est une dépendance fonctionnelle réelle.

## Alternatives écartées

- **Tout avancer, y compris les adaptateurs MTN/Orange, avant le Lot 2** :
  produirait des adaptateurs qui parlent au provider mais ne peuvent rien
  enregistrer financièrement — une capacité à moitié terminée, qui devrait de
  toute façon être retouchée une fois le ledger disponible. Le seul bénéfice
  réel (dé-risquer la stabilité du sandbox provider tôt) peut s'obtenir
  autrement : un test de contrat contre le socle générique, sans attendre le
  Lot 2 ni construire l'orchestration complète.
- **Ne rien avancer, garder ADR-0026 tel quel** : laisse un module entier
  (`core/support/src/Integration`) sans propriétaire ni calendrier explicite
  alors qu'une équipe de 3-4 développeurs (plan de delivery §1.1) peut
  parfaitement paralléliser ce travail avec le Lot 2, puisque les deux ne se
  bloquent pas mutuellement dans le graphe de dépendances.

## Conséquences

- Le rôle « Dev Payment/Providers » (plan de delivery §3.1) peut démarrer ce
  socle dès maintenant, en parallèle du « Dev Money » sur le Lot 2 — sans
  attendre la fin de celui-ci.
- `packages/support/README.md` : l'entrée « Appelant HTTP sortant
  d'intégration » passe de « Lot 3 » à « en cours, parallèle au Lot 2 ».
- `CLAUDE.md` (section « Ce qui n'est pas encore construit ») à mettre à jour
  en conséquence une fois le socle livré.
