/**
 * The mobile-nav store of the operator console.
 *
 * ONE source of truth for "is the drawer open", read by the hamburger,
 * the backdrop and the sidebar. `sync()` closes the drawer when a resize
 * crosses the md breakpoint (768 px), and ignores resizes that do not —
 * an on-screen keyboard or a collapsing browser bar fires `resize` too and
 * must not slam the drawer shut.
 *
 * Why a file and not an inline <script> (audit 2026-10-29, ADR-0102):
 * an inline script forces `'unsafe-inline'` into the console's CSP. Served
 * from the application's own origin, this file is allowed by
 * `script-src 'self'` — and the console drops one of the two markers.
 *
 * It must run BEFORE Alpine: the store is read by the very first render
 * of the drawer. The layout therefore loads it with `defer`, before the
 * Alpine bundle (both are deferred, and this one comes first in the
 * document).
 */
document.addEventListener('alpine:init', () => {
    Alpine.store('nav', {
        open: false,
        wide: window.innerWidth >= 768,
        toggle() { this.open = ! this.open; },
        close() { this.open = false; },
        sync() {
            const wide = window.innerWidth >= 768;
            if (wide !== this.wide) {
                this.wide = wide;
                this.open = false;
            }
        },
    });

    window.addEventListener('resize', () => Alpine.store('nav').sync());
});
