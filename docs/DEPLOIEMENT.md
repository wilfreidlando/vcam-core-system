# Déploiement du Core System

> **Pour toute l'équipe.** Comment le Core est déployé sur le VPS, environnement par environnement. À jour au **2026-10-05**, version du contrat de la plateforme : **2.5**.
> La plateforme (outils, règles, runbooks) : <https://github.com/wilfreidlando/vcam-infra-deploy>. Profil de projet : **A, standard (staging puis production)**
> ([profils](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/reference/profils-de-projet.md)).
> Le détail de la **toute première installation**, du premier opérateur et du premier paiement : [`docs/operations/deploiement-production.md`](operations/deploiement-production.md).

## 1. La carte du projet : un environnement par ligne

| Environnement | Adresse | Dossier sur le serveur | Fichier d'environnement | Version déployée | Qui déploie, et comment | Sauvegardée ? |
| --- | --- | --- | --- | --- | --- | --- |
| **staging** | `https://core-system-staging.visibilitycam.com` | `/app/core-system/staging` | `.env.staging` (600) | `develop` (`STAGING_BRANCH` dans `platform.env`) | `make staging-deploy` (ou `deploy.sh watch`) | non (`BACKUP_DISABLED=1`) |
| **production** | `https://core-system.visibilitycam.com` | `/app/core-system/prod` | `.env` (600) | **la version validée en staging**, jamais construite en production | le responsable du Core : `make prod-promote` | **non, pour l'instant** : exception acceptée (pas de bucket S3, `BACKUP_DISABLED=1`), voir « Écarts connus ». La règle du standard est **S3, obligatoire** |

Les noms des conteneurs de **production** sont **historiques** (`core-system-app`, `core-system-postgres`…, réseau `core-system-internal`) : ne jamais les renommer. Ceux du staging sont préfixés `core-system-staging-`.

## 2. Ce qui tourne

| Service | Rôle | Réseaux | Santé | Données |
| --- | --- | --- | --- | --- |
| `webserver` | nginx sidecar : seul service public, ajoute l'en-tête de confiance | `nginx-proxy`, privé | oui | — |
| `app` | Application Laravel (FrankenPHP) | privé, `observability` | `/health/ready` (base et Valkey) | — |
| `horizon` | Files d'attente (paiements, webhooks, notifications) | privé, `observability` | — | — |
| `scheduler` | Tâches planifiées (rapprochement des paiements bloqués…) | privé, `observability` | — | — |
| `postgres` | PostgreSQL 18 | privé **seulement** | `pg_isready` par le réseau | volume `core-system-<env>_postgres-data` |
| `valkey` | Cache, files, sessions | privé **seulement** | `valkey-cli ping` | volume `core-system-<env>_valkey-data` |
| `backup` | Sauvegarde chiffrée de la base, envoyée sur S3 puis effacée du serveur | privé | un envoi réussi il y a moins de 36 h | marqueur seulement |

Aucun port n'est publié sur Internet.

## 3. Les variables : leur nom et leur rôle (jamais leur valeur)

| Variable | Rôle | Diffère par environnement ? | D'où elle vient |
| --- | --- | --- | --- |
| `APP_KEY` | Chiffrement de l'application **et des secrets enregistrés en base** (clés des applications fournisseurs, secrets des webhooks) | **oui** | générée **une fois, conservée pour toujours** : la changer rend ces secrets illisibles |
| `DB_PASSWORD` | Mot de passe de la base | oui | `openssl rand -base64 32` |
| `CORE_PUBLIC_HOST` | Nom public (**jamais `VIRTUAL_HOST`** : tous les conteneurs chargent ce fichier) | oui | le DNS |
| `TRUSTED_PROXY_TOKEN` | Prouve à l'application que les en-têtes transmis viennent de son nginx | **oui** | `openssl rand -hex 32` |
| `CORE_ENVIRONMENT` | `sandbox` ou `live` : seul `live` fait circuler de l'argent réel | **oui : le staging est toujours `sandbox`** | fixe |
| `DEPLOYMENT`, `STACK_PREFIX`, `CORE_ENV_FILE` | Identité de la pile | oui | exemples du dépôt |
| `MTN_MOMO_*`, `ORANGE_MONEY_*`, `WAHA_*`, `MAIL_*` | Fournisseurs et messagerie | **oui : au staging, des identifiants de test** | les fournisseurs |
| `IDENTITY_DISABLE_MFA`, `IDENTITY_DISABLE_TOTP_IN_LOCAL` | Double authentification de la console | **désactivée** en production et en staging (décision du 2026-10-05) ; l'exemple dit `false` | à réactiver : voir « Écarts connus » |
| `BACKUP_*`, `AWS_*`, `BACKUP_DISABLED` | Sauvegardes sur S3 ; `BACKUP_DISABLED=1` au staging | staging : désactivée ; **production : `BACKUP_DISABLED=1` aussi pour l'instant** (exception, voir « Écarts connus »), S3 à brancher | la plateforme |

