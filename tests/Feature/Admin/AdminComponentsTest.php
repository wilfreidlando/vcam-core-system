<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use Tests\TestCase;

/**
 * Le design system de la console (resources/views/components/admin/*) : chaque composant est rendu
 * seul, avec et sans erreur, et ses attributs ARIA sont vérifiés — c'est la cause racine de 119 écarts
 * d'accessibilité (annexe-accessibilite-2026-10-29, chantiers 1 à 3).
 */
final class AdminComponentsTest extends TestCase
{
    // ---------------------------------------------------------------- field

    public function test_field_links_label_input_and_hint_without_error(): void
    {
        $html = $this->render('<x-admin.field name="email" label="Adresse e-mail" type="email" hint="Elle sert à vous inviter." autocomplete="email" required />');

        self::assertStringContainsString('<label for="field-email"', $html);
        $input = $this->tagContaining($html, 'id="field-email"');
        self::assertStringContainsString('type="email"', $input);
        self::assertStringContainsString('name="email"', $input);
        self::assertStringContainsString('required', $input);
        self::assertStringContainsString('autocomplete="email"', $input);
        self::assertStringContainsString('aria-describedby="field-email-hint"', $input);
        self::assertStringNotContainsString('aria-invalid', $input);
        self::assertStringContainsString('id="field-email-hint"', $html);
        self::assertStringNotContainsString('field-email-error', $html);
    }

    public function test_field_error_is_linked_and_marks_the_input_invalid(): void
    {
        $this->withViewErrors(['email' => 'Adresse invalide.']);
        $html = $this->render('<x-admin.field name="email" label="Adresse e-mail" hint="Un indice" />');

        $input = $this->tagContaining($html, 'id="field-email"');
        self::assertStringContainsString('aria-invalid="true"', $input);
        self::assertStringContainsString('aria-describedby="field-email-hint field-email-error"', $input);
        self::assertStringContainsString('id="field-email-error"', $html);
        self::assertStringContainsString('Adresse invalide.', $html);
        self::assertStringContainsString('border-rose-600', $input);
    }

    public function test_field_input_always_carries_the_border_class(): void
    {
        // B26 : sans la classe `border`, le preflight Tailwind force border-width:0.
        foreach (['', ' type="textarea"', ' type="select" :options="[\'a\' => \'A\']"'] as $extra) {
            $html = $this->render('<x-admin.field name="x" label="X"'.$extra.' />');
            $control = $this->tagContaining($html, 'id="field-x"');
            self::assertMatchesRegularExpression('/class="[^"]*(?<![-\w])border(?![-\w])[^"]*"/', $control, $control);
            self::assertStringContainsString('border-slate-300', $control);
        }
    }

    public function test_field_old_input_wins_over_the_model_value(): void
    {
        // Défaut #22 : le modèle écrasait la saisie précédente.
        $this->putOldInput(['name' => 'Saisie rejetée']);
        $html = $this->render('<x-admin.field name="name" label="Nom" value="Valeur du modèle" />');

        self::assertStringContainsString('value="Saisie rejetée"', $html);
        self::assertStringNotContainsString('Valeur du modèle', $html);
    }

    public function test_field_falls_back_to_the_model_value_without_old_input(): void
    {
        $html = $this->render('<x-admin.field name="name" label="Nom" value="Valeur du modèle" />');

        self::assertStringContainsString('value="Valeur du modèle"', $html);
    }

    public function test_field_never_refills_a_password(): void
    {
        $this->putOldInput(['password' => 'secret']);
        $html = $this->render('<x-admin.field name="password" label="Mot de passe" type="password" value="x" autocomplete="current-password" />');

        self::assertStringNotContainsString('secret', $html);
        self::assertStringNotContainsString('value=', $this->tagContaining($html, 'id="field-password"'));
    }

    public function test_field_textarea_and_select_keep_label_and_error_wiring(): void
    {
        $this->withViewErrors(['reason' => 'Motif requis.', 'role' => 'Rôle inconnu.']);
        $this->putOldInput(['role' => 'admin']);

        $area = $this->render('<x-admin.field name="reason" label="Motif" type="textarea" required />');
        self::assertStringContainsString('<label for="field-reason"', $area);
        self::assertMatchesRegularExpression('/<textarea[^>]*id="field-reason"[^>]*aria-invalid="true"/s', $area);

        $select = $this->render('<x-admin.field name="role" label="Rôle" type="select" value="viewer" :options="[\'viewer\' => \'Lecteur\', \'admin\' => \'Admin\']" placeholder="Choisir" />');
        self::assertMatchesRegularExpression('/<select[^>]*id="field-role"[^>]*aria-invalid="true"/s', $select);
        self::assertMatchesRegularExpression('/<option value="admin"\s+selected>/', $select);
        self::assertDoesNotMatchRegularExpression('/<option value="viewer"\s+selected>/', $select);
    }

