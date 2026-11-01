# ADR-0087 — Catalogue de capacités, démarrage par capacité, horloges de test

- **Statut** : Accepté — amendé par ADR-0096 (`developer-platform` reste un scaffold jusqu'au lot 16 : le registre, la route et la page sont livrés par le lot 16 lui-même)
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
- **Dérives concernées** : D5 contrat · D7 organisationnelle
- **Amende** : ADR-0048 (`developer-platform` entre en production en version minimale), ADR-0066 (horloges de test sur le staging)

## Contexte

Un projet qui arrive doit savoir **ce qui existe** avant de reconstruire. Le
core a un guide `curl` complet et un OpenAPI publié, mais pas de catalogue : la
découverte passe par la lecture des ADR. La gouvernance (§10, §15) fait du
« taux d'accès au catalogue » un indicateur de dérive — qui n'est pas mesurable
sans catalogue.

Par ailleurs, tester un cycle d'abonnement (essai → renouvellement → impayé →
expiration) demande aujourd'hui d'attendre des semaines ou de manipuler des
dates en base. Le port `Core\Support\Clock` existe déjà, avec des horloges
fixes en test.

## Décision

### 1. Un registre de capacités dérivé, jamais recopié

`developer-platform` tient un registre machine :

```yaml
- key: billing.subscriptions
  name: Abonnements
  status: stable          # preview | stable | deprecated
  scopes: [subscriptions:read, subscriptions:write]
  openapi_tags: [Subscriptions]
  events: [subscription.activated, subscription.past_due, subscription.expired]
  guide: docs/integration/capacites/abonnements.md
  does_not: [prorata, prélèvement automatique]
```

- Route de lecture `GET /v1/capabilities` (sans portée particulière) et page
  dans la console.
- Un test compare le registre aux tags de l'OpenAPI : une capacité exposée et
  non listée — ou listée et absente — fait échouer la CI.

### 2. Une page de démarrage par capacité

Clé et portées nécessaires, première requête, réponse **réelle**, événements à
écouter, erreurs fréquentes, ce que la capacité ne fait pas. Les exemples
`curl` sont **exécutés par la suite de tests** contre une instance réelle,
comme ceux du guide actuel : un exemple faux casse le build.

### 3. Horloges de test, staging uniquement

- Activées seulement si `TEST_CLOCKS_ENABLED=true` (jamais en production ; la
  configuration de production l'interdit et un test le vérifie).
- Un projet de test peut avancer **son** horloge :
  `POST /v1/test-clock/advance {to: "2026-12-01T00:00:00Z"}` (portée
  `subscriptions:write`, projet marqué `test_mode`).
- Le planificateur de `billing` résout l'horloge **par projet** via le port
  `Clock` : avancer l'horloge d'un projet déroule ses renouvellements, fins
  d'essai, délais de grâce — sans toucher aux autres projets.
- Combinée au simulateur de paiement existant
  (`POST /v1/payments/{id}/simulate`), elle permet une recette complète d'un
  cycle d'abonnement en quelques minutes.

### 4. Cycle de vie d'un projet, outillé

Création (organisation + projet) → clés à portée → webhook enregistré et testé
→ application prestataire → plans publiés → portail configuré → mise en
service → rotation → suspension → archivage et export. Chaque étape a sa page
et apparaît dans la liste de contrôle d'onboarding (ADR-0080 §4).

### 5. Non ouvert en v1

Inscription libre-service d'une organisation, SDK clients, espace
communautaire. Le registre et les pages en sont la base ; leur ouverture se
décidera sur demande démontrée.

## Alternatives écartées

- **Catalogue rédigé à la main.** Rejeté : il diverge à la première capacité
  ajoutée.
- **Un environnement « sandbox » complet séparé.** Rejeté (ADR-0066) : coût
  permanent ; le staging + simulateur + horloges de test couvrent le besoin.
- **Manipuler les dates en base pour tester.** Rejeté : non reproductible, et
  incapable de tester le planificateur réel.

## Conséquences

- `developer-platform` sort du scaffold (registre, route, page).
- `billing` lit l'heure **exclusivement** par `Clock` (règle PHPStan : aucun
  `now()`/`Carbon::now()` dans `billing`).
- Le guide d'intégration est organisé par capacité.

## Vérification

- Test registre ↔ OpenAPI.
- Test : `TEST_CLOCKS_ENABLED` absent → route `404`.
- Recette sur horloge de test : essai 14 jours → facture → impayé → grâce →
  expiration, en une exécution.
