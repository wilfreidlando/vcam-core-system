# ADR-0069 — Organisations, projets et accès console scopé par organisation

- **Statut** : Proposé
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (à valider)
- **Dérives concernées** : D2 couplage · D3 données/PII · D7 organisationnelle
- **Amende** : ADR-0044 (rôles opérateur), ADR-0050 / ADR-0052 (gestion des opérateurs, création d'organisations et de projets)

## Contexte

Le modèle d'identité livré a deux plans :

- **machine** : `Organization` → `Project` → `ApiKey` ; une requête `/v1` est
  résolue en `ProjectContext` (ADR-0014) ;
- **humain** : `Operator` avec un `OperatorRole` fixe (`platform_admin`,
  `finance_operator`, `support_operator`, `provider_manager`,
  `read_only_auditor`), Sanctum et MFA TOTP obligatoire (ADR-0044).

Un opérateur voit **tous** les projets : le modèle suppose que seul le
personnel de la plateforme se connecte à la console. Dès qu'une organisation
tierce possède un projet — ou qu'une équipe interne ne doit voir que ses
propres projets — ce modèle fuit. Les projets ont déjà un `status`
(`active` par défaut) mais aucun cycle de vie n'est défini.

## Décision

### 1. Deux portées d'autorisation, une seule identité de connexion

Un **compte console** (`operators`, renommé en vocabulaire « compte console »
dans la documentation, la table ne change pas de nom) peut porter :

- un **rôle de plateforme** (l'`OperatorRole` existant, optionnel) : portée
  **toutes organisations** ;
- zéro ou plusieurs **appartenances d'organisation** :

```sql
CREATE TABLE organization_memberships (
  id              uuid PRIMARY KEY,
  organization_id uuid NOT NULL,
  operator_id     uuid NOT NULL,
  role            text NOT NULL,
  created_at      timestamptz NOT NULL DEFAULT now(),
  revoked_at      timestamptz,
  CONSTRAINT organization_memberships_role_check
    CHECK (role IN ('org_owner', 'org_admin', 'org_finance', 'org_support', 'org_viewer'))
);
CREATE UNIQUE INDEX organization_memberships_active_unique
  ON organization_memberships (organization_id, operator_id) WHERE revoked_at IS NULL;
```

| Rôle d'organisation | Peut |
|---|---|
| `org_owner` | tout ce que peut `org_admin` + gérer les membres et les rôles, archiver un projet |
| `org_admin` | gérer projets, clés d'API, webhooks, applications provider du projet, sessions de canal, catalogue, réglages du portail |
| `org_finance` | lire et agir sur clients, abonnements, factures, encaissements hors ligne, grand livre, exceptions de paiement |
| `org_support` | lire clients, abonnements, factures, notifications ; actions de support (prolonger, suspendre) avec motif |
| `org_viewer` | lecture seule de tout le périmètre de l'organisation |

Les permissions restent un **enum fixe** côté code (`OrganizationRole`), comme
`OperatorRole` (YAGNI, ADR-0044) — pas de schéma dynamique de permissions.

### 2. La portée d'accès est un paramètre obligatoire, jamais un filtre optionnel

Le middleware `operator.auth` produit un `AccessScope` :

```php
final class AccessScope {
    public function __construct(
        public bool $allOrganizations,          // vrai seulement pour un rôle de plateforme
        public array $organizationIds,          // sinon : les organisations du compte
        public array $permissionsByOrganization // org_id => list<permission>
    ) {}
}
```

- **Tout port de listing ou de lecture** utilisé par la console reçoit
  l'`AccessScope` en **paramètre requis** (pas de valeur par défaut). Un port qui
  ne le prend pas ne peut pas être appelé depuis `admin` : c'est une règle
  PHPStan (`AdminQueriesAreScoped`).
- Une ressource hors portée renvoie **404** (on ne confirme pas l'existence
  d'une ressource étrangère), jamais 403.
- Les actions **réservées à la plateforme** restent sous rôle de plateforme :
  gestion des comptes console de la plateforme, sessions de canal **globales**,
  Horizon, réglages globaux.

### 3. Cycle de vie d'un projet

| Statut | Effet |
|---|---|
| `active` | fonctionnement normal |
| `suspended` | toute requête `/v1` du projet → `403 project-suspended` ; le portail client affiche une page d'indisponibilité ; les jobs planifiés du projet sont mis en pause ; les webhooks entrants des prestataires **continuent** d'être traités (l'argent ne s'arrête pas en vol) |
| `archived` | état final : clés révoquées, endpoints de webhook désactivés, données conservées selon ADR-0083, export disponible (ADR-0088) |

Transitions : `active ⇄ suspended`, `active|suspended → archived`. Chaque
transition est auditée avec un motif. Contrainte `CHECK` ajoutée sur
`projects.status` et `organizations.status`.

### 4. Création d'une organisation et invitation

- Une organisation est créée par un rôle de plateforme (la création
  libre-service n'est pas ouverte, voir ADR-0087).
- Le premier `org_owner` est **invité** : un jeton d'activation à usage unique
  (32 octets aléatoires, haché, valable 72 h) lui permet de choisir son mot de
  passe et d'enrôler son MFA. Aucun mot de passe n'est choisi par le système ni
  transmis (ADR-0081, S-07).
- Un `org_owner` invite ensuite ses membres par le même mécanisme.

### 5. Le MFA est obligatoire pour tout compte console

Qu'il ait un rôle de plateforme ou seulement une appartenance. Les règles
d'ADR-0044/0046 (anti-rejeu TOTP, throttle, expiration des jetons) s'appliquent
sans exception.

## Sécurité

- **IDOR inter-organisations** : chaque route console à identifiant reçoit un
  test généré « compte de l'organisation A sur une ressource de B → 404 »
  (garde-fou G15, ADR-0081).
- **Élévation de privilège** : un `org_admin` ne peut pas s'attribuer
  `org_owner` ; un `org_owner` ne peut pas attribuer de rôle de plateforme. Les
  changements de rôle sont audités.
- **Dernier propriétaire** : la révocation du dernier `org_owner` actif est
  refusée (`409 last-owner`).

## Alternatives écartées

- **Une console séparée pour les organisations tierces.** Rejeté : deux
  consoles à maintenir pour les mêmes objets ; la portée suffit.
- **Un `organization_id` sur `operators` (un compte = une organisation).**
  Rejeté : un consultant ou un membre du personnel peut servir plusieurs
  organisations.
- **Filtrer dans les contrôleurs.** Rejeté : un oubli = une fuite. Le paramètre
  requis rend l'oubli impossible à compiler.
- **Permissions dynamiques en base.** Rejeté : surface de configuration et
  d'erreur disproportionnée pour cinq rôles stables.

## Conséquences

- `identity` gagne `organization_memberships`, `OrganizationRole`, `AccessScope`
  et les jetons d'invitation.
- Tous les ports `List*` / `Get*` utilisés par `admin` gagnent un paramètre
  `AccessScope` ; les ports existants (ADR-0045, ADR-0052) sont migrés.
- Les comptes console existants gardent leur rôle de plateforme ; aucun accès
  n'est réduit.
- Ouvre la gestion par des organisations tierces de leurs propres projets sans
  aucune donnée visible d'un autre locataire.

## Vérification

- Test d'architecture : aucun port appelé par `admin` sans `AccessScope`.
- Tests IDOR générés (G15) sur toutes les routes console à identifiant.
- Test : projet `suspended` → `403 project-suspended` sur `/v1`, webhooks
  prestataires toujours traités.
