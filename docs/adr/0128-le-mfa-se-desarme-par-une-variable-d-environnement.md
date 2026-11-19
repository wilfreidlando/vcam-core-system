# ADR-0128 — Le MFA de l'opérateur se désarme par une variable d'environnement

- **Statut** : Accepté
- **Date** : 2026-11-17
- **Décideurs** : commanditaire (« je veux qu'il y'ai une variable qui me permet de desactiver le MFA a partir d'une variable d'env tout simplement », « je peux vouloir desactiver meme en prod je le fais », « je veux un seule variable pour gerer ca »), comité d'architecture
- **Dérives concernées** : D3 données · D5 contrat
- **Précise** : ADR-0081 S-05 (authentification forte de la console), ADR-0064 (le déploiement de staging), ADR-0066 §5 (les tests d'intégration se font sur le staging)
- **Amende** : ADR-0115 (décision 2 — l'exemple du refus MFA qu'elle citait)

## Contexte

La console exige un **second facteur** pour chaque connexion d'opérateur
(ADR-0081 S-05). L'audit du 2026-10-29 (#12) a prévu un contournement
d'exploitation : `IDENTITY_DISABLE_MFA=true` retire l'étape MFA de la
connexion — un mot de passe juste donne immédiatement la session, sur les
**deux** plans (web et API).

Le drapeau existe, il est câblé, il est journalisé (`operator.mfa_skipped`)…
mais il est **refusé au démarrage hors de `APP_ENV=local`**
(`MfaBypassForbidden`). La raison est bonne : un fichier d'environnement
copié par erreur ne doit pas retirer le second facteur d'un déploiement qui
face à Internet.

Mais elle fait que l'exigence du commanditaire — « une variable qui me permet
de désactiver le MFA à partir d'une variable d'env tout simplement » — n'est
pas satisfaite :

- le **staging** n'est pas `local` : il tourne avec `APP_ENV=production`
  (ADR-0064), c'est le même code et la même image que le déploiement vivant.
  Le drapeau y est donc inutilisable — et c'est là que se font les tests
  d'intégration (ADR-0066 §5) ;
- le commanditaire a ensuite dit qu'il pouvait vouloir le MFA désarmé **en
  production**, et qu'il le ferait lui-même.

Deux versions ont été envisagées avant celle-ci : d'abord un marqueur de
déploiement (`DEPLOYMENT=staging` / `prod`), puis une seconde ligne
(`IDENTITY_DISABLE_MFA_ALLOW_LIVE=true`) pour ouvrir la production. Le
commanditaire a tranché : « je veux une seule variable pour gérer ça ».

## Décision

**Décision 1 — une seule variable, partout.**

`IDENTITY_DISABLE_MFA=true` désarme le MFA de l'opérateur **sur n'importe quel
runtime** : le poste du développeur, le déploiement de staging, le déploiement
vivant. Pas de seconde variable, pas d'environnement à déclarer, pas de
condition à satisfaire : le drapeau **est** la décision.

**Décision 2 — le refus au démarrage disparaît.**

`MfaBypassForbidden` est retiré, et avec lui le garde-fou qui refusait de
démarrer hors `local`. Une variable d'environnement décide désormais de la
sécurité de la console : c'est la décision du commanditaire, elle est
écrite ici, et elle est lisible dans le dépôt.

**Décision 3 — ce qui remplace le refus, c'est la trace.**

Chaque connexion sans MFA est journalisée (`operator.mfa_skipped`, avec la
raison et le plan — `web` ou `api`), et la console l'annonce au moment où
elle ouvre la session (« MFA désactivée sur cet environnement »). Une fenêtre
sans MFA est donc **visible dans le journal**, sur n'importe quel runtime,
même en production : c'est là qu'on la voit, et c'est là qu'on la juge.

**Décision 4 — les gabarits de déploiement ne livrent jamais le drapeau actif.**

`.env.production.example` et `.env.staging.example` gardent
`IDENTITY_DISABLE_MFA=false` : le garde-fou G-7 (audit 2026-10-29) continue de
l'exiger. L'activation reste un **acte volontaire**, posé sur le serveur, dans
le fichier d'environnement du déploiement.

**Décision 5 — `disable_totp_in_local` n'est pas concerné.**

Ce drapeau-là ne désarme pas le MFA : il garde l'écran d'enrôlement et de
vérification, et fait accepter n'importe quel code à 6 chiffres. Il reste
refusé hors `local`, comme avant.

## Alternatives écartées

- **Le refus hors `local`, puis une seconde ligne pour la production** — c'est
  la version que le commanditaire a refusée : deux variables à poser, deux
  notions à maintenir (l'environnement, puis le déverrouillage), pour une
  action unique.
- **Un marqueur de déploiement (`DEPLOYMENT=staging` / `prod`)** — un
  concept de plus dans les fichiers d'environnement, pour un runtime qui
  n'en a pas besoin : le drapeau n'a qu'à dire ce qu'il fait.
- **Supprimer le refus partout, sans trace** — rejeté : la trace est ce qui
  rend le drapeau gérable. Un second facteur désarmé en silence serait un
  trou noir ; désarmé et journalisé, c'est une décision visible.

## Conséquences

- **Une variable, partout** : c'est ce que le commanditaire demandait.
- Le second facteur de la console est à la merci d'une ligne d'environnement :
  c'est voulu, et c'est écrit. La règle « MFA obligatoire » (ADR-0081 S-05)
  reste la règle par défaut ; elle n'est plus opposable par le code à une
  décision d'exploitation.
- Le journal garde la trace de chaque connexion sans MFA : on sait qui s'est
  connecté sans second facteur, et quand.
- ADR-0115 (les cibles privées) cite le refus MFA comme son analogue : le
  refus des cibles privées, lui, reste en place — c'est le MFA qui a changé.

## Vérification

- `packages/identity/tests/Integration/MfaBypassTest.php`
  — l'application démarre avec le MFA désarmé sur n'importe quel runtime, et
  avec le MFA actif sur n'importe quel runtime.
- `tests/Feature/Admin/MfaDisabledLoginTest.php`
  — console et API : la session (et le jeton) partent sans second facteur,
  et l'entrée `operator.mfa_skipped` est au journal ; sans la variable, le
  MFA est toujours demandé et rien n'est journalisé comme sauté.
- `tests/Architecture/ProductionFlagsTest.php`
  — les gabarits de déploiement ne livrent jamais le drapeau actif.
