# Matrice de recette R-01 → R-15

Plan de recette de [spec-fonctionnelle.md §F8](spec-fonctionnelle.md). Chaque
critère renvoie au(x) test(s) automatisé(s) qui le prouvent, au format
`fichier::méthode`. Les tests ajoutés pour fermer la recette sont dans
`tests/Integration/RecetteCriteriaTest.php` (une méthode `rNN_…` par critère
qu'aucun test existant ne prouvait seul), sur l'horloge injectée (ADR-0087) et
le simulateur de paiement (ADR-0066).

Statuts : **couvert** (prouvé par des tests qui existaient déjà), **couvert
(nouveau)** (preuve écrite pour la recette), **hors périmètre actuel**.

| # | Critère | Test(s) qui le prouvent | Statut |
|---|---|---|---|
| R-01 | Un membre de l'organisation A n'accède à aucune donnée de l'organisation B (404 partout). | `tests/Architecture/Idor/ConsoleIdorTest.php::an_organization_owner_of_a_gets_nothing_of_organization_b` (un cas généré par route de console porteuse d'identifiant) ; `tests/Architecture/IdorGuardTest.php::every_console_route_carrying_an_identifier_is_in_the_manifest` (aucune route oubliée) ; `tests/Feature/Admin/OrganizationMembersTest.php::an_account_of_organization_a_gets_404_on_every_member_route_of_organization_b` ; côté API, un projet ne voit pas les données d'un autre : `packages/billing/tests/Integration/InvoiceFlowTest.php::the_invoice_of_another_project_is_not_found`, `packages/billing/tests/Integration/BillingFlowTest.php::the_contract_of_another_project_is_not_found`, `packages/billing/tests/Integration/UsageFlowTest.php::the_entitlements_of_another_projects_customer_are_not_found`, `tests/Invariants/LedgerProjectIsolationTest.php::an_entry_can_never_cross_projects` | couvert |
| R-02 | Une clé sans portée `payouts:write` ne peut pas verser. | — | hors périmètre actuel (module payout non livré, lot 19). Seul le mécanisme générique de portée existe (`tests/Integration/ScopedApiKeysHttpTest.php::a_key_only_does_what_its_scopes_allow`) ; la portée `payouts:write` est déclarée dans `ApiKeyScopes` mais aucune route ne l'exige. À écrire avec le module. |
| R-03 | Le parcours F4.2 se déroule sur horloge de test, webhooks reçus dans l'ordre logique ou rejoués sans effet. | `tests/Integration/RecetteCriteriaTest.php::r03_the_trial_journey_runs_on_the_test_clock_and_replayed_events_change_nothing` (essai de 14 jours, facture à J−7, paiement, callback rejoué sans écriture ni événement en double, passage `active`, ordre de livraison des webhooks) ; en complément `packages/payment/tests/Integration/Application/PaymentToWalletIntegrationTest.php::a_replayed_success_books_the_settlement_only_once` | couvert (nouveau). **Écart** : `subscription.trial_will_end` (J−3, F4.2 étape 3, ADR-0072 §9) n'est émis par aucun code ; le test ne l'exige donc pas. Voir « Écarts relevés ». |
| R-04 | Paiement anticipé (F4.3) : aucune période perdue, aucun chevauchement. | `tests/Integration/RecetteCriteriaTest.php::r04_an_advance_payment_loses_no_period_and_overlaps_none` ; en complément `packages/billing/tests/Integration/BillingReviewFixesTest.php::the_scheduler_prepares_the_next_period_and_rolls_over` | couvert (nouveau) |
| R-05 | Impayé puis grâce puis expiration (F4.3) aux dates attendues. | `tests/Integration/SaasLifecycleFlowTest.php::an_unpaid_renewal_runs_through_grace_then_expiry_and_the_customer_comes_back` ; `tests/Integration/SaasLifecycleFlowTest.php::a_renewal_paid_inside_the_grace_keeps_the_customer_and_never_expires` ; bornes à la minute près : `tests/Integration/RecetteCriteriaTest.php::r05_unpaid_then_grace_then_expiry_fall_exactly_on_their_dates` | couvert |
| R-06 | Encaissement hors ligne au-dessus du seuil : n'écrit rien au grand livre tant qu'il n'est pas validé (libellé aligné sur ADR-0095, 2026-10-29). | Au-dessus du seuil rien n'est écrit tant qu'on n'a pas validé : `packages/billing/tests/Integration/ManualReceiptFlowTest.php::a_receipt_above_the_threshold_waits_for_a_validation_and_books_nothing`. Le refus de l'auto-validation tient pour les exceptions d'argent (`tests/Feature/Admin/MoneyExceptionsWebFlowTest.php::above_the_threshold_a_second_finance_operator_approves_and_the_ledger_is_written_once`) et les corrections du grand livre (`packages/ledger/tests/Integration/LedgerAdjustmentsTest.php::at_or_above_the_threshold_a_second_pair_of_eyes_is_needed`). | couvert, **avec écart de formulation** : pour l'encaissement hors ligne, ADR-0095 a retiré la règle « l'auteur ne valide pas sa saisie » (un compte suffit, auteur compris : `ManualReceiptFlowTest::the_account_that_recorded_the_receipt_may_validate_it`). Le critère R-06 tel que rédigé est donc contredit par l'ADR ratifié. Voir « Écarts relevés ». |
| R-07 | Limite d'usage 1, 50 requêtes simultanées : une seule admise ; même clé rejouée : comptée une fois. | `packages/billing/tests/Integration/UsageFlowTest.php::fifty_distinct_calls_against_a_limit_of_one_admit_exactly_one` ; `packages/billing/tests/Integration/UsageFlowTest.php::a_replayed_key_counts_once_and_answers_the_same_decision` ; concurrence réelle (50 processus PHP) : `tools/checks/usage-concurrency.sh` (un test PHPUnit tourne dans une seule transaction et ne peut pas la prouver) | couvert |
| R-08 | Notification refusée par quota : aucune notification créée, aucun appel prestataire. | `tests/Integration/NotificationQuotaHttpFlowTest.php::a_message_beyond_the_plan_is_refused_with_no_row_and_no_provider_call` ; `tests/Integration/NotificationQuotaHttpFlowTest.php::a_replayed_message_counts_once` | couvert |
| R-09 | Montant confirmé différent : aucune imputation, exception ouverte, grand livre équilibré. | `tests/Integration/RecetteCriteriaTest.php::r09_a_different_confirmed_amount_imputes_nothing_opens_an_exception_and_keeps_the_ledger_balanced` (de bout en bout : facture ouverte, créance intacte, exception `high`, argent au compte `payment_exceptions`, grand livre équilibré) ; unitaires : `packages/billing/tests/Integration/InvoicePaymentFlowTest.php::a_partial_settlement_is_never_imputed`, `packages/payment/tests/Integration/Application/PaymentToWalletIntegrationTest.php::a_confirmed_amount_mismatch_credits_no_wallet_and_books_the_confirmed_amount_as_an_exception` | couvert (nouveau) |
| R-10 | Tout paiement réussi a son écriture ; balance de vérification nulle par projet et devise. | `tests/Integration/RecetteCriteriaTest.php::r10_every_successful_payment_has_its_entry_and_the_trial_balance_is_nil_per_project_and_currency` (deux projets, XAF et USD, paiements réussis, échoué et annulé) ; `tests/Integration/PaymentInvariantsCheckTest.php::a_succeeded_payment_without_settlement_opens_one_critical_exception` ; `tests/Invariants/LedgerInvariantsTest.php::every_transaction_balances_to_zero` ; `packages/ledger/tests/Integration/LedgerReadModelTest.php::the_trial_balance_nets_to_zero_per_currency_and_stays_inside_the_project` | couvert (nouveau) |
| R-11 | Portail : lien réutilisé ou expiré refusé ; URL de retour hors liste refusée ; client A ne voit pas les factures de B. | `tests/Integration/PortalFlowTest.php::the_link_works_once_then_the_cookie_opens_the_home_with_strict_headers` (usage unique) ; `tests/Integration/PortalFlowTest.php::an_expired_link_and_a_garbage_link_both_fail` ; `tests/Integration/PortalFlowTest.php::a_return_url_outside_the_projects_list_is_refused` ; `tests/Integration/PortalFlowTest.php::a_customer_sees_its_own_invoice_and_a_neighbours_is_not_found` | couvert |
| R-12 | Versement en délai dépassé : reste `pending`, aucune contre-passation ; la consultation tranche. | — | hors périmètre actuel (module payout non livré, lot 19). À écrire avec le module. |
| R-13 | Effacement d'un client : aucune donnée personnelle restante, grand livre inchangé. | `tests/Integration/RecetteCriteriaTest.php::r13_erasing_a_customer_leaves_no_personal_data_and_the_ledger_unchanged` (balayage de toutes les tables, abonnement annulé, portail fermé, empreinte du grand livre et factures identiques) ; en complément `packages/billing/tests/Integration/BillingFlowTest.php::erasing_a_customer_destroys_its_identity_and_keeps_its_row`, `tests/Integration/PortalFlowTest.php::an_erased_customer_loses_its_open_session_at_once` | couvert (nouveau) — deux anomalies de produit corrigées en le rédigeant (voir ci-dessous). Le snapshot figé d'une facture **émise** survit à l'effacement, chiffré (ADR-0094 §7). |
| R-14 | Projet suspendu : `/v1` refusé, callbacks prestataires toujours traités. | `tests/Integration/RecetteCriteriaTest.php::r14_a_suspended_project_refuses_v1_but_provider_callbacks_are_still_processed` (lectures et écritures `/v1` en 403 `project-suspended` ; callback MyCoolPay signé traité, paiement réussi, écriture au grand livre) ; en complément (unitaire) `packages/identity/tests/Unit/Application/ResolveProjectFromBearerTokenTest.php` (« rejects a valid key belonging to an inactive project ») | couvert (nouveau) |
| R-15 | Recette bout-en-bout avec une application réelle : tenant → client → abonnement → notification avec quota → paiement → portail → webhook → accès ouvert puis fermé à l'expiration. | `tests/Integration/SaasRecetteEndToEndTest.php::a_saas_goes_from_first_call_to_paid_subscription_to_quota_limit` (appel unique, paiement portail, webhooks, quota, résiliation) ; `tests/Integration/SaasLifecycleFlowTest.php::an_unpaid_renewal_runs_through_grace_then_expiry_and_the_customer_comes_back` (accès fermé à l'expiration, retour du client) ; quota de notification : `tests/Integration/NotificationQuotaHttpFlowTest.php::a_message_beyond_the_plan_is_refused_with_no_row_and_no_provider_call`. La recette sur l'application réelle (staging) reste à jouer à la main avec le kit `docs/integration/kit-laravel/INSTALL.md`. | couvert (automatisé) ; passage sur staging à tenir |

