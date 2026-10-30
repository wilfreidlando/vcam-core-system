/**
 * The interactive components of the operator console.
 *
 * Why a file and not an inline <script> (audit 2026-10-29, ADR-0102): an
 * inline script forces `'unsafe-inline'` into the console's CSP. Served from
 * the application's own origin, this file is allowed by `script-src 'self'`.
 *
 * Why components and not `x-data="{ … }"` literals: the console runs
 * Alpine's **CSP build** (`@alpinejs/csp`, ADR-0102), which evaluates an
 * expression by reading a *property path* — `navOpen`, `all`, `label` — and
 * nothing else. It cannot evaluate an object literal (`x-data="{ open:
 * false }"`), an assignment (`@click="open = ! open"`), a ternary
 * (`:aria-expanded="open ? 'true' : 'false'"`), a call (`@click="show()"`)
 * nor `x-model` (which assigns). Every piece of state therefore lives in a
 * component registered here, and every expression of the templates is a
 * plain path.
 *
 * Two rules of that evaluator, read off its source:
 *
 *  - a **function** found on the path is called automatically, with the DOM
 *    event as its first argument: `@click="navToggle"` calls
 *    `navToggle(event)`, and `@change="toggleAll"` reads `event.target`;
 *  - `this` is bound to the object that owns the property, and only to an
 *    object **directly** in the scope stack. A state nested one level
 *    deeper (`adminShell.nav.open`) would resolve `this` to the merged
 *    scope, where `open` does not exist. That is why every component keeps
 *    its state flat, at the top level of the component.
 *
 * It must run BEFORE Alpine: the components are looked up by the very first
 * render of the drawer, and `alpine:init` fires when Alpine starts — a
 * listener registered after that event would never run. Both scripts are
 * deferred, and the document order decides.
 */