Modèles : `.env.production.example` et `.env.staging.example`. Droits des `.env` : `600`, propriétaire `root`. **La règle : jamais les secrets de la production au staging** (écart actuel : voir « Écarts connus »).

## 4. Premier déploiement d'un environnement

Voir [`docs/operations/deploiement-production.md`](operations/deploiement-production.md) (installation complète). En bref, une fois les dossiers et les `.env` prêts :

```bash
cd /app/core-system/staging && sudo /app/vps-platform/bin/deploy.sh check staging      # contrôle seul : ne change rien
cd /app/core-system/staging && sudo make staging-deploy                                 # construit l'image de la branche et la déploie
cd /app/core-system/prod    && sudo /app/vps-platform/bin/deploy.sh check prod && sudo make prod-promote
```

**Vérifier** : `make deploy-status` ; `curl -fsS https://<adresse>/health/ready` répond 200 ; Grafana, **Plateforme → Application — vue d'ensemble**, choisir `core-system`.

## 5. Livrer une nouvelle version

| Étape | Qui | Commande ou action | Vérification |
| --- | --- | --- | --- |
| 1. Merger sur `develop` | le développeur | merge request | — |
| 2. Le staging se met à jour | `make staging-deploy` (`REF=origin/<branche>` pour une autre branche) | — | `make deploy-status` |
| 3. **Recette** | le responsable du Core | connexion à la console du staging, un paiement **sandbox**, une notification de test, un webhook | tout fonctionne, **avec des identifiants de test** |
| 4. Migrations : **les répéter sur une copie de la production** | le responsable du Core | [guide 6, étape 5 bis](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/06-core-au-standard.md) | comparer les lignes avant et après ; lire toute migration qui **supprime** |
| 5. Une **sauvegarde à la main** avant une migration risquée | le responsable | `/app/vps-platform/bin/deploy.sh backup prod` | `uploaded to s3://…` |
| 6. Contrat d'API modifié ? | le responsable | **annoncer aux intégrateurs avant** | — |
| 7. Production | le responsable | `cd /app/core-system/prod && make prod-promote` (taper « oui ») | `/health/ready` 200, `make deploy-status`, Grafana |

La production n'est **jamais** déployée automatiquement. **Les migrations du Core sont dans les modules** (`packages/*/database/migrations`), pas seulement dans `database/migrations` : les chercher avec
`git diff --name-status <version en production> <nouvelle version> -- '*migrations*'`.

## 6. Mettre à jour ce qui est déjà là

| Je change… | Je fais | Coupure | Retour arrière |
| --- | --- | --- | --- |
| Le **code** | § 5 | quelques secondes en production | `make rollback ENV=prod` |
| Une **variable** | éditer le `.env` de l'environnement, puis `deploy.sh up <env> <version courante>` | quelques secondes | remettre l'ancienne valeur, même commande |
| `compose.prod.yaml` ou `platform.env` | un commit, puis § 5 (lus **dans le commit déployé**) | comme une livraison | `make rollback ENV=<env>` |
| Les **tableaux et alertes du Core** | `observability/` de ce dépôt ; publiés après la production, ou `deploy.sh obs-sync` | aucune | republier la version précédente |

## 7. Retour arrière

- **Automatique** : si la nouvelle version ne répond pas à `/health/ready`, l'ancienne est remise (sauf la toute première promotion d'un projet existant, qui n'a pas de version précédente).
- **À la main** : `make rollback ENV=<env>`. **Les migrations ne sont pas annulées** : vérifier que l'ancien code les supporte, sinon restaurer la dernière sauvegarde (§ 8).

## 8. Sauvegarde et restauration

