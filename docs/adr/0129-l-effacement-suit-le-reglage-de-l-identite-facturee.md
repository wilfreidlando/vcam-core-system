# ADR-0129 — L'effacement suit le réglage de l'identité facturée

- **Statut** : Accepté
- **Date** : 2026-11-19
- **Décideurs** : commanditaire (« fait ce qui doit être fait »), comité d'architecture
- **Dérives concernées** : D3 données · D5 contrat
- **Précise** : ADR-0094 §7 (l'effacement et le snapshot figé), ADR-0083 §6.2 (la conservation des données personnelles)
- **Amende** : ADR-0119 §1 (les deux réglages que la console n'édite pas)

## Contexte

`billing_settings.retain_invoice_identity` existe depuis ADR-0094 §4 : il
est dans le schéma, il a un défaut (`true`), et ADR-0094 §7 lui donne un rôle
précis — « `true` (le défaut, conforme à la spécification technique) conserve
les factures brouillon du client, `false` les vide à l'effacement ».
ADR-0083 §6.2 dit la même chose.

Le code, lui, ne le faisait pas. L'écart a été relevé par la recette
(§5 des « écarts relevés » de `docs/specs/recette-matrice.md`) :
**« `retain_invoice_identity = false` n'a aucun effet : l'effacement ne vide
pas les factures brouillon. `EraseCustomerUseCase` ne touche à aucune
facture, et le réglage n'est lu nulle part. »** C'était exact : le port
`InvoiceRepository::clearDraftSnapshots()` existait, son adaptateur Eloquent
existait, **aucun appelant ne l'appelait**.

Un réglage qui n'agit pas est pire qu'un réglage absent : le projet croit
avoir choisi, la fiche client disparaît, et le nom du client reste figé sur
ses factures brouillon. Le choix était écrit dans le schéma, il était
juste mort.

Le réglage était mort **deux fois** : rien ne le lisait, et rien ne pouvait
l'écrire. La console est le seul écrivain des réglages de commerce (la route
`admin.projects.commerce-settings` ; il n'existe pas de route `/v1` pour
eux), et la case à cocher n'existait pas. Même en branchant le code, le
projet n'aurait eu aucun moyen de faire le choix.

## Décision

**Décision 1 — l'effacement respecte le réglage.**

`EraseCustomerUseCase` lit `billing_settings.retain_invoice_identity` du
projet, dans la transaction de l'effacement :

- `true` (le défaut) — les factures **brouillon** du client gardent leur
  identité figée ;
- `false` — l'effacement **vide** l'identité figée des factures brouillon du
  client (le snapshot passe à `{}`).

Une facture **émise** n'est pas concernée : c'est un document, son snapshot
est gelé par la base (le trigger `invoice_issued_immutable`, ADR-0073 §1) et
la créance subsiste. L'option n'ouvre pas la porte à une réécriture d'un
document émis — ADR-0094 §7.

**Décision 2 — la fiche client, elle, disparaît toujours.**

Ce que le règle est le **snapshot figé sur les factures brouillon**, pas la
fiche client : l'e-mail, le téléphone et le nom du client effacé disparaissent
dans tous les cas (ADR-0070 §2, ADR-0083). Le réglage ne sert qu'à la
décision **documentaire** du projet : « mes brouillons sont des documents
que je garde, même pour un client effacé » ou « mes brouillons ne portent
plus son nom ».

**Décision 3 — la console expose le choix, et le porte comme un `null`.**

La case à cocher « Conserver le nom figé du client sur ses factures brouillon »
vient dans les réglages du commerce. La commande
(`SaveBillingSettingsCommand`) porte `?bool $retainInvoiceIdentity` : `null`
laisse le choix tel quel, comme le catalogue de packs (ADR-0119 §1). Une
autre edition des réglages ne peut donc pas éteindre le choix par accident.

**Décision 4 — le défaut ne change pas.**

`true` reste le défaut : c'est celui de la spécification technique, et c'est
ce qui laisse à l'effacement son sens usuel — le client disparaît, ses
documents restent. Changer le défaut en base serait une décision de
migration, pas une décision de code ; elle n'est pas prise ici.

## Alternatives écartées

- **Supprimer le réglage** — il est dans le schéma, dans ADR-0094 §4, et la
  spécification technique le prévoit : l'enlever pour faire taire l'écart
  aurait supprimé une capacité, pas une incohérence.
- **Vider toujours les snapshots des brouillons** — contredit le défaut de la
  spécification technique et ADR-0094 §7 ; et un brouillon n'est pas un
  document : le projet peut vouloir le garder.
- **Exposer le réglage dans l'API publique `/v1`** — il n'existe pas de route
  `/v1` pour les réglages de commerce, et en créer une serait une capacité
  nouvelle (le choix du projet sur ses données personnelles mérite la
  console, où l'engagement est visible et audité).
- **Laisser le réglage mort et le signaler** — c'est ce que la recette a
  fait ; l'écart reste ouvert jusqu'à ce qu'il soit traité.

## Conséquences

- L'écart de recette est fermé : le réglage agit, et il est vérifié par un
  test.
- La console est le seul endroit où le choix se fait : c'est coherent avec
  le fait qu'elle est le seul écrivain de ces réglages.
- Un client effacé peut laisser son nom sur ses brouillons : c'est un choix
  du projet, pas un oubli du Core, et il est audité
  (`billing.settings_changed`, avant et après).
- Le port `InvoiceRepository::clearDraftSnapshots()` n'est plus une méthode
  morte : elle a un appelant, et le trigger d'immuabilité protège ce qu'elle
  ne touche pas.

## Vérification

- `packages/billing/tests/Integration/BillingFlowTest.php`
  — `the_erasure_empties_the_draft_invoices_when_the_project_renounces_to_keep_the_identity` :
  `retain_invoice_identity = false` vide le snapshot des factures brouillon du
  client effacé ; une facture **émise** garde le sien.
- `packages/billing/tests/Integration/BillingFlowTest.php`
  — `the_draft_invoices_keep_their_identity_when_the_project_keeps_it` :
  `retain_invoice_identity = true` (le défaut) laisse le snapshot en place.
- `tests/Feature/Admin/ProjectCommerceConsoleTest.php`
  — la case à cocher de la console écrit le réglage, une edition qui ne le
  porte pas le laisse tel quel, et le journal de la console garde l'avant et
  l'après.
