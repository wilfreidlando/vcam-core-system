# 02 — Organisations et projets

*Ce chapitre est la référence technique des organisations, des projets et des rôles. Pour les droits et les invitations, la procédure est dans le [parcours F03](../parcours/README.md).*

Trois objets structurent le Core : l'**organisation**, le **projet**, et l'**opérateur**.
Ce chapitre dit qui est qui, et ce que vous pouvez faire de chaque côté.

---

## 1. Le vocabulaire

| Objet | Ce que c'est | Exemple |
|---|---|---|
| **Organisation** | l'entreprise ou le groupe qui achète le Core. Elle a un nom, un statut, des membres. | « Vcam Holding » |
| **Projet** | l'espace d'un produit dans cette organisation. Il porte ses clés d'API, ses clients, ses factures, son grand livre. | « Boutique Orange CI » |
| **Opérateur** | une personne. Plateforme (tous les projets) ou membre d'une organisation (ses projets). | vous |

**Un projet appartient à une organisation. Un opérateur appartient à la plateforme et, s'il
le souhaite, à des organisations.** L'appartenance à une organisation donne un rôle
(`org_owner`, `org_admin`, `org_finance`, `org_support`, `org_viewer`) et donc une
**portée** : la liste des projets que cet opérateur voit.

C'est la portée qui fait qu'une ressource inaccessible n'est pas « interdite » mais
**« introuvable »**. Voir le chapitre 01.

---

## 2. Les organisations

`GET /admin/organizations`

| Colonne | Ce qu'elle contient |
|---|---|
| `ID` | l'identifiant, en UUID complet |
| `Nom` | le nom saisi |
| `Statut` | `Active` ou `Suspendue` |
| `Actions` | voir ci-dessous |

**Actions de la ligne** : `« Voir les projets → »`, `« Membres »` (si vous avez la
permission), `« Modifier »` et `« + Nouveau projet »` (si vous avez `projects:write`).

Un champ `« Rechercher par nom… »` filtre la liste. Pagination par `page` / `per_page`.

### Créer une organisation

`GET /admin/organizations/create` → un seul champ, `« Nom »` → `« Créer l'organisation »`.

**Réservé à la plateforme.** Un membre d'organisation ne peut pas créer d'organisation : il
n'a pas `projects:write` au niveau plateforme. Le message est explicite :
`« La permission projects:write est requise. »`

### Modifier une organisation

`GET /admin/organizations/{organizationId}/edit` :

- `« Nom »` (obligatoire),
- `« Statut »` : `Active` ou `Suspendue`. **`Archivée` n'est pas proposé ici** — l'archivage
  concerne les projets.

> **Suspendre une organisation ne coupe pas ses projets.** C'est une suspension
> d'organisation, pas une mise en service : vérifiez l'effet attendu avant de cliquer.

---

## 3. Les membres d'une organisation

`GET /admin/organizations/{organizationId}/members`

| Colonne | Ce qu'elle contient |
|---|---|
| `Email` | l'adresse de la personne |
| `Nom` | son nom |
| `Rôle` | le rôle `org_*` |
| `Arrivé le` | date d'adhésion |

Si vous avez `members:write` dans cette organisation, chaque ligne porte en plus un
**sélecteur de rôle** et un bouton `« Appliquer »` (le changement ne part pas tout seul),
plus un bouton `« Révoquer »` en confirmation.

Inviter un membre : `GET .../members/invite` → `email` + `role` → `« Envoyer
l'invitation »`. Le jeton d'activation s'affiche une seule fois.

Voir le chapitre 01 pour les règles de rôle et le refus du « dernier propriétaire ».

---

## 4. Les projets

`GET /admin/projects`

**Filtres** : `« Rechercher par nom »` et `« ID de l'organisation »` (champ libre, format
UUID). Boutons `« Filtrer »` / `« Réinitialiser »`.

| Colonne | Ce qu'elle contient |
|---|---|
| `ID` | les 8 premiers caractères de l'UUID, suffixe `…` |
| `Organisation` | le nom résolu |
| `Nom` | le nom du projet |
| `Statut` | `Actif`, `Suspendu`, `Archivé` |
| `Actions` | `« Voir → »`, `« Modifier »` (si `projects:write`) |

> L'identifiant de projet est un UUID aléatoire : il n'est pas devinable. L'UUID court
> affiché ici (8 caractères + `…`) suffit à désigner un projet dans une conversation avec
> la plateforme, qui retrouvera le projet complet à partir de l'identifiant complet.

### Créer un projet

`GET /admin/projects/create`

1. Choisissez l'**organisation** dans la liste déroulante (les 100 premières sont
   proposées ; si votre organisation n'y est pas, affinez la recherche sur l'écran
   précédent).
2. Saisissez le `« Nom »`.
3. `« Créer le projet »`.

**Au moment de la création, le Core génère une clé d'API pour le projet.** L'écran
`« Projet créé — sa clé API a été générée. »` affiche la clé **en clair, une seule
fois**. Copiez-la, ou laissez la clé vierge et créez-en une depuis l'écran `« Clés API »`
(chapitre 03) avec une portée choisie.

Si aucune organisation n'existe encore, l'écran affiche : `« Aucune organisation n'existe
encore — créez-en une d'abord. »`

### La fiche d'un projet

`GET /admin/projects/{projectId}`. Elle affiche l'identifiant, l'organisation, le nom, le
statut, et **17 liens** vers les écrans du projet. Chaque lien n'apparaît que si vous avez
la permission correspondante :

