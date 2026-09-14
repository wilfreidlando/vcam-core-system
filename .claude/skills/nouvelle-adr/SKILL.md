---
name: nouvelle-adr
description: Create a new Architecture Decision Record from the template, correctly numbered and linked from the index. Use whenever a structural decision is made — including a refusal, which is as valuable as an acceptance (gouvernance §12).
allowed-tools: Read, Write, Edit, Bash(ls docs/adr/*)
---

# Nouvelle ADR

Toute décision structurante est tracée : entrée d'une capacité dans le Core,
choix de contrat, exception (datée), changement de règle transverse, écart
assumé vis-à-vis des spécifications.

## Étapes

1. **Trouver le prochain numéro** : `ls docs/adr/ | sort -V | tail -5` — les
   numéros ne se réutilisent jamais, même pour un ADR refusé ou remplacé.

2. **Copier `docs/adr/0000-modele.md`** vers
   `docs/adr/<NNNN>-<titre-kebab-case>.md`.

3. **Remplir chaque section** :
   - **Contexte** : faits vérifiables et datés (versions, dates de fin de
     support, volumétrie) — pas des suppositions.
   - **Décision** : formulée de façon vérifiable. Si un garde-fou l'impose,
     nommer son identifiant (ex. G3c) et son fichier.
   - **Alternatives écartées** : chaque option sérieuse et la raison précise
     du rejet — pas une liste creuse.
   - **Conséquences** : ce que ça facilite, ce que ça engage, un déclencheur
     de réévaluation si pertinent (ex. « avant fin de support de X »).

4. **Ajouter la ligne dans `docs/adr/README.md`**, tableau correspondant à la
   phase concernée (ou créer une nouvelle section si c'est une nouvelle
   phase du projet).

5. **Si l'ADR modifie un garde-fou existant** : mettre à jour
   `tests/Architecture/GuardRegistry.php` et
   `docs/architecture/garde-fous-inactifs.md` en cohérence — `GuardCoverageTest`
   échoue sinon.

6. **Si l'ADR refuse une proposition externe** (ex. un avis d'architecte, une
   recommandation d'outillage) : citer précisément ce qui est retenu vs rejeté,
   comme fait dans
   [ADR-0026](../../../docs/adr/0026-integration-providers-externes.md). Un
   refus argumenté est la meilleure protection contre un même débat répété.

## Ne jamais faire

- Réécrire un ADR accepté — le remplacer par un nouveau et marquer l'ancien
  « Remplacé par ADR-XXXX ».
- Sauter la section « Alternatives écartées » — c'est ce qui rend l'ADR utile
  dans six mois, quand quelqu'un demande « pourquoi pas X ? ».
