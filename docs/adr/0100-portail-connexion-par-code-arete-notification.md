# ADR-0100 — Connexion directe du portail par code : l'arête `portal → notification` et les choix de réalisation

- **Statut** : Accepté — réalisation d'ADR-0075 §2, ratifiée après livraison du code
- **Date** : 2026-10-29
- **Décideurs** : commanditaire (cadrage de livraison) ; comité d'architecture (ratification)
- **Dérives concernées** : D1 périmètre · D3 données/PII · D5 contrat
- **Amende** : ADR-0075 §2, ADR-0068 (graphe de modules)

## Contexte

ADR-0075 §2 décide une entrée secondaire du portail, désactivée par défaut : le client saisit son
contact, le Core envoie un code à 6 chiffres par le canal du projet, le portail ouvre une session.
Elle n'était pas livrée. L'envoyer exige que `portal` appelle `notification`, ce que le graphe de
modules (ADR-0068) ne permet pas : le graphe de `portal` ne comptait que `support`, `identity`,
`billing`, `payment` et `audit` (le tableau d'ADR-0075 en listait pourtant `notification`).

## Décision

### 1. Nouvelle arête : `portal → notification`

`portal` envoie le code par `Core\Notification\Contracts\SendTextNotification`, comme n'importe quel
appelant, sur le canal `whatsapp` et depuis la **session de canal du projet**
(`portal_settings.direct_login_session`). Pas de cycle : `notification → billing`, `billing` ne
connaît pas `portal`. Mis à jour ensemble : `tests/Architecture/ModuleGraph.php`,
`deptrac-modules.yaml`, `packages/portal/composer.json`. Aucune autre arête.

### 2. Réalisation d'ADR-0075 §2

- **Réglages** : `direct_login_enabled` (faux par défaut) et `direct_login_session`. Activer sans
  session est refusé par le domaine ; sans les deux, la page n'existe pas (`404`).
- **Pages** : `GET /portal/{projectId}/login` (téléphone), `POST` (envoi), `POST .../login/verify`
  (code). CSP stricte, sans JavaScript, même mise en page que le portail aux couleurs du projet.
- **Code** : 6 chiffres tirés du CSPRNG, stocké sous la seule forme d'un **HMAC à clé**, lié au projet
  et au téléphone ; valable 10 minutes ; 5 essais (l'essai est décompté par un `UPDATE` conditionnel
  *avant* la comparaison) ; usage unique (`UPDATE` conditionnel) ; un nouveau code retire les
  précédents ; renvoi au plus toutes les 60 s. Le téléphone n'est jamais stocké par le portail : la
  ligne se retrouve par son HMAC. Table `portal_login_codes`.
- **Pas d'énumération** : la réponse à la demande de code ne dépend ni de l'existence du contact ni
  de la cadence de renvoi. La recherche du client et l'envoi se font **après** l'envoi de la réponse,
  avec le même travail (recherche, code, HMAC) pour un inconnu et un client. Un contact inconnu ou
  partagé par plusieurs clients du projet n'envoie rien.
- **Recherche du client** : nouveau contrat de `billing`, `FindCustomerByContact` (projet, `phone` ou
  `email`, valeur), par l'index aveugle (ADR-0083) ; jamais un client effacé ; un contact porté par
  deux clients du projet est **ambigu et ne trouve personne** (le téléphone n'est pas unique : un code
  envoyé à un numéro partagé ne doit pas ouvrir le compte d'un autre). Le formulaire ne demande que le
  téléphone (WhatsApp est le seul canal routable, ADR-0099 §4).
- **Limites** (ADR-0085 §1), horaires, compteurs du cache, **fermées en cas de panne** : 5 codes par
  téléphone, 30 par adresse IP, plafond d'envois par projet
  (`portal.direct_login.project_hourly_cap`, 200 par défaut), 60 vérifications par adresse IP. Si le
  compteur est illisible : `503`, rien n'est envoyé. Le plafond du projet, atteint, est silencieux pour
  le navigateur (sinon il révélerait qu'un envoi allait avoir lieu) et journalisé en `warning`.
- **Session** : même échange que le lien à usage unique (`PortalSessionService::openForCustomer`),
  cookie `__Host-portal`, accueil du portail. **Elle n'ouvre que le portail**, jamais l'application du
  projet. L'ouverture est auditée (`portal.session_opened`, `via = direct_login`) ; les journaux ne
  portent ni le téléphone ni le code.
- **Facturation du message** : le code est un message du projet, décompté comme tout envoi
  (`variables.customer_ref` = le client qui se connecte) ; un projet dont le quota est épuisé n'envoie
  plus de code, et le journal le dit.

## Alternatives écartées

- **Appeler `notification` par un événement ou une file.** Rejeté : composition implicite entre
  modules (ADR-0037, ADR-0041) ; l'appel direct au contrat est le même que celui des projets.
- **Jeton anti-CSRF sur la page publique.** Rejeté : sans session il ne peut être lié à personne, un
  serveur tiers le lirait puis le rejouerait ; ce qui protège ces portes est le code (usage unique,
  5 essais) et les limites.
- **Réponse « trop de demandes » dépendante du client.** Rejeté : toute différence observable est une
  énumération.

## Conséquences

- Le contenu d'une notification est conservé par `notification` 90 jours puis vidé
  (`notification.content_retention_days`) : le texte du code y figure en clair pendant ce délai alors
  que le code est mort au bout de 10 minutes. Écart connu de « seul le HMAC est stocké » côté
  `portal` ; à lever si `notification` gagne un envoi sans conservation du contenu.
- `billing` gagne un contrat (`FindCustomerByContact`), sans arête nouvelle.
- La carte des capacités passe l'entrée secondaire du portail à ●.

## Vérification

- `PortalDirectLoginTest` : désactivée → `404` ; réponse identique pour un numéro connu et inconnu, rien
  envoyé à l'inconnu ; bon code → cookie et accueil ; code rejoué, expiré, après 5 essais → refusé ;
  renvoi avant 60 s → réponse neutre, un seul message ; plafonds par téléphone, par IP et par projet ;
  compteur en panne → `503` sans envoi ; client d'un autre projet ou effacé → rien.
- `ComposerGraphTest` et Deptrac : le graphe, `composer.json` et le code concordent.