## Bilan

- 13 critères couverts par des tests automatisés (7 déjà prouvés — R-01, R-05, R-06
  avec écart, R-07, R-08, R-11, R-15 — et 6 prouvés par `RecetteCriteriaTest` :
  R-03, R-04, R-09, R-10, R-13, R-14 ; R-05 y reçoit en plus ses bornes à la minute).
- 2 critères hors périmètre actuel : R-02 et R-12 (module payout non livré, lot 19).

## Écarts relevés en rédigeant la recette

Corrigés dans le code :

1. **Effacement d'un client sans annulation de ses abonnements** (F4.8,
   ADR-0083 §6.3) : `EraseCustomerUseCase` ne touchait que la fiche ; le contrat
   restait `active` et l'ordonnanceur continuait à émettre des factures pour une
   personne effacée. Il annule maintenant les contrats non finaux et publie
   `subscription.canceled` dans la même transaction
   (`packages/billing/src/Application/UseCase/EraseCustomerUseCase.php`).
2. **Données personnelles en clair dans la chronologie d'un paiement** : l'entrée
   `Initiated` de `payment_timeline_entries.details` stockait nom, e-mail et
   téléphone du payeur ; l'effacement ne pouvait pas les atteindre
   (`packages/payment/src/Application/UseCase/StartPaymentUseCase.php`). La
   chronologie ne garde plus que « fourni / non fourni » et la langue.

