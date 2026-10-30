<!DOCTYPE html>
<html lang="fr" class="h-full bg-slate-50" style="font-size: 18px;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Administration') · Enterprise Core</title>

    {{-- Tailwind via CDN. Pinning keeps the layout stable across builds
         without a bundler. The font-size override on <html> above scales
         every `rem` unit up by 12.5% (16px → 18px) — Tailwind's text-sm,
         text-base, text-lg, etc. all become proportionally larger without
         touching the 100+ call sites that already use them. (Bumped from
         an earlier 17px, which the user reported still read too small.) --}}
    {{-- Both libraries are served by the application, at the versions that
         used to be pinned on the CDNs: a compromised third-party host could
         run arbitrary JavaScript on the console's origin, with its CSRF
         token and the secrets it shows once (recette 2026-10-29, F-05).
         Provenance and SHA-256 in public/assets/admin/vendor/README.md. --}}
    <script src="{{ asset('assets/admin/vendor/tailwindcss-3.4.16.js') }}"></script>
    {{-- The mobile-nav store: ONE source of truth for "is the drawer
         open", read by the hamburger, the backdrop and the sidebar.
         `sync()` closes the drawer when a resize crosses the md
         breakpoint (768 px), and ignores resizes that do not — an
         on-screen keyboard or a collapsing browser bar fires `resize`
         too and must not slam the drawer shut.

         It lives in a served file, not in an inline <script>: an inline
         script would force `'unsafe-inline'` into the console's CSP
         (ADR-0102). It is loaded BEFORE Alpine — the store is read by the
         very first render of the drawer, and `alpine:init` fires when
         Alpine starts: a listener registered after that event would
         never run. Both scripts are deferred, and the document order
         decides. --}}
    <script defer src="{{ asset('assets/admin/console.js') }}"></script>
    {{-- Alpine.js for the small interactive bits (mobile nav,
         confirmation dialogs). Loaded as a defer script. --}}
    <script defer src="{{ asset('assets/admin/vendor/alpine-3.14.1.min.js') }}"></script>

    <style>
        /* Subtle scrollbar polish for the admin aesthetic. */
        ::-webkit-scrollbar { width: 8px; height: 8px; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
        ::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
        /* Flash message slide-in. */
        @keyframes flash-in {
            from { opacity: 0; transform: translateY(-6px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        /* Motion is decoration, not information (WCAG 2.3.3). The portal
           already honours the user's preference; the console did not. */
        @media (prefers-reduced-motion: no-preference) {
            .flash { animation: flash-in 180ms ease-out both; }
        }
        /* Form-field focus ring. WCAG 2.2 SC 2.4.11 (Focus Appearance, AA)
           requires the focused state to contrast at least 3:1 with the
           unfocused one: the previous 25%-alpha indigo halo measured
           1.39:1 on white and was effectively invisible. */
        input:focus, select:focus, textarea:focus {
            outline: 2px solid #4338ca;
            outline-offset: 2px;
            border-color: rgb(99 102 241);
        }
        /* Alpine boots after the first paint: without this rule the
           confirmation overlays flash on screen and their buttons are
           focusable while the drawer they belong to is still hidden. */
        [x-cloak] { display: none !important; }
        /* The drawer below md. Hidden by default (and so out of the
           accessibility tree and the tab order); the nav store opens it
           by adding `is-open`. From md up Tailwind's `md:flex` shows the
           sidebar permanently and this rule does not apply. */
        @media (max-width: 767px) {
            #admin-sidebar { display: none; }
            #admin-sidebar.is-open { display: flex; flex-direction: column; }
        }
        /* Skip link (WCAG 2.4.1) — off-screen until it takes the focus. */
        .skip-link {
            position: absolute;
            left: -9999px;
            top: 0;
            z-index: 100;
            background: #4338ca;
            color: #fff;
            padding: 0.5rem 1rem;
            border-radius: 0 0 0.375rem 0;
            font-weight: 600;
        }
        .skip-link:focus { left: 0; }
    </style>
</head>

<body class="h-full text-slate-800 antialiased">

    {{-- Skip link (WCAG 2.4.1). The console repeats the same fifteen
         sidebar links on every page, plus a breadcrumb: without this
         link a keyboard user walks the whole menu on every page. --}}
    <a href="#main" class="skip-link">Aller au contenu</a>

    {{-- Mobile-only top bar with a hamburger that toggles the sidebar
         via Alpine. Hidden on md+. Only shown when the operator is logged
         in — public pages (login, MFA) skip the chrome entirely.

         The button drives `$store.nav`, the very store the sidebar
         reads: it used to toggle a local `open` flag that nothing else
         ever looked at, so on a phone the drawer never opened and the
         only visible effect was the icon turning into a cross. --}}
    @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
        <header
            class="md:hidden sticky top-0 z-30 flex items-center justify-between bg-white border-b border-slate-200 px-4 h-14">
            <a href="{{ route('admin.dashboard') }}" class="font-semibold text-slate-900">Enterprise Core</a>
            <button
                type="button"
                @click="$store.nav.toggle()"
                :aria-expanded="$store.nav.open ? 'true' : 'false'"
                aria-controls="admin-sidebar"
                class="inline-flex items-center justify-center rounded-md p-2 text-slate-600 hover:bg-slate-100"
                aria-label="Afficher/masquer le menu">
                <svg x-show="!$store.nav.open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg x-show="$store.nav.open" x-cloak xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </header>
    @endif

    {{-- Bug found by live verification: this used to be
         `min-h-screen md:min-h-0` — `md:min-h-0` removes the height
         constraint entirely on desktop, so the whole shell collapsed to
         its content's height instead of filling the viewport, and the
         sidebar visibly grew/shrank with whatever page was open. `h-screen
         overflow-hidden` pins the shell to exactly the viewport height,
         always; only `<main>` scrolls internally. --}}
    <div class="flex h-screen overflow-hidden">

        {{-- Sidebar: only rendered for authenticated operators. Hidden
             entirely on login/MFA pages (no OperatorContext bound yet).
             Fixed-width and fixed-height on md+ (its own scrollbar if the
             nav ever grows past the viewport), slide-over on mobile. --}}
        @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
            {{-- The entries and badges ($operator, $can*, $*Count) come from
                 App\Http\View\Composers\AdminSidebarComposer — never from a
                 port called in this template (ADR-0069 §2, guard G16). --}}

            {{-- Backdrop of the mobile drawer. Clicking it closes the
                 drawer. Without it the drawer simply covers the page and
                 the operator has to hunt for the (now hidden) hamburger
                 to get out. --}}
            <div
                x-show="$store.nav.open"
                x-cloak
                @click="$store.nav.close()"
                aria-hidden="true"
                class="fixed inset-0 z-30 bg-slate-900/50 md:hidden"></div>

            {{-- The sidebar. On md+ it is always shown (Tailwind `md:flex`);
                 below md it is hidden by the `#admin-sidebar` rule in the
                 <style> above unless the store opens it (`is-open`).
                 Every Alpine expression here is a single call or
                 assignment — the previous resize handler,
                 `window.innerWidth >= 768 && $store.navOpen = true`, was
                 not valid JavaScript (`a && b = c` is a syntax error): it
                 never ran and threw on every resize. The breakpoint logic
                 now lives in `Alpine.store('nav').sync()`, plain JS below. --}}
            <aside
                id="admin-sidebar"
                aria-label="Barre latérale"
                x-data
                @keydown.escape.window="$store.nav.close()"
                :class="{ 'is-open': $store.nav.open }"
                class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 bg-slate-900 text-slate-100 md:relative md:flex md:flex-col md:h-screen md:overflow-y-auto">

                {{-- Brand --}}
                <div class="flex items-center h-14 px-5 border-b border-slate-800/60">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
                        <span class="font-semibold tracking-tight">Administration Core</span>
                    </a>
                </div>

                {{-- Navigation — grouped under uppercase section labels so
                     the hierarchy reads at a glance instead of one flat
                     list of 6 links (review finding: "even the sidebar is
                     poor"). Each group only renders its label when at
                     least one of its links is permitted for the current
                     operator; each link carries an active-state highlight
                     and, where cheap and meaningful, a small numeric
                     badge. --}}
                <nav aria-label="Navigation principale" class="flex-1 overflow-y-auto px-3 py-2 text-base">

                    {{-- Vue d'ensemble --}}
                    @if($canDashboard)
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 px-3 pt-4 pb-1">Vue d'ensemble</p>
                        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.dashboard') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            <span class="truncate">Tableau de bord</span>
                        </a>
                    @endif

                    {{-- Identité --}}
                    @if($canOrganizations || $canProjects || $canOperators)
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 px-3 pt-4 pb-1">Identité</p>

                        @if($canOrganizations)
                            <a href="{{ route('admin.organizations.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.organizations.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h2"/></svg>
                                <span class="truncate">Organisations</span>
                            </a>
                        @endif

                        @if($canProjects)
                            <a href="{{ route('admin.projects.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.projects.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <span class="truncate">Projets</span>
                            </a>
                        @endif

                        @if($canOperators)
                            <a href="{{ route('admin.operators.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.operators.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span class="truncate">Opérateurs</span>
                            </a>
                        @endif
                    @endif

                    {{-- Argent --}}
                    @if($canPayments || $canWallets || $canReconciliation || $canMoneyExceptions || $canLedgerAdjustments)
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 px-3 pt-4 pb-1">Argent</p>

                        @if($canPayments)
                            <a href="{{ route('admin.payments.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.payments.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
                                <span class="truncate">Paiements</span>
                            </a>
                        @endif

                        @if($canWallets)
                            {{-- Pas de liste globale des wallets : leur
                                 visibilité est toujours rattachée à un
                                 projet — ce lien envoie d'abord choisir un
                                 projet, comme "Organisations -> Voir les
                                 projets", puis l'onglet Wallets de la fiche
                                 projet prend le relais. Le libellé le dit :
                                 "Wallets" seul faisait croire à un écran
                                 dédié alors qu'on atterrissait sur la liste
                                 des projets. --}}
                            <a href="{{ route('admin.projects.index') }}" title="Choisissez un projet pour voir ses portefeuilles" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 text-slate-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 100 4 2 2 0 000-4z"/></svg>
                                <span class="truncate">Wallets par projet</span>
                            </a>
                        @endif

                        @if($canMoneyExceptions)
                            <a href="{{ route('admin.money-exceptions.index') }}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.money-exceptions.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <span class="flex items-center gap-3 min-w-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/></svg>
                                    <span class="truncate">Exceptions d'argent</span>
                                </span>
                                @if($moneyExceptionsCount !== null && $moneyExceptionsCount > 0)
                                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 text-rose-300 text-xs font-semibold px-1.5 py-0.5 min-w-[1.375rem] shrink-0">{{ $moneyExceptionsCount }}</span>
                                @endif
                            </a>
                        @endif

                        @if($canLedgerAdjustments)
                            {{-- ADR-0076 §6 — les corrections d'exploitation :
                                 l'autre moitié de la question des
                                 exceptions d'argent. Même permission
                                 (money-exceptions:read), même public. --}}
                            <a href="{{ route('admin.ledger-adjustments.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.ledger-adjustments.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                <span class="leading-snug">Corrections d'exploitation</span>
                            </a>
                        @endif

                        @if($canReconciliation)
                            <a href="{{ route('admin.reconciliation.new') }}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.reconciliation.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <span class="flex items-center gap-3 min-w-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                                    <span class="truncate">Réconciliation</span>
                                </span>
                                @if($stuckPaymentsCount !== null && $stuckPaymentsCount > 0)
                                    <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 text-amber-300 text-xs font-semibold px-1.5 py-0.5 min-w-[1.375rem] shrink-0">{{ $stuckPaymentsCount }}</span>
                                @endif
                            </a>
                        @endif
                    @endif

                    {{-- Facturation --}}
                    @if($canBilling)
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 px-3 pt-4 pb-1">Facturation</p>

                        {{-- Un client, une offre et un abonnement
                             appartiennent à un projet : ce lien envoie
                             d'abord choisir un projet (comme
                             "Wallets par projet"), puis la fiche projet
                             porte les trois entrées. --}}
                        @if($canPortfolio)
                            <a href="{{ route('admin.portfolio') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.portfolio') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3v18h18M7 15l4-4 3 3 5-6"/></svg>
                                <span class="truncate">Portefeuille</span>
                            </a>
                        @endif
                        <a href="{{ route('admin.projects.index') }}" title="Choisissez un projet pour voir sa facturation" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 text-slate-300">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                            <span class="truncate">Facturation par projet</span>
                        </a>
                    @endif

                    {{-- Plateforme --}}
                    @if($canWebhooks || $canAudit || $canSessions || $canQueues)
                        <p class="text-sm font-semibold uppercase tracking-wider text-slate-400 px-3 pt-4 pb-1">Plateforme</p>

                        @if($canWebhooks)
                            <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.webhook-deliveries.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <span class="flex items-center gap-3 min-w-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    <span class="truncate">Webhooks</span>
                                </span>
                                @if($deadWebhookCount !== null && $deadWebhookCount > 0)
                                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 text-rose-300 text-xs font-semibold px-1.5 py-0.5 min-w-[1.375rem] shrink-0">{{ $deadWebhookCount }}</span>
                                @endif
                            </a>
                        @endif

                        @if($canSessions)
                            <a href="{{ route('admin.channel-sessions.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.channel-sessions.*') || request()->routeIs('admin.projects.channel-sessions.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
                                <span class="truncate">Sessions WhatsApp</span>
                            </a>
                        @endif

                        @if($canAudit)
                            <a href="{{ route('admin.audit-log.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.audit-log.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span class="truncate">Journal d'audit</span>
                            </a>
                        @endif

                        @if($canQueues)
                            <a href="{{ url(config('horizon.path', 'horizon')) }}" target="_blank" rel="noopener" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 text-slate-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h10"/></svg>
                                <span class="truncate">Files (Horizon)</span>
                            </a>
                        @endif
                    @endif
                </nav>

                {{-- Carte opérateur en bas de sidebar. C'est le landmark
                     `contentinfo` de la console : sans lui, la page n'a
                     aucun footer landmark. --}}
                <footer class="border-t border-slate-800/60 p-4 text-base">
                    <div class="font-medium text-white truncate">{{ $operator->email }}</div>
                    {{-- Le rôle était affiché avec sa valeur technique
                         (`platform_admin`) : lisible par un développeur, pas
                         par un exploitant. La valeur de l'énumération n'a pas
                         changé — seule sa présentation. --}}
                    @php
                        $roleLabels = [
                            'platform_admin' => 'Administrateur de plateforme',
                            'finance_operator' => 'Opérateur financier',
                            'support_operator' => 'Opérateur support',
                            'provider_manager' => 'Responsable des prestataires',
                            'read_only_auditor' => 'Auditeur (lecture seule)',
                        ];
                        $roleValue = $operator->role->value ?? null;
                    @endphp
                    <div class="text-slate-400 text-xs mt-0.5">Rôle : <span>{{ $roleLabels[$roleValue] ?? 'Membre d\'organisation' }}</span></div>
                    <form method="POST" action="{{ route('admin.logout') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full inline-flex justify-center items-center gap-2 rounded-md bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-sm font-medium text-slate-100">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3v-1"/></svg>
                            Se déconnecter
                        </button>
                    </form>
                </footer>
            </aside>
        @endif

        {{-- Main content — always rendered. Scrolls internally
             (`h-screen overflow-y-auto`) so the sidebar next to it never
             moves or resizes, whatever the page's content length. --}}
        <main id="main" tabindex="-1" class="flex-1 min-w-0 h-screen overflow-y-auto focus:outline-none">

            {{-- Top header bar with page title and flash messages. Hidden on
                 unauthenticated pages (no title, no flash yet). --}}
            @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
                <div class="bg-white border-b border-slate-200">
                    @if (! empty($breadcrumbs))
                        <nav aria-label="Fil d'Ariane" class="px-4 sm:px-6 lg:px-8 pt-4">
                            <ol class="flex flex-wrap items-center gap-x-1.5 gap-y-1 text-sm text-slate-500">
                                @foreach ($breadcrumbs as [$crumbLabel, $crumbUrl])
                                    <li class="flex items-center gap-1.5 min-w-0">
                                        @if (! $loop->first)
                                            <svg class="h-4 w-4 shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        @endif
                                        @if ($crumbUrl !== null && ! $loop->last)
                                            <a href="{{ $crumbUrl }}" class="truncate hover:text-indigo-700 hover:underline">{{ $crumbLabel }}</a>
                                        @else
                                            <span class="truncate font-medium text-slate-700" @if ($loop->last) aria-current="page" @endif>{{ $crumbLabel }}</span>
                                        @endif
                                    </li>
                                @endforeach
                            </ol>
                        </nav>
                    @endif
                    <header class="px-4 sm:px-6 lg:px-8 pt-2 pb-5 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-4">
                        <h1 class="text-xl sm:text-2xl font-semibold text-slate-900 truncate">@yield('title', 'Admin')</h1>
                        {{-- Le nom du projet ne doit pas disparaître sous
                             640 px : il était en `hidden sm:block`, donc
                             invisible ET retiré de l'arbre d'accessibilité. --}}
                        <div class="text-sm text-slate-500 min-w-0 truncate">
                            @hasSection('subtitle')
                                @yield('subtitle')
                            @endif
                        </div>
                    </header>

                    {{-- Flash messages from controllers (success/error).

                         The live regions are ALWAYS in the DOM: a region
                         created together with its content is not announced
                         by screen readers, because there was no empty
                         region to watch change. Only the content varies. --}}
                    <div class="px-4 sm:px-6 lg:px-8" role="status" aria-live="polite">
                        @if(session('status'))
                            <div class="flash mb-4 rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800 flex items-start gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>{{ session('status') }}</span>
                            </div>
                        @endif
                    </div>
                    <div class="px-4 sm:px-6 lg:px-8" role="alert">
                        @if(session('error'))
                            <div class="flash mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800 flex items-start gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span>{{ session('error') }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Validation errors.

                         The layout only rendered session('status') and
                         session('error'), so the flash produced by
                         back()->withErrors() was thrown away: twenty forms
                         redirected the operator with nothing to read, and
                         the form looked like it had done nothing. --}}
                    @if($errors->any())
                        <div class="px-4 sm:px-6 lg:px-8">
                            <div role="alert" tabindex="-1" class="flash mb-4 rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800 focus:outline-none focus-visible:outline focus-visible:outline-2 focus-visible:outline-[#4338ca]">
                                <p class="font-semibold">Le formulaire n'a pas pu être enregistré :</p>
                                <ul class="mt-1.5 list-disc pl-5 space-y-1">
                                    @foreach($errors->all() as $error)
                                        <li>{{ $error }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Page content. Unauthenticated pages (login, MFA) get a
                 dedicated full-height, truly centered shell — both
                 horizontally and vertically, not just `mt-12` pushing the
                 card down from the top (review finding: the login page
                 sat near the top of a tall viewport instead of looking
                 centered). Authenticated pages keep the normal padded
                 content area. --}}
            @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
                <div class="px-4 sm:px-6 lg:px-8 py-6">
                    @yield('content')
                </div>
            @else
                <div class="min-h-full flex items-center justify-center px-4 py-12 bg-gradient-to-b from-slate-50 to-slate-100">
                    @yield('content')
                </div>
            @endif
        </main>

    </div>

</body>
</html>
