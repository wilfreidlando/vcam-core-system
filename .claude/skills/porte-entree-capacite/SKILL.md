---
name: porte-entree-capacite
description: Run the four Core-entry gates against a proposed capability and produce a verdict plus a draft ADR. Use before adding any new bounded context or before deciding whether a feature belongs in the Core vs a consuming product.
allowed-tools: Read, Grep, Write
---

# Les quatre portes d'entrée dans le Core

Source : `docs/specs/gouvernance-anti-derive.md` §4. Une capacité ne rejoint le
Core que si elle passe les quatre portes, dans l'ordre. Un seul « non » la
maintient dans le produit qui la demande.

## Les quatre questions, dans l'ordre

1. **Transverse ?** Au moins **deux produits réels** en ont un besoin actuel —
   pas hypothétique, pas « ce sera utile un jour ». Si un seul produit le
   demande : non, ça reste dans ce produit.

2. **Générique ?** Aucune règle métier spécifique à un secteur ou un client.
   Si l'implémentation doit « connaître » qu'il s'agit d'un hôtel, d'une école
   ou d'un catalogue particulier : non.

3. **Gain > coût ?** La centralisation apporte un gain net (sécurité,
   réutilisation, conformité, réduction d'intégrations) supérieur au coût de
   maintenance et de coordination entre équipes.

4. **Contractualisable ?** Exposable par un contrat stable (API/événements)
   sans fuite d'implémentation. Si l'exposer proprement est impossible : le
   découpage est à revoir, pas seulement la réponse à cette porte.

## Pour un cas limite : la grille de notation

Chaque critère noté 0 à 3 :

| Critère | 0 | 3 |
| ------- | - | - |
| Transversalité | 1 seul produit | ≥ 3 produits |
| Généricité | Fortement spécifique | Totalement générique |
| Valeur (sécurité/conformité) | Marginale | Critique et transverse |
| Contractualisabilité | Fuite inévitable | Contrat net et stable |
| Coût de maintenance | Élevé et incertain | Faible et prévisible |

Une note globale basse : différer ou garder dans le produit.

## Procédure

1. Poser les quatre questions dans l'ordre à l'utilisateur ou à partir du
   contexte fourni ; s'arrêter au premier « non ».
2. Si toutes les portes passent (ou si le score de la grille est net), rédiger
   un verdict explicite : la capacité entre / n'entre pas / est différée.
3. Vérifier `docs/gouvernance/liste-negative.md` (à créer si absent) pour la
   jurisprudence des refus passés — un refus similaire a peut-être déjà été
   tracé.
4. Utiliser la skill `nouvelle-adr` pour enregistrer le verdict, acceptation
   **ou refus** — les deux sont tracés.

## Ne jamais faire

- Accepter une capacité « pour ne pas refaire ce travail plus tard » — c'est
  précisément le raisonnement qui produit un Core fourre-tout (dérive D1).
- Sauter une porte parce que les trois autres sont clairement positives — les
  quatre sont évaluées, dans l'ordre, à chaque fois.