| Lien | Permission | Chapitre |
|---|---|---|
| Paiements de ce projet | aucune (toujours) | 05 |
| Wallets de ce projet | aucune (toujours) | 06 |
| Notifications de ce projet | `notifications:read` | 06 |
| Enregistrer une application provider de paiement | `provider-applications:write` | 03 |
| Enregistrer un endpoint webhook | `webhook-endpoints:write` | 03 |
| Sessions WhatsApp | `channel-sessions:write` | 03 |
| Clés API | `api-keys:write` | 03 |
| Clients de ce projet | `customers:read` | 04 |
| Offres de ce projet | `plans:read` | 04 |
| Abonnements de ce projet | `subscriptions:read` | 04 |
| Factures de ce projet | `invoices:read` | 04 |
| Grand livre et balance | `wallets:read` | 09 |
| Indicateurs et créances | `invoices:write` | 06 |
| Usage et quotas | `usage:read` | 06 |
| Portail client | `portal:write` | 07 |
| Export des données | `exports:write` | 06 |
| Réglages du commerce | `plans:write` | 04 |

**Un lien absent n'est pas un bug** : il signifie que votre rôle n'a pas la permission.
Allez voir le pied de la barre latérale pour connaître votre rôle.

### Modifier un projet : le cycle de vie

`GET /admin/projects/{projectId}/edit` :

- `« Nom »` (obligatoire),
- `« Statut »` : `Actif` / `Suspendu` / `Archivé (définitif — révoque les clés)`,
- `« Motif du changement de statut »` — **obligatoire si le statut change**, 500 caractères
  maximum. Le motif est écrit dans le journal d'audit : c'est ce qui reste de la décision
  quand on la relit dans six mois.

| Statut | Réversible ? | Effet |
|---|---|---|
| `Actif` | oui | le projet fonctionne |
| `Suspendu` | oui, vers `Actif` | les appels du projet sont refusés (`403 project-suspended`) |
| `Archivé` | **non** | les clés sont révoquées ; définitif |

**Archiver est réservé au propriétaire de l'organisation** (ou à la plateforme). Un autre
rôle reçoit : `« Seul le propriétaire de l'organisation peut archiver un projet. »` Le
formulaire ne propose d'ailleurs `Archivé` que si vous êtes en droit de le faire.

---

## 5. Le portefeuille

`GET /admin/portfolio` — la vue transversale : tous les projets d'un coup.

**Permission** : `invoices:write`. Un opérateur support ou un lecteur ne la voit pas, et
ne la verra pas non plus par une URL devinée.

### Les quatre cartes

| Carte | Ce qu'elle mesure | Où la lire |
|---|---|---|
| **Revenu récurrent mensuel** | les contrats actifs et impayés, au mois | par devise |
| **Encaissé** | ce qui est réellement rentré | lu dans le grand livre |
| **Créances** | ce qu'on vous doit | par ancienneté |
| **Clients actifs** | `actifs / total`, avec le nombre d'impayés | par projet |

### Le tableau des projets

`Projet`, `MRR`, `Actifs`, `Essais`, `Impayés`, `Nouveaux / perdus`, `Encaissé`, `Créances`,
`À surveiller`.

**Les projets à surveiller remontent en premier.** Les pastilles sont : `N impayé(s)`,
`N client(s) à la limite`, `créances > 60 jours`, `attrition X,X %`.

### Créances par ancienneté

`Projet`, `Devise`, `Non échues`, `1–30 jours`, `31–60 jours`, `> 60 jours`. La colonne
`> 60 jours` passe en rouge gras quand elle n'est pas vide : c'est votre file de
recouvrement.

### Limite de la liste

Le portefeuille affiche **50 projets au maximum**. Au-delà, un bandeau le dit :
`« Seuls les 50 premiers projets sont affichés : affinez la recherche. »` Utilisez le champ
`« Rechercher un projet »`.

---

## 6. Le tableau de bord

`GET /admin/` — la page d'arrivée.

**Garde** : `audit:read`. Sans cette permission, vous obtenez `403`.

Trois familles de cartes :

| Famille | Cartes |
|---|---|
| Plateforme | Organisations, Projets, Opérateurs |
| Argent | Paiements (24h), Réussites (24h), **Paiements bloqués** |
| Livraison | **Webhooks en échec** |

Le tableau `« Activité récente »` liste les **8 dernières entrées d'audit sur 7 jours** :
`Quand`, `Acteur`, `Action`, `Ressource`.

Trois raccourcis : `« Organisations »`, `« Paiements »`, `« Webhooks en échec »`.

> **Chaque statistique est isolée dans un `try/catch`.** Si un sous-système est en panne,
> sa carte affiche `0` au lieu de casser la page. Un `0` sur « Paiements bloqués » pendant
> une panne n'est pas une information : vérifiez `/ready` avant de conclure.

---

## Repères techniques

| Comportement | Fichier |
|---|---|
| Portée d'accès (projets visibles) | `packages/identity/src/Contracts/OperatorContext.php`, `Core\Support\Access\AccessScope` |
| Règle 404 « introuvable » / 403 « interdit » | `app/Http/Controllers/Admin/Concerns/ResolvesScopedProject.php` |
| Écrans d'organisations et de membres | `app/Http/Controllers/Admin/OrganizationsController.php`, `…/OrganizationMembersController.php` |
| Écrans de projets | `app/Http/Controllers/Admin/ProjectsController.php` |
| Portefeuille | `app/Http/Controllers/Admin/PortfolioController.php` |
| Tableau de bord | `app/Http/Controllers/Admin/DashboardController.php` |
| Barre latérale | `resources/views/layouts/admin.blade.php`, `app/Http/View/Composers/AdminSidebarComposer.php` |
| Règles de cycle de vie | `docs/adr/0069-organisations-projets-et-acces-console-scopes.md` |
