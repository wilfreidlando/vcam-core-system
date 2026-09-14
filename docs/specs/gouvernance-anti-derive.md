> Conversion Markdown de [`source/Gouvernance_Anti_Derive_Core_System.docx`](source/Gouvernance_Anti_Derive_Core_System.docx), générée par `tools/docs/docx_to_markdown.py`.
> En cas d'écart, le document Word fait foi ; régénérer après toute mise à jour.

# Enterprise Core System — Conception : gouvernance d’architecture & garde-fous anti-dérive

Architecture / Conseil logiciel

Version 1.0 — Septembre 2026

## 1. Objet du document

Ce document décrit la conception des garde-fous qui empêchent l’Enterprise Core System de dériver au fil du temps. Il ne parle pas de code : il définit quels mécanismes structurels, automatisés et humains maintiennent le Core dans son rôle — un socle de capacités transversales génériques — et l’empêchent de devenir un monolithe métier fourre-tout, couplé aux produits et impossible à maintenir.

La dérive n’arrive jamais d’un coup. Elle résulte de dizaines de petites concessions individuellement « raisonnables » : un champ métier ajouté « juste pour ce produit », un accès direct à une table « parce que c’est plus rapide », un statut provider laissé tel quel « pour gagner du temps ». Chacune paraît anodine ; leur cumul détruit le socle. Les garde-fous existent pour rendre ces concessions visibles, coûteuses et décidées, jamais silencieuses.

## 2. Les trois lignes de défense

Chaque garde-fou appartient à l’une de trois catégories, par ordre de robustesse décroissante :

| Ligne | Nature | Force | Faiblesse |
| --- | --- | --- | --- |
| Design | L’architecture rend la dérive difficile par construction. | Structurelle, permanente. | Nécessite une conception initiale correcte. |
| Outillage | La CI échoue automatiquement en cas de dérive. | Impossible à oublier. | Ne couvre que ce qui est mesurable. |
| Processus | Le comité et les revues tranchent ce qui reste humain. | Couvre le jugement. | Dépend de la discipline ; peut être contourné sous pression. |

Règle directrice : préférer toujours l’automatique au structurel, et le structurel à l’humain. Un garde-fou qu’on peut oublier finit par être oublié. On n’utilise le processus que pour ce qui ne peut être ni structuré ni automatisé.

## 3. Taxonomie des dérives

Sept modes de dérive à contrer. Chacun a des symptômes reconnaissables et un ou plusieurs garde-fous dédiés.

| Code | Dérive | Symptôme typique |
| --- | --- | --- |
| D1 | Périmètre (fourre-tout) | Le Core contient des règles métier propres à un produit. |
| D2 | Couplage | Un produit lit directement une table du Core ou dépend de ses internals. |
| D3 | Données (explosion / PII) | La base du Core grossit avec des utilisateurs et des données personnelles dupliqués. |
| D4 | Provider | Des spécificités d’un provider fuitent dans le cœur (statuts bruts, formats). |
| D5 | Contrat | Des changements cassants d’API non maîtrisés ; des versions qui divergent. |
| D6 | Intégrité financière | Des soldes incohérents, des écritures modifiables, des montants en flottant. |
| D7 | Organisationnelle | Le Core sans propriétaire clair ; décisions ad hoc ; connaissance tribale. |

## 4. Garde-fou contre D1 — Périmètre : les critères d’entrée dans le Core

Ligne : processus + design.

Le garde-fou central. Une capacité ne rejoint le Core que si elle passe les quatre portes, dans l’ordre. Un seul « Non » la maintient dans le produit.

1. Transverse ? — Au moins deux produits en ont un besoin réel (pas hypothétique). Sinon, la capacité reste dans le produit qui la demande.
1. Générique ? — Elle ne contient aucune règle métier spécifique à un produit. Si l’implémentation doit « connaître » qu’il s’agit d’un hôtel, d’une école ou d’un catalogue particulier, elle n’entre pas.
1. Gain > coût ? — La centralisation apporte un gain net (sécurité, réutilisation, conformité, réduction d’intégrations) supérieur au coût de maintenance et de coordination.
1. Contractualisable ? — Elle peut être exposée par un contrat stable (API/événements) sans fuite d’implémentation. Si l’exposer proprement est impossible, le découpage est à revoir.

### 4.1 Grille d’évaluation pour les cas limites

Pour les demandes ambiguës, une note est attribuée (chaque critère 0 à 3) ; en dessous d’un seuil, la capacité est différée ou reste dans le produit.

| Critère | 0 | 3 |
| --- | --- | --- |
| Transversalité | 1 seul produit | ≥ 3 produits |
| Généricité | Fortement spécifique | Totalement générique |
| Valeur (sécurité/conformité) | Marginale | Critique et transverse |
| Contractualisabilité | Fuite inévitable | Contrat net et stable |
| Coût de maintenance | Élevé et incertain | Faible et prévisible |

### 4.2 Liste négative vivante

La liste « ce qui n’entre PAS dans le Core » (gestion de chambres d’hôtel, gestion pédagogique, catalogues métier, workflows propres à un client, pricing produit non transverse, UI métier…) est un document vivant, enrichi à chaque décision de refus. Elle sert de jurisprudence : un refus passé argumente les refus futurs.

