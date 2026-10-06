# Documentation du Core System

> Le point d'entrée de toute la documentation du dépôt. Chaque page est listée ici, avec ce qu'elle apporte. À jour au **2026-10-05**.

## Je veux…

| Besoin | Page |
| --- | --- |
| **Déployer** le Core, environnement par environnement (staging, production), livrer, revenir en arrière, savoir ce qui sort du standard | [Fiche de déploiement](DEPLOIEMENT.md) |
| Faire la **toute première installation** sur un serveur vierge | [Déploiement en production](operations/deploiement-production.md) |
| **Voir ce qui se passe** : journaux, traces, métriques, tableaux Grafana, alertes | [Observabilité](operations/observabilite.md) |
| **Comprendre l'architecture** d'un coup d'œil | [Vue d'ensemble](architecture/vue-ensemble.md) |
| Savoir **pourquoi** une décision a été prise | [Index des ADR](adr/README.md) |
| **Intégrer** un produit au Core (appels, clés, webhooks) | [Guide `curl` de l'intégrateur](integration/guide-curl-integrateur.md), [Webhooks et `callback_url`](integration/webhooks.md) |

## L'exploitation

| Page | Contenu |
| --- | --- |
| [Fiche de déploiement](DEPLOIEMENT.md) | La carte du projet, les variables, la livraison, la mise à jour, le retour arrière, la sauvegarde, les **écarts connus** avec le standard |
| [Déploiement en production](operations/deploiement-production.md) | Installation complète, du serveur vierge au premier paiement |
| [Observabilité](operations/observabilite.md) | Ce qui est journalisé (et son niveau), les tableaux et alertes du Core, comment lire un parcours |

## L'architecture

| Page | Contenu |
| --- | --- |
| [Vue d'ensemble](architecture/vue-ensemble.md) | Modules, topologie d'exécution, anatomie d'une requête, garde-fous |
| [Registre des garde-fous](architecture/garde-fous-inactifs.md) | L'état de chaque garde-fou, daté : un garde-fou qu'on croit actif sans qu'il protège est pire que pas de garde-fou |
| [Architecture d'intégration des services externes](architecture/guides/architecture-integration-externe.md) | Le socle qui parle aux fournisseurs de paiement et de messagerie |
| [Avis d'architecte du 2026-09-14](architecture/avis-architecte-2026-09-14.md) et [la réponse](architecture/reponse-avis-architecte-2026-09-14.md) | Revue externe de la structure et suites données |
| [Prompt de passation du socle d'intégration](architecture/prompt-socle-integration.md) | Document de passation interne (historique) |

## La gouvernance

| Page | Contenu |
| --- | --- |
| [Comité d'architecture](gouvernance/comite-architecture.md) | Sa composition et son fonctionnement |
| [Indicateurs de dérive](gouvernance/indicateurs-derive.md) | Les indicateurs suivis en continu, leur cible et leur dernière mesure |
| [Liste négative](gouvernance/liste-negative.md) | Ce que le Core a **refusé** d'absorber, et pourquoi |
| [Registre des exceptions](gouvernance/registre-exceptions.md) | Chaque exception : datée, tracée par un ADR, avec une date de revue |
| [Gouvernance anti-dérive (spécification)](specs/gouvernance-anti-derive.md) | Les quatre portes avant d'ajouter quoi que ce soit |

## Les spécifications et le plan

| Page | Contenu |
| --- | --- |
| [Spécification fonctionnelle](specs/spec-fonctionnelle.md) | Ce que fait le Core |
| [Spécification technique](specs/spec-technique.md) | Comment il le fait (schémas, contrats) |
| [Plan de delivery du MVP](specs/plan-delivery-mvp.md) | Les lots et leur ordre |
| [Plan d'implémentation d'octobre 2026](plan/plan-implementation-2026-10.md) | Durcissement, observabilité, clés, statuts, console, documentation |
| [Rapport d'audit du 2026-10-03](audit/audit-2026-10-03.md) | Sécurité, conception, documentation intégrateur et processus (surtout les webhooks) |

Les sources `.docx` des spécifications sont dans [`specs/source/`](specs/source).

## La plateforme d'hébergement

Les outils, règles et runbooks du serveur ne sont pas dans ce dépôt : [vcam-infra-deploy](https://github.com/wilfreidlando/vcam-infra-deploy) (cloné sur le serveur dans `/app/vps-platform`).