document.addEventListener('alpine:init', () => {
    /**
     * The shell of the console — the state of the mobile drawer.
     *
     * It lives on `<body>` because the hamburger (in the header) and the
     * drawer (in the sidebar) are siblings: only a common ancestor can
     * hold a state both of them read.
     */
    Alpine.data('adminShell', () => ({
        navOpen: false,
        navWide: window.innerWidth >= 768,

        init() {
            // `navSync()` closes the drawer when a resize crosses the md
            // breakpoint (768 px), and ignores the resizes that do not — an
            // on-screen keyboard or a collapsing browser bar fires `resize`
            // too and must not slam the drawer shut.
            window.addEventListener('resize', () => this.navSync());
        },

        navToggle() {
            this.navOpen = ! this.navOpen;
        },

        navClose() {
            this.navOpen = false;
        },

        navSync() {
            const wide = window.innerWidth >= 768;
            if (wide !== this.navWide) {
                this.navWide = wide;
                this.navOpen = false;
            }
        },

        get navAriaExpanded() {
            return this.navOpen ? 'true' : 'false';
        },

        get navClosed() {
            return ! this.navOpen;
        },

        get navOpenClass() {
            return this.navOpen ? 'is-open' : '';
        },
    }));

    /**
     * The confirmation dialog (WCAG 4.1.2, 2.4.3, 2.1.2, 2.5.8): focus
     * trap (Tab / Shift+Tab loop), Escape closes, a click on the backdrop
     * closes, the focus goes back to the trigger on close.
     *
     * One instance per dialog: the component is instantiated by `x-data`,
     * so each of them owns its own state.
     */
    Alpine.data('confirmDialog', () => ({
        open: false,

        show() {
            this.open = true;
            // The focus can only move once the panel is really rendered:
            // while the overlay is `display: none`, `focus()` on one of
            // its fields does nothing at all.
            //
            // Alpine's CSP build does not flush the pending effects before
            // running a `nextTick` callback (the standard build does), and
            // `x-show` re-shows an element in a `setTimeout` — so the
            // callback could run while the panel is still hidden. The
            // wait is therefore explicit.
            this.whenRendered(() => this.focusInitial());
        },

        /**
         * Run a callback once the panel has boxes again.
         *
         * `requestAnimationFrame` is the frame in which the browser has
         * applied the styles; the loop stops as soon as the panel is
         * rendered, and gives up after a second — a dialog that is still
         * not rendered after a second is broken anyway.
         */
        whenRendered(callback) {
            let frames = 0;

            const attempt = () => {
                const panel = this.$refs.panel;

                if (panel && panel.getClientRects().length > 0) {
                    callback();

                    return;
                }

                frames += 1;

                if (frames > 60) {
                    return;
                }

                requestAnimationFrame(attempt);
            };

            requestAnimationFrame(attempt);
        },

        close() {
            this.open = false;
            const opener = this.$refs.wrapper.querySelector('[aria-haspopup]');
            this.$nextTick(() => { if (opener) { opener.focus(); } });
        },

        onEscape() {
            if (this.open) { this.close(); }
        },

        focusables() {
            return Array.from(this.$refs.panel.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]):not([type=hidden]), select:not([disabled]), textarea:not([disabled])'));
        },

        focusInitial() {
            // The fields of the dialog live in a `[data-dialog-fields]`
            // wrapper, which is rendered only when the caller gave fields.
            // The query therefore answers "is there a field to focus?" on
            // its own — no `hasFields` flag to keep in sync with the markup.
            const field = this.$refs.panel.querySelector('[data-dialog-fields] input:not([type=hidden]):not([disabled]), [data-dialog-fields] select, [data-dialog-fields] textarea');
            (field || this.$refs.cancel).focus();
        },

        trap(e) {
            const items = this.focusables();
            if (items.length === 0) { e.preventDefault(); return; }
            const first = items[0];
            const last = items[items.length - 1];
            const inside = this.$refs.panel.contains(document.activeElement);
            if (! inside) { e.preventDefault(); first.focus(); }
            else if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
            else if (! e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
        },

        get ariaExpanded() {
            return this.open ? 'true' : 'false';
        },
    }));

    /**
     * The "show the details" toggle of the audit log.
     */
    Alpine.data('auditLog', () => ({
        expanded: false,

        toggle() {
            this.expanded = ! this.expanded;
        },

        get ariaExpanded() {
            return this.expanded ? 'true' : 'false';
        },

        get label() {
            return this.expanded ? 'Masquer ▾' : 'Voir ▸';
        },
    }));

    /**
     * The "show the details" toggle of a dead webhook delivery.
     */
    Alpine.data('deliveryDetails', () => ({
        showDetails: false,

        toggle() {
            this.showDetails = ! this.showDetails;
        },

        get ariaExpanded() {
            return this.showDetails ? 'true' : 'false';
        },

        get chevronClass() {
            return this.showDetails ? 'rotate-90' : '';
        },
    }));

    /**
     * The "edit this webhook endpoint" toggle.
     */
    Alpine.data('endpointEditor', () => ({
        editing: false,

        toggle() {
            this.editing = ! this.editing;
        },

        get ariaExpanded() {
            return this.editing ? 'true' : 'false';
        },

        get notEditing() {
            return ! this.editing;
        },
    }));

    /**
     * The "receive everything" checkbox of a webhook endpoint: checking it
     * disables the per-category checkboxes (they would contradict it).
     *
     * The initial value is a server-side fact (`data-all`), not a client-side
     * guess: the endpoint already stored in the database may already
     * receive everything.
     */
    Alpine.data('allCategories', () => ({
        all: false,

        init() {
            this.all = this.$el.dataset.all === 'true';
        },

        toggleAll(e) {
            this.all = e.target.checked;
        },

        get dimmedClass() {
            return this.all ? 'opacity-50' : '';
        },
    }));

    /**
     * The template picker of a new API key: choosing "Personnalisée"
     * reveals the list of scopes.
     *
     * The initial template is a server-side fact (`data-template`), so the
     * radio that carries it is checked by the server and stays checked
     * after a validation failure.
     */
    Alpine.data('apiKeyTemplate', () => ({
        template: 'server',

        init() {
            this.template = this.$el.dataset.template || 'server';
        },

        selectTemplate(e) {
            this.template = e.target.value;
        },

        get custom() {
            return this.template === 'custom';
        },
    }));

    /**
     * The summary of the validation errors: it takes the focus when the
     * page loads, so a screen reader reads the errors before anything
     * else (WCAG 3.3.1).
     */
    Alpine.data('errorSummary', () => ({
        init() {
            this.$el.focus();
        },
    }));
});