## 5. Garde-fou contre D2 — Couplage : frontières vérifiées

Ligne : outillage + design.

- Aucun accès direct aux bases. Un produit ne lit jamais une table du Core ; il passe par API ou événements. C’est une règle de design absolue.
- Aucune dépendance inter-modules hors interfaces. Un module n’importe que les Contracts d’un autre, jamais ses modèles ou ses tables. Vérifié par Deptrac en CI : une dépendance non déclarée fait échouer le build.
- Le Ledger ne dépend de rien. Le cœur financier est en bas de la pile ; aucun module ne lui impose de dépendance. Règle Deptrac la plus stricte.
- Corrélation, pas fusion. Les données de plusieurs contextes se relient par identifiants de corrélation (external_ref, correlation_id), jamais par jointure entre bases.

## 6. Garde-fou contre D3 — Données : anti-explosion et anti-PII

Ligne : design + outillage.

C’est le garde-fou qui répond à la crainte d’une base démesurée.

- Les end-users restent dans les produits. Le Core ne stocke jamais l’identité complète d’un utilisateur final.
- Party léger, créé lazy. Le Core ne matérialise un Party (référence external_ref, type, sans PII) qu’au premier besoin réel (wallet, versement, bénéficiaire). Une plateforme à 500 000 utilisateurs dont 3 000 transactent ne crée que 3 000 Parties.
- Fitness function anti-PII. Un test de CI inspecte le schéma de la table parties : la présence de colonnes interdites (email, nom, mot de passe, téléphone…) fait échouer le build. La contrainte devient impossible à violer par inadvertance.
- Métadonnées minimales. Le champ metadata n’accueille que le strictement nécessaire, jamais un miroir du profil produit.

## 7. Garde-fou contre D4 — Provider : anti-corruption

Ligne : design + outillage.

- Ports & Adapters. Le cœur ne connaît que des interfaces (PaymentProvider, NotificationChannelProvider). Chaque provider est un adaptateur isolé.
- Statuts canoniques. Chaque adaptateur traduit les statuts bruts du provider vers un ensemble canonique unique. Le cœur ne manipule que le canonique ; le brut est conservé pour l’audit, cantonné à l’adaptateur.
- Fitness function anti-fuite. Un test vérifie que les chaînes de statut spécifiques à un provider n’apparaissent nulle part hors de son adaptateur. Si un statut brut fuit dans un service métier, la CI échoue.
- Conséquence. Changer ou ajouter un provider = un adaptateur + une config. Aucune application consommatrice, aucun module métier ne bouge.

## 8. Garde-fou contre D5 — Contrat : discipline de versionnement

Ligne : outillage + processus.

- Contract-first. Les API sont décrites en OpenAPI ; le contrat est la frontière, pas l’implémentation.
- Détection automatique des ruptures. Un diff de contrat en CI détecte tout changement cassant ; sans montée de version explicite, le build échoue.
- Politique de dépréciation. Une version dépréciée reste servie pendant une fenêtre de préavis annoncée ; on ne casse jamais un consommateur sans préavis.
- Tests de contrat (Pact). Les attentes des consommateurs sont testées contre le Core : une régression de compatibilité est détectée avant déploiement.
- Validation comité. Toute évolution de contrat public passe par le comité d’architecture.

## 9. Garde-fou contre D6 — Intégrité financière

Ligne : design + outillage.

- Ledger immuable. Aucune écriture n’est modifiée ni supprimée ; une correction est une écriture compensatoire. Règle de design non négociable.
- Invariants testés. Des tests de CI vérifient en permanence : somme des écritures d’une transaction = 0 ; solde matérialisé = somme dérivée des écritures ; aucun solde négatif non voulu sous concurrence.
- Fitness function anti-flottant. Un test inspecte les migrations : toute colonne monétaire déclarée en float/double (au lieu de bigint minor units) fait échouer le build.
- Idempotence obligatoire. Un test vérifie que chaque endpoint de mutation monétaire exige une clé d’idempotence.
- Émission via outbox. Un test d’architecture garantit qu’aucun événement financier n’est émis hors de la transaction (pas d’incohérence état/événement).

## 10. Garde-fou contre D7 — Organisationnelle : le Core comme produit

Ligne : processus.

- Le Core est un produit, pas un projet. Il a un propriétaire (product/plateforme), un ownership technique clair, une roadmap indépendante des roadmaps métier.
- Comité d’architecture. Instance légère qui détient les droits de décision structurants (voir §11).
- ADR/RFC systématiques. Toute décision structurante est tracée (voir §12), ce qui combat la connaissance tribale.
- SLA interne. L’équipe plateforme s’engage sur des délais de traitement des demandes produit, sans quoi les équipes contournent le Core et l’investissement est perdu.
- Catalogue des capacités documenté et maintenu, pour que chaque équipe sache ce qui existe déjà — évitant la reconstruction et la duplication.

## 11. Le comité d’architecture

Composition (léger) : le tech lead/architecte plateforme, un pair technique, et un représentant produit tournant selon le sujet.

