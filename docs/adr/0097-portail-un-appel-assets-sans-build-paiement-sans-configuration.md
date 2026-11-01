# ADR-0097 — Le portail se branche en un appel : assets sans build, paiement sans configuration, événements de commerce abonnables

- **Statut** : Accepté
- **Date** : 2026-10-28
- **Décideurs** : commanditaire (cadrage de livraison), réalise ADR-0075 et ADR-0084
- **Dérives concernées** : D1 périmètre · D2 couplage · D5 contrat

## Contexte

ADR-0075 décrit le portail client hébergé ; ADR-0084 décrit la séquence de
branchement d'un SaaS (client, abonnement, paiement, événements). Mise bout à
bout, la séquence demandait à un projet quatre appels, la configuration des
moyens de paiement et une lecture attentive de six ADR. L'objectif de livraison
est qu'un SaaS déjà codé se branche en **environ trente minutes**, sans écrire
de logique de commerce. Trois décisions de conception en découlent.

## Décision

### 1. Un seul appel ouvre le portail : `POST /v1/portal/sessions`

Le corps accepte, en plus de `customer_ref` et `return_url` (ADR-0075 §1) :

- `customer` — déclare ou rafraîchit le client (upsert sur sa référence). Un
  champ omis **conserve** sa valeur : un appel partiel ne peut pas effacer un
  courriel déclaré plus tôt. Exige la portée `customers:write`.
- `plan_code` (+ `trial_days`) — abonne le client **seulement s'il n'a aucun
  contrat vivant sur ce plan**. L'appel est donc sûr à chaque connexion. Exige
  `subscriptions:write`.

Une clé qui n'a que `portal:write` ne peut donc ni écrire un client ni signer
un contrat « par la porte de côté » : le rayon d'explosion d'une clé de portail
reste celui d'ADR-0075 §1.

### 2. Le paiement n'est pas une configuration du projet

Le portail appelle `PayInvoice` (ADR-0073), qui passe par l'endpoint de paiement
de la plateforme. Un projet ne configure **aucun** prestataire ni canal : le
portail propose les canaux que `PaymentProviderRouter` sait résoudre pour ce
projet (`mtn_momo`, `orange_money`, `card`), et **n'en propose jamais un que
personne ne sert**. `portal_settings.payment_channels` ne sert qu'à
**restreindre** l'offre. Un bouton qui ne peut qu'échouer est pire que pas de
bouton.

### 3. Les assets du portail n'ont pas d'étape de build

La politique CSP du portail (ADR-0075 §Sécurité) est `default-src 'none';
style-src 'self'` : ni script tiers, ni style en ligne. Le CDN Tailwind de la
console est donc inutilisable. Le portail sert **une feuille de style écrite à
la main** (`public/assets/portal/portal.css`, jetons de design en variables
CSS, mobile-first, mode sombre, focus visibles) et une feuille de thème
générée par session (`/portal/theme.css`, une seule variable `--brand`). Aucun
JavaScript ; les jauges utilisent l'élément natif `<progress>`. Cela évite
d'introduire Node dans la chaîne de livraison du Core.

### 4. Les événements de commerce sont abonnables

`WebhookEventTypes::ALL` ne contenait que paiements, notifications et wallets :
un SaaS ne pouvait pas s'abonner à `subscription.*`, alors qu'ADR-0084 §3 en
fait le déclencheur de l'accès. Sont ajoutés `subscription.*`, `invoice.issued|
paid|payment_failed|voided`, `customer.suspended|reactivated` et
`usage.threshold_reached|limit_reached`. Chaque charge utile porte déjà
`version` (déduplication et ordre côté consommateur).

### 5. Le bouton « Payer » et le garde-fou G7

Le garde-fou G7 exige l'en-tête `Idempotency-Key` sur toute route qui bouge
de l'argent ; un formulaire de navigateur ne peut pas l'envoyer. Le plan
« navigateur du portail » (routes protégées par `AuthenticatePortal`) est donc
déclaré dans `IdempotencyTest`, **comme l'est déjà la console web**, et non
désactivé : l'idempotence est déplacée côté serveur. La clé transmise à
`PayInvoice` est dérivée (HMAC) de la session, de la facture et d'un **nonce
à usage de formulaire** : un double clic est un seul paiement. L'exemption ne
s'applique qu'aux routes portant ce middleware ; toute autre route monétaire
reste soumise au garde-fou.

### 6. Un kit de branchement côté SaaS

`docs/integration/kit-laravel/` fournit le code à copier : client, middleware de
droits (fail-closed, cache 60 s), récepteur de webhooks (signature,
déduplication, version par ressource) et bouton « Gérer mon abonnement ». Le
seul code métier laissé au projet est `TenantAccess::apply()`.

## Alternatives écartées

- **Configurer les canaux de paiement par projet.** Rejeté : c'est exactement
  la charge que le Core doit retirer à un projet.
- **Tailwind compilé pour le portail.** Rejeté : impose Node et un artefact de
  build pour une feuille de style de deux cents lignes.
- **Deux appels (client puis session).** Rejeté : chaque appel ajouté est une
  tâche de synchronisation de plus pour le projet.

## Conséquences

- Les ADR-0075 et ADR-0084 sont réalisés ; ils ne sont pas modifiés.
- Un canal de paiement ajouté à la plateforme apparaît dans le portail de tous
  les projets qui n'ont pas restreint l'offre.
- Le guide d'intégration devient `docs/integration/kit-laravel/INSTALL.md`.

## Vérification

- Test : une clé `portal:write` seule qui passe `customer` ou `plan_code` → 403.
- Test : un appel répété avec `plan_code` ne crée pas de second abonnement.
- Test : un `return_url` hors liste (y compris `https://projet.example.com.evil.io`) → 422.
- Test : un canal non routable n'est pas proposé.
- Test : un lien consommé deux fois → la seconde tentative échoue ; un lien
  expiré échoue.
- Test : la facture d'un autre client → 404.
