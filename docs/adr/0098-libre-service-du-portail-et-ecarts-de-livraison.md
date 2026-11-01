# ADR-0098 — Libre-service du portail et écarts de livraison des lots 12 et 13

- **Statut** : Proposé — décisions prises en livraison sur instruction du commanditaire ;
  à ratifier par le comité d'architecture
- **Date** : 2026-10-29
- **Décideurs** : commanditaire (cadrage de livraison) ; comité d'architecture (ratification)
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Amende** : ADR-0075 §3, ADR-0072 §9, ADR-0074 §1, ADR-0090 §1

## Contexte

La livraison des lots 12 et 13 a produit des comportements que les ADR ne listent pas
explicitement. Une capacité sans décision écrite est exactement la dérive que la gouvernance
combat (ADR-0091 en a consigné huit après coup). Cet ADR **tranche** : ce qui est gardé est
décidé ici, ce qui ne l'est pas a été retiré.

## Décision

### 1. Gardé : le client agit seul sur son contrat, dans le portail

ADR-0075 §3 liste « annuler à échéance » et « changer de plan parmi les plans publics ». Le
besoin exprimé — « renouveler, changer de plan, etc. » — ajoute trois gestes, **tous soumis à
`portal_settings`** (désactivés par défaut) et tous des opérations **déjà décidées** de `billing` :

| Geste | Réglage | Opération de `billing` |
|---|---|---|
| Souscrire à un plan public (client sans contrat vivant) | `allow_plan_change` | `CreateSubscription` (ADR-0072 §7) |
| Renouveler un contrat terminé (`canceled`, `expired`) | `allow_plan_change` | `CreateSubscription` sur le même plan |
| Reprendre le renouvellement (annuler son « ne pas renouveler ») | `allow_self_cancel` | **`KeepSubscriptionRenewing`** — nouveau contrat, symétrique de `CancelSubscription` |

Règles : un geste ne s'applique qu'à un contrat du client de la session (404 sinon, G15) ; un
changement d'offre est refusé sur un contrat qui prend fin ou n'est pas vivant (le planificateur
l'abandonnerait en silence) ; souscrire deux fois sur le même plan est **un succès idempotent**,
jamais une erreur ; un client déjà abonné à un autre plan est refusé avec une phrase.

### 2. Gardé : `subscription.resumed` signale aussi la reprise du renouvellement

ADR-0072 §9 réserve `subscription.resumed` à la sortie de pause. Créer un événement de plus pour
un seul champ n'apporte rien : la charge utile porte déjà `cancel_at_period_end` (`false` ici).
Un consommateur qui marque un contrat « en fin de vie » sur `subscription.canceled` +
`cancel_at_period_end = true` le démarque sur `subscription.resumed` +
`cancel_at_period_end = false`. Le kit d'intégration le documente.

### 3. Gardé : un opérateur peut signer un contrat pour un client

La fiche client de la console (ADR-0080 §1, niveau Client) expose `CreateSubscription`,
l'opération de l'API publique, avec la permission `subscriptions:write` et son audit. Aucune
opération nouvelle : un raccourci d'écran vers une opération décidée (ADR-0080 §5).

### 4. Gardé : le profil du portail est en lecture seule

ADR-0075 §3 prévoit « mettre à jour email/téléphone ». **Retiré** : le projet est propriétaire de
l'identité de ses clients (ADR-0084 §1) ; deux écrivains du même contact divergent. Le portail
montre un profil masqué et renvoie vers le projet. ADR-0075 §3 est amendé.

### 5. Retiré, faute de décision : la pause libre-service et l'ajustement manuel d'usage

- La **pause** est un geste d'exploitation (ADR-0072 §6) ; l'ouvrir au client n'a été décidé nulle
  part et n'a pas été demandé. Retirée du portail.
- L'**ajustement manuel d'un compteur** par l'opérateur n'est pas dans ADR-0080 §5 (« la console
  n'invente aucune opération »). Retiré. Si le besoin se confirme : ADR dédié (écriture
  compensatoire signée, motif obligatoire).

### 6. Écart assumé : aucune partition ni contrainte déclarée en base

ADR-0090 §1 demande le partitionnement mensuel d'`usage_records`. Le propriétaire a décidé que les
migrations s'écrivent avec le Schema builder de Laravel, **sans SQL brut ni contrainte en base** :
les règles (formats, signe d'une quantité, compteur non négatif, journal en ajout seul) vivent dans
le code (`ConsumeUsageUseCase`, `PostgresUsageStore`), et seules les clés uniques subsistent, parce
qu'une clé unique est la primitive de concurrence de l'idempotence, pas une règle métier.
Conséquence : le journal n'est protégé de la modification que par le code, et la partition devient
une **tâche d'exploitation du lot 15**. Les migrations plus anciennes du dépôt gardent leurs
contraintes : on ne les réécrit pas.

### 7. Indicateurs : « encaissé » est celui du mois

ADR-0080 §2 définit « Encaissé (période) ». Il est calculé comme la différence des débits des
comptes `clearing` et `manual_receipts` entre maintenant et l'ouverture du mois, lus au grand
livre. Limite connue, à lever au lot 14 : un reclassement de compte de règlement débite deux fois
un compte (lire par type de transaction).

## Alternatives écartées

- **Laisser les gestes sans ADR.** Rejeté : c'est la dérive D1 ; le comité l'a déjà corrigée une fois (ADR-0091).
- **Un événement `subscription.renewal_resumed`.** Rejeté : un nom de plus pour un champ déjà présent (ADR-0086 : tout ajout d'événement est un contrat à maintenir).
- **Modification du contact par le portail.** Rejeté : second écrivain du même contact.

## Conséquences

- `billing` gagne le contrat `KeepSubscriptionRenewing` (aucune arête de graphe).
- Le kit d'intégration traite `subscription.canceled` avec `cancel_at_period_end = true` comme un avertissement, jamais comme un blocage.
- La carte des capacités passe les lots 12 et 13 de ◐ à ●.

## Vérification

- `PortalFlowTest` : souscrire (et rejouer), renouveler, reprendre le renouvellement, refus sur le contrat d'un autre client, refus de changer d'offre sur un contrat qui prend fin.
- `SaasLifecycleFlowTest` : essai → paiement → renouvellement émis d'avance → impayé avec droits conservés → expiration → réabonnement.
- Un renouvellement payé avant le début de sa période ne passe jamais le contrat en impayé.