    public function test_field_slot_replaces_the_control_and_keeps_label_hint_and_error(): void
    {
        $this->withViewErrors(['plan' => 'Plan requis.']);
        $html = $this->render(<<<'BLADE'
            <x-admin.field name="plan" label="Offre" hint="Choisissez une offre" type="custom">
                <input id="field-plan" name="plan" aria-describedby="field-plan-hint field-plan-error" aria-invalid="true" class="border">
            </x-admin.field>
            BLADE);

        self::assertStringContainsString('<label for="field-plan"', $html);
        self::assertSame(1, substr_count($html, 'id="field-plan"'));
        self::assertStringContainsString('id="field-plan-hint"', $html);
        self::assertStringContainsString('id="field-plan-error"', $html);
    }

    public function test_field_optional_label_and_custom_id_and_hidden_label(): void
    {
        $html = $this->render('<x-admin.field name="note" label="Note" id="note-1" optional label-hidden />');

        self::assertStringContainsString('<label for="note-1" class="block text-sm font-medium text-slate-700 sr-only">', $html);
        self::assertStringContainsString('(facultatif)', $html);
        self::assertStringContainsString('id="note-1"', $html);
    }

    public function test_field_bracket_names_read_dotted_error_and_old_keys(): void
    {
        $this->withViewErrors(['events' => 'Choisissez au moins un événement.']);
        $html = $this->render('<x-admin.field name="events[]" label="Événements" />');

        self::assertStringContainsString('Choisissez au moins un événement.', $html);
        self::assertStringContainsString('aria-invalid="true"', $html);
    }

    // --------------------------------------------------------------- errors

    public function test_errors_summary_is_an_alert_focusable_with_a_list(): void
    {
        $this->withViewErrors(['a' => 'Premier problème.', 'b' => 'Second problème.']);
        $html = $this->render('<x-admin.errors />');

        $tag = $this->tagContaining($html, 'role="alert"');
        self::assertStringContainsString('tabindex="-1"', $tag);
        self::assertStringContainsString('id="form-errors"', $tag);
        self::assertStringContainsString('Le formulaire contient des erreurs.', $html);
        self::assertStringContainsString('<li>Premier problème.</li>', $html);
        self::assertStringContainsString('<li>Second problème.</li>', $html);
        self::assertStringContainsString('x-init="$el.focus()"', $tag);
    }

    public function test_errors_summary_accepts_explicit_messages_and_renders_nothing_when_empty(): void
    {
        $html = $this->render('<x-admin.errors :messages="[\'Échec de la révocation.\']" title="Échec" :focus="false" />');
        self::assertStringContainsString('<li>Échec de la révocation.</li>', $html);
        self::assertStringContainsString('Échec', $html);
        self::assertStringNotContainsString('x-init', $html);

        self::assertSame('', trim($this->render('<x-admin.errors />')));
    }

    // ---------------------------------------------------------------- alert

    public function test_alert_role_follows_the_variant(): void
    {
        $success = $this->render('<x-admin.alert variant="success">Invitation créée.</x-admin.alert>');
        self::assertStringContainsString('role="status"', $success);
        self::assertStringContainsString('Invitation créée.', $success);
        self::assertStringContainsString('Succès : ', $success);

        $info = $this->render('<x-admin.alert>Info</x-admin.alert>');
        self::assertStringContainsString('role="status"', $info);

        $error = $this->render('<x-admin.alert variant="error" title="Oups">Échec.</x-admin.alert>');
        self::assertStringContainsString('role="alert"', $error);
        self::assertStringNotContainsString('role="status"', $error);
        self::assertStringContainsString('Erreur : ', $error);
        self::assertStringContainsString('Oups', $error);
        self::assertStringContainsString('aria-hidden="true"', $error);
    }

    // -------------------------------------------------------- confirm-dialog

    public function test_confirm_dialog_exposes_the_dialog_pattern(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-admin.confirm-dialog id="rev" title="Révoquer ?" message="Action irréversible." action="/admin/x" method="DELETE" confirm="Révoquer">
                <x-slot:trigger><button type="button" class="btn">Révoquer</button></x-slot:trigger>
            </x-admin.confirm-dialog>
            BLADE);

        $dialog = $this->tagContaining($html, 'role="dialog"');
        self::assertStringContainsString('aria-modal="true"', $dialog);
        self::assertStringContainsString('aria-labelledby="rev-title"', $dialog);
        self::assertStringContainsString('aria-describedby="rev-desc"', $dialog);
        self::assertStringContainsString('id="rev-title"', $html);
        self::assertStringContainsString('id="rev-desc"', $html);