Non corrigés (à trancher) :

3. **`subscription.trial_will_end` jamais émis.** Spécifié (spec F4.2 étape 3,
   ADR-0072 §9, spec technique) mais ni publié par l'ordonnanceur ni listé dans
   `WebhookEventTypes`. L'émission exige un marqueur d'idempotence par contrat
   (le passage tourne toutes les 5 minutes) et l'ajout au catalogue d'événements
   et à l'OpenAPI : pas un correctif minimal.
4. **R-06 contredit ADR-0095** pour l'encaissement hors ligne (l'auteur peut
   valider sa saisie). Soit le critère est réécrit (« au-dessus du seuil, rien
   n'est écrit tant qu'un compte n'a pas validé »), soit l'ADR est amendé.
5. **`retain_invoice_identity = false` n'a aucun effet** : l'effacement ne vide pas
   les factures brouillon (ADR-0094 §7) : `EraseCustomerUseCase` ne touche à aucune
   facture, et le réglage n'est lu nulle part. Non exercé par la recette.


## Mise à jour 2026-10-29

- `subscription.trial_will_end` est désormais émis une fois à J−3 (`TrialWillEndTest`, table `subscription_notices`).
- R-06 : libellé de la spécification aligné sur ADR-0095 (le défaut était dans le critère, pas dans le code).
- `billing_settings.retain_invoice_identity` reste sans effet : à décider avec le lot de conformité (ADR-0094 §7).

## Recette par navigateur

La session de bout en bout menée dans un navigateur, depuis la console de toute la plateforme, est dans
[recette-navigateur.md](recette-navigateur.md) (26 scénarios, 17 défauts trouvés et corrigés).
