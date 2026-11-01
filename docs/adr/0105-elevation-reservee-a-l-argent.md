# ADR-0105 — L'élévation de privilège est réservée à l'argent

- **Statut** : Accepté
- **Date** : 2026-10-30
- **Décideurs** : commanditaire (décision de politique de sécurité)
- **Dérives concernées** : D1 périmètre · D5 contrat
- **Précise** : ADR-0081 (S-05, modèle de menace), annexe sécurité de la recette du 2026-10-29 (écart #5, garde-fou G-4)

## Contexte

La recette du 2026-10-29 (annexe sécurité, écart #5) a posé une élévation de privilège :
avant une **action sensible** de la console, l'opérateur ressaisit un code TOTP, valable
cinq minutes. La décision était prise sur une liste de vingt et une routes : les actions
qui déplacent de l'argent (facture, reçu, exception d'argent, correction du grand livre) et,
à côté, des actions qui n'en déplacent pas — enregistrer un endpoint webhook, émettre une
clé d'API, inviter un opérateur, révoquer un membre d'organisation, effacer l'identité
d'un client.

Le mécanisme lui-même est sain : le MFA de la connexion prouve l'identité une fois, le
`step_up` redemande une preuve fraîche quand l'acte engage. Ce qui ne tenait pas, c'est
l'**étendue** : demander un second code pour créer un endpoint webhook ou inviter un
opérateur n'ajoute aucun contrôle proportionné à ce que ces actes peuvent faire — et cela
coûte à chaque fois une saisie et un aller-retour sur une page d'élévation.

Reste que la console **distinguait déjà** la direction sûre de la direction engageante :
dans la famille argent, `money-exceptions.reject` et `ledger-adjustments.reject` (refuser)
n'exigeaient pas d'élévation, tandis que `approve` et `resolve` (qui écrivent au grand
livre) l'exigeaient. La règle « l'élévation là où l'argent bouge » était déjà appliquée —
route par route, par famille. Elle ne l'était simplement pas appliquée aux autres familles.

## Décision

**Décision 1 — l'élévation TOTP est demandée au moment où l'argent bouge, et nulle part ailleurs.**

Les sept routes qui gardent `operator.step_up` sont exactement celles qui déplacent de
l'argent :

| Route | Ce qu'elle écrit |
|---|---|
| `admin.projects.invoices.issue.web` | émet la facture : la créance et son écriture au grand livre |
| `admin.projects.invoices.void.web` | annule la facture : la contre-passation |
| `admin.projects.invoices.uncollectible.web` | déclare la facture incassable |
| `admin.projects.receipts.approve.web` | valide un encaissement hors ligne : la balance du portefeuille |
| `admin.money-exceptions.resolve` | tranche une exception d'argent |
| `admin.money-exceptions.approve` | approuve une exception au-dessus du seuil |
| `admin.ledger-adjustments.approve` | fait écrire la correction par le grand livre |

**Décision 2 — les autres écritures sensibles perdent l'élévation, et gardent tout le
reste.** Elles restent **journalisées** (le journal d'audit enregistre chaque geste de la
console) et **plafonnées** (`throttle:console-sensitive`, écart #17). Ce qui disparaît, c'est
la seule *redemande* de code TOTP.

Les routes concernées : endpoints webhook (enregistrer, modifier, faire tourner le secret,
révoquer le secret précédent), clés d'API (émettre, faire tourner, révoquer), opérateurs
(inviter, révoquer, changer le rôle), membres d'organisation (inviter, révoquer, changer
le rôle), effacement de l'identité d'un client.

**Décision 3 — le MFA de la connexion reste la preuve de l'identité, et il reste
obligatoire.** Rien n'est allégé de ce côté : le TOTP est demandé à la première connexion
(avec l'enrôlement) et à **chaque** connexion. Une session volée reste une session
d'un opérateur déjà authentifié — c'est ce que l'élévation protégeait sur les routes
d'argent, et c'est là qu'elle reste.

**Décision 4 — le garde-fou de classement devient plus strict, pas plus lâche.**

`StepUpAndThrottleTest` ne se contente plus de vérifier que les routes sensibles portent
l'élévation : il vérifie **aussi** que les routes qui ne déplacent pas d'argent **ne la
portent pas** (`only_the_writes_that_move_money_demand_the_step_up`), et il n'oublie plus
les factures ni les reçus dans les familles à classer. Une route d'argent nouvelle ne peut
donc pas être classée « plafonnée seulement » par megarde : le test le signale.

## Alternatives écartées

**Garder l'élévation partout.** — Rejeté : c'est l'état que le commanditaire a explicitement
écarté. La demande était sans ambiguïté : l'élévation « ne doit être faite qu'à la
connexion ou à la manipulation de l'argent ».

**Retirer l'élévation partout, y compris sur l'argent.** — Rejeté : cela laisserait une
session de console volée capable d'émettre une facture ou d'approuver un encaissement sans
aucun second facteur. Le contrôle « l'argent bouge » est celui qui a le plus de sens.

**Laisser l'élévation sur l'émission d'une clé d'API `payments:write`.** — Écarté, et le
résiduel est écrit ci-dessous : une clé d'API est un instrument de mouvement d'argent, et
la console en émet. C'est l'argument qui poussait à la conserver ; il n'a pas retenu,
parce que la règle énoncée est « l'argent bouge » et qu'une clé d'API ne bouge rien par
elle-même. **Le résiduel est assumé et tracé** : voir « Conséquences ».

## Conséquences

**Ce qui s'allège** : l'opérateur ne ressaisit plus de code pour les gestes de
configuration et d'organisation. La console garde le MFA de la connexion, qui est la
preuve la plus forte qu'elle ait.

**Le résiduel, écrit** : une session de console compromise — un poste laissé ouvert, un
XSS, un vol de cookie de session — peut désormais **émettre une clé d'API portant la
portée `payments:write`** sans second facteur, puis se servir de cette clé pour déclencher
un paiement. L'audit de chaque geste reste, et le plafond reste, mais le second facteur,
lui, n'est plus là sur ce chemin.

La parades possibles, si ce risque se matérialise : restreindre l'émission d'une clé
`payments:write` aux opérateurs d'un rôle particulier, exiger l'approbation d'un second
opérateur pour une portée d'écriture, ou re-mettre l'élévation sur cette seule route.
Aucune n'est décidée ici.

**Ce qui est engagé** :
- l'écran d'élévation et son plafond d'essais restent en place ;
- `StepUpTest` prouve les deux moitiés de la règle (une écriture d'argent bloque, une
  écriture qui n'en déplace pas ne l'est pas) ;
- `StepUpAndThrottleTest` garde le classement des routes et interdit le retour de
  l'élévation sur une route qui ne déplace pas d'argent.

**Point de réévaluation** : l'émission d'une clé d'API `payments:write` est le point à
surveiller. Si le Core expose un jour une capacité de mouvement d'argent qui ne passe
pas par une écriture de console, la règle doit être revue — elle porte sur les *écritures
de la console*, pas sur toutes les façons de déplacer de l'argent.