        $trigger = $this->tagContaining($html, 'class="btn"');
        self::assertStringContainsString('aria-haspopup="dialog"', $trigger);
        self::assertStringContainsString(':aria-expanded=', $trigger);
        self::assertStringContainsString('aria-expanded="false"', $trigger);

        self::assertStringContainsString('x-cloak', $html);
        self::assertStringContainsString('@keydown.escape.window="onEscape()"', $html);
        self::assertStringContainsString('@keydown.tab="trap($event)"', $html);
        self::assertStringContainsString('@click.self="close()"', $html);
        self::assertStringContainsString('x-ref="cancel"', $html);

        self::assertStringContainsString('action="/admin/x"', $html);
        self::assertStringContainsString('name="_token"', $html);
        self::assertStringContainsString('name="_method" value="DELETE"', $html);
        self::assertStringContainsString('>Révoquer</button>', $html);
    }

    public function test_confirm_dialog_post_has_csrf_but_no_method_spoofing_and_default_trigger(): void
    {
        $html = $this->render('<x-admin.confirm-dialog title="Émettre ?" action="/admin/emit" confirm="Émettre" variant="primary" trigger-label="Émettre la facture" />');

        self::assertStringContainsString('name="_token"', $html);
        self::assertStringNotContainsString('name="_method"', $html);
        self::assertStringNotContainsString('aria-describedby', $html);
        $trigger = $this->tagContaining($html, 'aria-haspopup="dialog"');
        self::assertStringContainsString('type="button"', $trigger);
        self::assertStringContainsString('Émettre la facture', $html);
        self::assertStringContainsString('bg-indigo-600', $html);
    }

    public function test_confirm_dialog_slot_fields_are_inside_the_form_and_get_the_initial_focus(): void
    {
        $html = $this->render(<<<'BLADE'
            <x-admin.confirm-dialog id="d" title="Annuler ?" action="/a">
                <input type="text" name="reason" required>
            </x-admin.confirm-dialog>
            BLADE);

        self::assertStringContainsString('hasFields: true', $html);
        self::assertMatchesRegularExpression('/<form[^>]*>.*data-dialog-fields[^>]*>\s*<input type="text" name="reason" required>.*<\/form>/s', $html);

        $without = $this->render('<x-admin.confirm-dialog title="T" action="/a" />');
        self::assertStringContainsString('hasFields: false', $without);
    }

    public function test_confirm_dialog_buttons_meet_the_minimum_touch_target(): void
    {
        $html = $this->render('<x-admin.confirm-dialog title="T" action="/a" />');

        self::assertSame(3, preg_match_all('/<button[^>]*py-1\.5/', $html), 'Déclencheur, Annuler et Confirmer doivent porter py-1.5.');
    }

    // ---------------------------------------------------------------- meter

    public function test_meter_is_a_native_progress_with_value_text_and_a_textual_state(): void
    {
        $ok = $this->render('<x-admin.meter label="Notifications" :used="40" :limit="100" unit="messages" />');
        $progress = $this->tagContaining($ok, 'role="progressbar"');
        self::assertStringStartsWith('<progress', $progress);
        self::assertStringContainsString('role="progressbar"', $progress);
        self::assertStringContainsString('aria-valuenow="40"', $progress);
        self::assertStringContainsString('aria-valuetext="40 sur 100 messages"', $progress);
        self::assertStringContainsString('aria-labelledby="meter-notifications"', $progress);
        self::assertStringContainsString('id="meter-notifications"', $ok);
        self::assertStringNotContainsString('Presque atteint', $ok);
        self::assertStringNotContainsString('Atteint', $ok);

        $warn = $this->render('<x-admin.meter label="Notifications" :used="85" :limit="100" />');
        self::assertStringContainsString('Presque atteint', $warn);
        self::assertStringContainsString('aria-valuetext="85 sur 100, presque atteint"', $warn);

        $full = $this->render('<x-admin.meter label="Notifications" :used="120" :limit="100" />');
        self::assertStringContainsString('>Atteint</p>', $full);
        self::assertStringContainsString('aria-valuenow="100"', $full);
    }

    public function test_meter_without_limit_is_text_only(): void
    {
        $html = $this->render('<x-admin.meter label="Appels" :used="1500" :limit="null" />');

        self::assertStringNotContainsString('<progress', $html);
        self::assertStringContainsString('illimité', $html);
        self::assertStringContainsString('1 500', $html);
    }

    // ---------------------------------------------------------------- table

    public function test_table_renders_caption_scoped_headers_and_labels_the_empty_header(): void
    {
        $html = $this->render('<x-admin.table caption="Factures du projet" :headers="[\'Numéro\', \'\']"><tr><td>F-1</td><td></td></tr></x-admin.table>');

        self::assertStringContainsString('<caption class="sr-only">Factures du projet</caption>', $html);
        self::assertSame(2, substr_count($html, 'scope="col"'));
        self::assertStringContainsString('aria-label="Actions"', $html);
        self::assertStringContainsString('overflow-x-auto', $html);
    }

    public function test_table_wraps_bare_rows_in_a_tbody_and_keeps_the_callers_tbody(): void
    {
        $bare = $this->render('<x-admin.table :headers="[\'A\']"><tr><td>1</td></tr></x-admin.table>');
        self::assertSame(1, substr_count($bare, '<tbody'));
        self::assertStringNotContainsString('<caption', $bare);

        $own = $this->render('<x-admin.table :headers="[\'A\']"><tbody x-data="{}"><tr><td>1</td></tr></tbody></x-admin.table>');
        self::assertSame(1, substr_count($own, '<tbody'));
        self::assertStringContainsString('<tbody x-data=', $own);
    }

    public function test_table_empty_state_has_a_tbody_and_a_full_width_cell(): void
    {
        $html = $this->render('<x-admin.table :headers="[\'A\', \'B\']" empty="Rien ici." />');

        self::assertStringContainsString('<tbody', $html);
        self::assertStringContainsString('colspan="2"', $html);
        self::assertStringContainsString('Rien ici.', $html);
    }

    // ----------------------------------------------------------- search-box

    public function test_search_box_has_a_label_bound_to_a_unique_input_id(): void
    {
        $html = $this->render('<x-admin.search-box placeholder="Rechercher par nom…" /><x-admin.search-box placeholder="Rechercher un projet…" name="q" id="fixed" value="abc" />');

        preg_match_all('/<label for="([^"]+)" class="sr-only">([^<]+)<\/label>/', $html, $labels, PREG_SET_ORDER);
        self::assertCount(2, $labels);
        self::assertNotSame($labels[0][1], $labels[1][1], 'Deux instances = deux ids distincts.');
        self::assertSame('Rechercher par nom', $labels[0][2]);
        self::assertSame('fixed', $labels[1][1]);
        self::assertStringContainsString('id="'.$labels[0][1].'"', $html);
        self::assertStringContainsString('name="q"', $html);
        self::assertStringContainsString('role="search"', $html);
        self::assertStringContainsString('Effacer', $html);
    }

    // ----------------------------------------------------------- pagination

    public function test_pagination_disabled_links_are_inert(): void
    {
        $first = $this->render('<x-admin.pagination :page="1" :per-page="10" :total="25" />');
        self::assertStringContainsString('aria-label="Pagination"', $first);
        self::assertMatchesRegularExpression('/<a role="link" aria-disabled="true" tabindex="-1"[^>]*>Précédent<\/a>/', $first);
        self::assertMatchesRegularExpression('/<a href="[^"]*page=2"[^>]*>Suivant<\/a>/', $first);

        $last = $this->render('<x-admin.pagination :page="3" :per-page="10" :total="25" />');
        self::assertMatchesRegularExpression('/<a role="link" aria-disabled="true" tabindex="-1"[^>]*>Suivant<\/a>/', $last);
        self::assertMatchesRegularExpression('/<a href="[^"]*page=2"[^>]*>Précédent<\/a>/', $last);
        self::assertDoesNotMatchRegularExpression('/aria-disabled="true"[^>]*href=|href=[^>]*aria-disabled="true"/', $last);
        self::assertMatchesRegularExpression('/py-2/', $last);

        self::assertSame('', trim($this->render('<x-admin.pagination :page="1" :per-page="10" :total="5" />')));
    }

    // --------------------------------------------------------- status-badge

    public function test_status_badge_always_carries_the_status_as_text(): void
    {
        $failed = $this->render('<x-admin.status-badge status="failed" :final="true" />');
        self::assertStringContainsString('>failed</span>', $failed);
        self::assertStringContainsString('bg-rose-100', $failed);
        self::assertStringContainsString('final', $failed);

        $unknown = $this->render('<x-admin.status-badge status="mystère" :final="false" />');
        self::assertStringContainsString('>mystère</span>', $unknown);
        self::assertStringContainsString('bg-slate-100', $unknown);
        self::assertStringContainsString('intermédiaire', $unknown);
    }

    private function render(string $template): string
    {
        return (string) $this->blade($template);
    }

    /** La balise ouvrante (première) qui contient $needle. */
    private function tagContaining(string $html, string $needle): string
    {
        $found = preg_match('/<[a-z][^<>]*'.preg_quote($needle, '/').'[^<>]*>/s', $html, $m);
        self::assertSame(1, $found, "Aucune balise ne contient « {$needle} ».");

        return $m[0] ?? '';
    }

    /**
     * @param array<string, mixed> $input
     */
    private function putOldInput(array $input): void
    {
        $session = app('session')->driver();
        assert($session instanceof \Illuminate\Contracts\Session\Session);
        app('request')->setLaravelSession($session);
        $session->put('_old_input', $input);
    }
}