- **Règle du standard** : sauvegarde chiffrée **chaque nuit**, envoyée sur **S3** puis effacée du serveur ; aucune copie ne reste sur le disque. À la demande : `deploy.sh backup prod`.
- **État actuel : la production du Core n'est PAS sauvegardée automatiquement** (pas de bucket S3, `BACKUP_DISABLED=1`). Une copie manuelle a été faite le 2026-10-05 avant le passage au standard : `/root/core-avant-standard-2026-10-05.dump` sur le serveur, **à ne pas supprimer** tant que le bucket n'existe pas. `deploy.sh backup prod` et la restauration ci-dessous **ne fonctionneront qu'une fois S3 branché**.
- **Restaurer** : `restore.sh prod s3://<bucket>/<préfixe>/core-system/<fichier>` avec la phrase de chiffrement ; la base est **remplacée** (état exact de la sauvegarde).
- Le staging n'est **pas** sauvegardé.
- **Exercice de restauration chaque mois** : [procédure](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/exercice-de-restauration.md).

## 9. Observabilité

| Quoi | Où |
| --- | --- |
| Disponibilité et certificat | les adresses sont dans la liste des sites sondés |
| Journaux | Grafana → Explore → Loki : `{app="core-system", deployment="prod"}` ; format `structured` (JSON) |
| État d'ensemble | Grafana → Plateforme → **Application — vue d'ensemble** → `core-system` |
| Tableaux et alertes **propres au Core** | dossier Grafana `core-system` : 2 tableaux et 6 alertes **livrés par ce dépôt** (`observability/`), publiés par `deploy.sh` |
| Métriques | `/metrics`, **réservé au réseau privé** |

Le détail : [`docs/operations/observabilite.md`](operations/observabilite.md) et le [guide 19 de la plateforme](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/19-observabilite-de-mon-projet.md).

## 10. En cas de problème

| Symptôme | Où aller |
| --- | --- |
| Le site ne répond plus | [runbook « site en panne »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/site-en-panne.md) |
| `deploy.sh` s'arrête avec une erreur | [runbook « déploiement en échec »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/deploiement-en-echec.md) |
| Un conteneur redémarre sans cesse | [runbook « conteneur en boucle »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/conteneur-en-boucle.md) |
| La base est inaccessible | [runbook « base inaccessible »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/base-inaccessible.md) |

**Qui prévenir** : le responsable du Core ; plateforme : le responsable de la plateforme.

## 11. Écarts connus avec le standard (à résorber)

| Écart | Pourquoi | Risque | Pour le résorber |
| --- | --- | --- | --- |
| **Production sans sauvegarde automatique** | Pas de bucket S3 pour l'instant (décision du 2026-10-05) | Une perte de la base ne serait récupérable qu'à partir de la copie manuelle du 2026-10-05. **Un rappel par e-mail est envoyé une fois par jour** (alerte « Une sauvegarde de production n'envoie plus de copie depuis 36 heures »), à partir du premier passage nocturne de l'agent suivant le déploiement de cette alerte | Créer le bucket, renseigner `BACKUP_S3_*`, retirer `BACKUP_DISABLED`, **refaire un exercice de restauration** |
| **Le staging porte des secrets et des données de production** | Recette avec la copie de la base de production, `APP_KEY` et clés WhatsApp de production, à la demande du responsable | Des envois réels depuis le staging si une file ou une tâche planifiée s'y exécute (l'horizon et le planificateur du staging sont **en marche**) | Après la recette : base de test, nouvelle `APP_KEY`, identifiants de test, `CORE_ENVIRONMENT=sandbox` |
| **Double authentification de la console désactivée** | Décision du responsable, production et staging | Un mot de passe volé suffit | Réactiver `IDENTITY_DISABLE_MFA=false` |
| **`WAHA_WEBHOOK_SECRET` absent** | Jamais renseigné | Les rappels WhatsApp entrants ne sont pas authentifiés | Le générer et le déclarer des deux côtés |
| **Contrat d'API modifié, intégrateurs non prévenus** | Beaucoup de changements dans `develop` par rapport à l'ancien `main` | Un intégrateur peut casser sans préavis | Annoncer les changements (liste dans les ADR depuis 0051) |

## 12. Historique de ce document

| Date | Changement |
| --- | --- |
| 2026-10-05 | Création d'après le modèle de la plateforme : environnements, répétition des migrations, sauvegardes sur S3, observabilité portée par le dépôt |
| 2026-10-05 | Contrôle en profondeur : la fiche disait « production sauvegardée sur S3 » et « jamais de secrets de production au staging » alors que ce n'est pas l'état réel ; ajout des **écarts connus**. Contrat 2.5 |