Cadence : rituel court toutes les deux semaines, plus des saisines à la demande pour les décisions bloquantes.

Droits de décision (ce que le comité, et lui seul, tranche) :

- l’entrée d’une capacité ou d’un domaine dans le Core (via les quatre portes) ;
- toute évolution d’un contrat d’API public ;
- l’octroi d’une exception temporaire (voir §13), toujours datée ;
- l’arbitrage des conflits de priorité entre équipes consommatrices.
RACI pour l’entrée d’un domaine dans le Core :

| Acteur | Rôle |
| --- | --- |
| Équipe demandeuse | Responsable de la demande et du dossier. |
| Comité d’architecture | Décideur (accountable). |
| Propriétaire plateforme | Consulté. |
| Équipes consommatrices | Informées. |

## 12. Processus ADR / RFC

- Toute décision structurante (entrée dans le Core, choix de contrat, exception, changement de règle transverse) fait l’objet d’un ADR court : contexte, décision, alternatives, conséquences.
- Les ADR sont versionnés avec le code, consultables par toutes les équipes.
- Un ADR de refus est aussi précieux qu’un ADR d’acceptation : il alimente la « liste négative vivante » (§4.2) et sert de jurisprudence.

## 13. Processus d’exception

La rigidité absolue pousse au contournement. Le garde-fou prévoit donc une soupape maîtrisée.

Quand un produit a un besoin réel qui ne passe pas les portes :

1. Préférer un point d’extension côté produit plutôt qu’une modification du Core : calcul côté produit, métadonnées, réaction à un événement, webhook. Le Core fournit la primitive ; le produit porte sa spécificité.
1. Si une exception dans le Core est inévitable, elle est datée, tracée par ADR, et assortie d’une date de revue. Une exception n’est jamais permanente par défaut.
1. Le nombre d’exceptions actives est un indicateur suivi : il doit tendre vers zéro. Une exception qui perdure signale soit un manque de primitive générique (à créer proprement), soit une dérive à corriger.

## 14. Fitness functions : les garde-fous automatisés

Récapitulatif des contrôles de CI qui échouent le build en cas de dérive. Ce sont les garde-fous les plus robustes car impossibles à oublier.

| Contrôle automatisé | Dérive contrée |
| --- | --- |
| Deptrac : aucune dépendance inter-modules non déclarée | D2 |
| Deptrac : le Ledger ne dépend d’aucun module | D2 / D6 |
| Un module n’importe que les Contracts d’un autre | D2 |
| Schéma parties : colonnes PII interdites → échec | D3 |
| Migrations : colonne monétaire non-bigint → échec | D6 |
| Statut brut provider hors adaptateur → échec | D4 |
| Endpoint monétaire sans Idempotency-Key → échec | D6 |
| Événement émis hors outbox/transaction → échec | D6 |
| Diff OpenAPI : rupture sans montée de version → échec | D5 |
| Tests de contrat (Pact) consommateurs → échec si régression | D5 |
| Invariants ledger (somme = 0, solde dérivé) → échec | D6 |

## 15. Indicateurs de dérive et cadence de revue

Suivis en continu ; toute tendance défavorable déclenche une revue.

- Dette de frontières (violations Deptrac) : doit rester à zéro.
- Colonnes PII dans parties : doit rester à zéro.
- Nombre d’exceptions actives : doit tendre vers zéro.
- Nombre de capacités dans le Core vs valeur démontrée : croissance justifiée, pas mécanique.
- Versions de contrat en vol : maîtrisées, avec dates de dépréciation.
- Écarts de réconciliation financière : doivent tendre vers zéro.
- Taux d’accès au catalogue des capacités : signe que les équipes réutilisent au lieu de reconstruire.
Une revue d’architecture périodique (trimestrielle) examine ces indicateurs, la liste négative, et les exceptions arrivant à échéance.

## 16. Synthèse

| Dérive | Garde-fou principal | Ligne de défense |
| --- | --- | --- |
| D1 Périmètre | Quatre portes d’entrée + liste négative vivante + comité | Processus / design |
| D2 Couplage | API/events only, no shared DB, Deptrac | Outillage / design |
| D3 Données | Party lazy sans PII + fitness function anti-PII | Design / outillage |
| D4 Provider | Ports & Adapters + statuts canoniques + anti-fuite | Design / outillage |
| D5 Contrat | Contract-first, diff automatique, Pact, dépréciation | Outillage / processus |
| D6 Intégrité financière | Ledger immuable + invariants + anti-flottant + idempotence | Design / outillage |
| D7 Organisationnelle | Core comme produit, comité, ADR, SLA interne | Processus |

Le fil conducteur : rendre la dérive visible, coûteuse et décidée, jamais silencieuse. Ce qui peut être automatisé l’est (la CI refuse la dérive) ; ce qui est structurel est posé dès la conception (l’architecture décourage la dérive) ; ce qui reste humain est tranché par un comité outillé d’une jurisprudence (le processus arbitre la dérive). C’est cette combinaison, et non un règlement unique, qui garde le socle sain sur la durée.

Fin du document — Version 1.0
