# ADR-0069 — Organisations, projets et accès console scopé par organisation

- **Statut** : Accepté
- **Date** : 2026-10-24
- **Décideurs** : comité d'architecture (ratifié le 2026-10-27)
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
  PHPStan (`AdminQueriesAreScoped`, garde-fou **G16**).
- Une ressource hors portée renvoie **404** (on ne confirme pas l'existence
  d'une ressource étrangère), jamais 403.
- Les actions **réservées à la plateforme** restent sous rôle de plateforme :
  gestion des comptes console de la plateforme, sessions de canal **globales**,
  Horizon, réglages globaux.
- **Le journal d'audit est scopé comme le reste.** `audit_log` porte
  `organization_id` et `project_id` : une entrée appartient à l'organisation
  du projet qu'elle concerne. Un compte d'organisation lit le journal **de
  son organisation** ; les entrées sans organisation ni projet — les
  événements de plateforme (authentification console, gestion des comptes
  console) — restent lisibles **par un rôle de plateforme seul**. Un journal
  d'audit que tout le monde verrait ne serait pas un journal d'audit.
- Le contrôle de permission est **à deux niveaux** : la console n'ouvre une
  page que si le compte tient la permission **dans au moins une** de ses
  organisations, puis l'action sur une ressource est refusée si le compte ne
  la tient **pas dans l'organisation de cette ressource**. Un compte membre de
  deux organisations de rôles différents ne doit pas appliquer le rôle le
  plus permissif aux deux.

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

## Mise en œuvre (lot 8)

Précisions apportées en réalisant la décision ; elles ne changent pas son
sens.

- **`AccessScope` vit dans le noyau partagé** (`core/support`, sans aucune
  persistance) et non dans `identity` : le module `ledger` ne dépend d'aucun
  autre module (G2) alors que la console appelle ses ports de lecture.
- **Le rôle de plateforme devient facultatif** (`operators.role` nullable) :
  tout rôle de plateforme voit toutes les organisations, ses permissions
  restant celles de son rôle ; un compte sans rôle de plateforme ne voit que
  ses appartenances. Aucun accès existant n'est réduit.
- **La portée est rétrécie par permission.** Voir une organisation n'est pas
  y avoir une permission : `OperatorContext::scopeFor($permission)` ne garde
  que les organisations où le rôle du compte porte la permission, et leurs
  projets. Une page de la console s'ouvre si cette portée n'est pas vide
  (403 sinon, ce qui ne révèle rien) et lit **à travers elle** ; une action
  sur une ressource répond 404 hors de la portée du compte, 403 si la
  ressource est visible mais la permission absente dans son organisation.
- **Les ports d'écriture qui touchent l'argent ou les secrets d'un projet
  portent aussi la portée**, pas seulement les ports de lecture :
  `ResolveMoneyException`, `ReconcilePayment`, `ReconcileStuckPayments`,
  `RegisterProviderApplication`. La vérification se fait dans le port (sous
  le verrou de ligne pour les exceptions d'argent), jamais seulement dans le
  contrôleur. Le planificateur passe explicitement `AccessScope::platform()`.
- **Le rôle de plateforme peut être retiré par la console.** Le formulaire
  d'édition d'un opérateur offre « Aucun rôle de plateforme ». Sans cette
  option, un compte sans rôle de plateforme n'avait rien de sélectionné et
  le navigateur soumettait la première option (`platform_admin`) : modifier
  le nom d'un compte d'organisation lui accordait silencieusement la
  plateforme.
- **L'archivage est atteignable.** `active|suspended → archived` : le
  formulaire n'offre `archived` qu'à un compte portant `projects:archive`
  sur ce projet (le joker d'`org_owner`, et l'administrateur de la
  plateforme) ; la requête qui le porte sans ce droit est refusée (403).
  Sans cela, la transition, la révocation des clés qu'elle déclenche et le
  garde lui-même étaient du code mort.
- **Une boîte aux lettres, un compte.** La comparaison des adresses
  console est insensible à la casse : une invitation adressée à une autre
  casse rejoint le compte existant — après vérification de son mot de passe
  actuel — au lieu d'en créer un second pour la même personne.
- **Le journal d'audit porte `organization_id` et `project_id`.** Chaque
  appelant attribue son entrée. Les entrées historiques de la forme
  `project:<uuid>` ne sont **pas** réécrites — le journal est append-only
  (S-16) et une attribution ne justifie jamais de le réécrire : elles sont
  rattachées à leur projet **au moment de la lecture**, par la forme
  historique de leur `resource`. Une entrée
  sans organisation ni projet est un événement de plateforme, lisible par un
  rôle de plateforme seul.
- **Archiver un projet est réservé au propriétaire de l'organisation** (et à
  l'administrateur de la plateforme) : permission `projects:archive`, que
  seul le joker de `org_owner` accorde. L'archivage révoque toutes les clés
  du projet dans la même transaction (chaque révocation auditée) et le module
  `webhook` ne diffuse plus rien pour un projet archivé ; un projet suspendu
  continue de recevoir ses webhooks. Transitions conformes au §3 :
  `active ⇄ suspended`, `active|suspended → archived` (l'archivage direct
  d'un projet actif est permis).
- **« Les jobs planifiés du projet sont mis en pause »** vise les traitements
  commerciaux à venir (renouvellements, relances — lot 10 et suivants). La
  réconciliation des paiements continue pour un projet suspendu, comme les
  webhooks entrants : l'argent en vol ne s'arrête pas. La page
  d'indisponibilité du portail client viendra avec le portail (lot 13).
- **Garde-fou G16** : la règle PHPStan `AdminQueriesAreScoped` (sans aucune
  exemption) couvre les contrôleurs de la console et les compositeurs de
  vues ; un test d'architecture interdit à tout gabarit Blade d'appeler un
  port, puisque PHPStan ne lit pas les gabarits.

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
