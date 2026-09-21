<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50" style="font-size: 18px;">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · Enterprise Core</title>

    {{-- Tailwind via CDN. Pinning keeps the layout stable across builds
         without a bundler. The font-size override on <html> above scales
         every `rem` unit up by 12.5% (16px → 18px) — Tailwind's text-sm,
         text-base, text-lg, etc. all become proportionally larger without
         touching the 100+ call sites that already use them. (Bumped from
         an earlier 17px, which the user reported still read too small.) --}}
    <script src="https://cdn.tailwindcss.com/3.4.16"></script>
    {{-- Alpine.js for the small interactive bits (mobile nav,
         confirmation dialogs). Loaded as a defer script. --}}
    <script defer src="https://unpkg.com/alpinejs@3.14.1/dist/cdn.min.js"></script>

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
        .flash { animation: flash-in 180ms ease-out both; }
        /* Form-field focus ring polish. */
        input:focus, select:focus, textarea:focus {
            outline: none;
            box-shadow: 0 0 0 3px rgb(99 102 241 / 0.25);
            border-color: rgb(99 102 241);
        }
    </style>
</head>

<body class="h-full text-slate-800 antialiased">

    {{-- Mobile-only top bar with a hamburger that toggles the sidebar
         via Alpine. Hidden on md+. Only shown when the operator is logged
         in — public pages (login, MFA) skip the chrome entirely. --}}
    @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
        <header
            x-data="{ open: false }"
            class="md:hidden sticky top-0 z-30 flex items-center justify-between bg-white border-b border-slate-200 px-4 h-14">
            <a href="{{ route('admin.dashboard') }}" class="font-semibold text-slate-900">Enterprise Core</a>
            <button
                @click="open = !open"
                class="inline-flex items-center justify-center rounded-md p-2 text-slate-600 hover:bg-slate-100"
                aria-label="Toggle navigation">
                <svg x-show="!open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                <svg x-show="open" xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
            @php
                /** @var \Core\Identity\Contracts\OperatorContext $operator */
                $operator = app(\Core\Identity\Contracts\OperatorContext::class);
                // Bug found by live verification: OperatorContext has no
                // `permissions` property (only `hasPermission()`), so this
                // used to read `null ?? []` and silently render an empty
                // sidebar for every operator, of every role — navigable
                // only by typing URLs directly.
                $has = fn (string $permission) => $operator->hasPermission($permission);

                // Per-link permission gates, computed once so both the
                // group-visibility check and the link itself reuse the
                // same boolean.
                $canDashboard = $has('audit:read');
                $canOrganizations = $has('projects:read');
                $canProjects = $has('projects:read');
                $canOperators = $has('operators:read');
                $canPayments = $has('payments:read');
                // Wallets have no standalone listing (ADR-0033 §1 / Part D:
                // wallet visibility is always project-scoped,
                // `GET projects/{projectId}/wallets`) — the sidebar entry
                // sends the operator to pick a project first, exactly like
                // the "Organizations -> View projects" pattern, then the
                // project detail page carries the actual Wallets tab.
                $canWallets = $has('wallets:read');
                $canReconciliation = $has('payments:reconcile:write');
                $canWebhooks = $has('webhooks:read');
                $canAudit = $has('audit:read');

                // Cheap, best-effort badge counts (same defensive
                // try/catch-degrade-to-null pattern as DashboardController's
                // stat queries) — a failed count just hides the badge
                // rather than erroring the whole sidebar/page. `perPage: 1`
                // means these are single-row lookups, not full scans.
                $deadWebhookCount = null;
                if ($canWebhooks) {
                    try {
                        $deadWebhookCount = app(\Core\Webhook\Contracts\ListDeadWebhookDeliveries::class)
                            ->list(new \Core\Webhook\Contracts\ListDeadWebhookDeliveriesCommand(
                                projectId: null,
                                page: 1,
                                perPage: 1,
                            ))->total;
                    } catch (\Throwable) {
                        $deadWebhookCount = null;
                    }
                }

                $stuckPaymentsCount = null;
                if ($canReconciliation) {
                    try {
                        $stuckPaymentsCount = app(\Core\Payment\Contracts\CountStuckPayments::class)->count();
                    } catch (\Throwable) {
                        $stuckPaymentsCount = null;
                    }
                }
            @endphp

            <aside
                x-data
                x-show="$store.navOpen || (window.innerWidth >= 768)"
                @resize.window="window.innerWidth >= 768 && $store.navOpen = true"
                :class="$store.navOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
                class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 bg-slate-900 text-slate-100 transition-transform duration-150 ease-out md:relative md:translate-x-0 md:flex md:flex-col md:h-screen md:overflow-y-auto">

                {{-- Brand --}}
                <div class="flex items-center h-14 px-5 border-b border-slate-800/60">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
                        <span class="font-semibold tracking-tight">Core Admin</span>
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
                <nav class="flex-1 overflow-y-auto px-3 py-2 text-sm">

                    {{-- Overview --}}
                    @if($canDashboard)
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 px-3 pt-4 pb-1">Overview</p>
                        <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.dashboard') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
                            <span class="truncate">Dashboard</span>
                        </a>
                    @endif

                    {{-- Identity --}}
                    @if($canOrganizations || $canProjects || $canOperators)
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 px-3 pt-4 pb-1">Identity</p>

                        @if($canOrganizations)
                            <a href="{{ route('admin.organizations.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.organizations.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h2"/></svg>
                                <span class="truncate">Organizations</span>
                            </a>
                        @endif

                        @if($canProjects)
                            <a href="{{ route('admin.projects.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.projects.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/></svg>
                                <span class="truncate">Projects</span>
                            </a>
                        @endif

                        @if($canOperators)
                            <a href="{{ route('admin.operators.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.operators.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                <span class="truncate">Operators</span>
                            </a>
                        @endif
                    @endif

                    {{-- Money --}}
                    @if($canPayments || $canWallets || $canReconciliation)
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 px-3 pt-4 pb-1">Money</p>

                        @if($canPayments)
                            <a href="{{ route('admin.payments.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.payments.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
                                <span class="truncate">Payments</span>
                            </a>
                        @endif

                        @if($canWallets)
                            {{-- No standalone wallet listing exists (ADR-0033
                                 §1 / Part D: wallet visibility is always
                                 project-scoped) — this sends the operator to
                                 pick a project first, exactly like
                                 "Organizations -> View projects", then the
                                 project detail page's Wallets tab does the
                                 rest. --}}
                            <a href="{{ route('admin.projects.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 text-slate-300">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12V7H5a2 2 0 010-4h14v4M3 5v14a2 2 0 002 2h16v-5M18 12a2 2 0 100 4 2 2 0 000-4z"/></svg>
                                <span class="truncate">Wallets</span>
                            </a>
                        @endif

                        @if($canReconciliation)
                            <a href="{{ route('admin.reconciliation.new') }}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.reconciliation.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <span class="flex items-center gap-3 min-w-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                                    <span class="truncate">Reconciliation</span>
                                </span>
                                @if($stuckPaymentsCount !== null && $stuckPaymentsCount > 0)
                                    <span class="inline-flex items-center justify-center rounded-full bg-amber-500/20 text-amber-300 text-xs font-semibold px-1.5 py-0.5 min-w-[1.375rem] shrink-0">{{ $stuckPaymentsCount }}</span>
                                @endif
                            </a>
                        @endif
                    @endif

                    {{-- Platform --}}
                    @if($canWebhooks || $canAudit)
                        <p class="text-xs font-semibold uppercase tracking-wider text-slate-500 px-3 pt-4 pb-1">Platform</p>

                        @if($canWebhooks)
                            <a href="{{ route('admin.webhook-deliveries.dead.web') }}" class="flex items-center justify-between gap-2 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.webhook-deliveries.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <span class="flex items-center gap-3 min-w-0">
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                                    <span class="truncate">Webhooks (DLQ)</span>
                                </span>
                                @if($deadWebhookCount !== null && $deadWebhookCount > 0)
                                    <span class="inline-flex items-center justify-center rounded-full bg-rose-500/20 text-rose-300 text-xs font-semibold px-1.5 py-0.5 min-w-[1.375rem] shrink-0">{{ $deadWebhookCount }}</span>
                                @endif
                            </a>
                        @endif

                        @if($canAudit)
                            <a href="{{ route('admin.audit-log.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.audit-log.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span class="truncate">Audit log</span>
                            </a>
                        @endif
                    @endif
                </nav>

                {{-- User card at the bottom --}}
                <div class="border-t border-slate-800/60 p-4 text-sm">
                    <div class="font-medium text-white truncate">{{ $operator->email }}</div>
                    <div class="text-slate-400 text-xs mt-0.5">Role: <span class="font-mono">{{ $operator->role->value ?? '' }}</span></div>
                    <form method="POST" action="{{ route('admin.logout') }}" class="mt-3">
                        @csrf
                        <button type="submit" class="w-full inline-flex justify-center items-center gap-2 rounded-md bg-slate-800 hover:bg-slate-700 px-3 py-1.5 text-sm font-medium text-slate-100">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3v-1"/></svg>
                            Log out
                        </button>
                    </form>
                </div>
            </aside>
        @endif

        {{-- Main content — always rendered. Scrolls internally
             (`h-screen overflow-y-auto`) so the sidebar next to it never
             moves or resizes, whatever the page's content length. --}}
        <main class="flex-1 min-w-0 h-screen overflow-y-auto">

            {{-- Top header bar with page title and flash messages. Hidden on
                 unauthenticated pages (no title, no flash yet). --}}
            @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
                <div class="bg-white border-b border-slate-200">
                    <div class="px-4 sm:px-6 lg:px-8 py-5 flex items-center justify-between">
                        <h1 class="text-xl sm:text-2xl font-semibold text-slate-900 truncate">@yield('title', 'Admin')</h1>
                        <div class="text-sm text-slate-500 hidden sm:block">
                            @hasSection('subtitle')
                                @yield('subtitle')
                            @endif
                        </div>
                    </div>

                    {{-- Flash messages from controllers (success/error) --}}
                    @if(session('status'))
                        <div class="px-4 sm:px-6 lg:px-8 pb-4">
                            <div class="flash rounded-md bg-emerald-50 border border-emerald-200 px-4 py-3 text-sm text-emerald-800 flex items-start gap-3" role="status">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <span>{{ session('status') }}</span>
                            </div>
                        </div>
                    @endif
                    @if(session('error'))
                        <div class="px-4 sm:px-6 lg:px-8 pb-4">
                            <div class="flash rounded-md bg-rose-50 border border-rose-200 px-4 py-3 text-sm text-rose-800 flex items-start gap-3" role="alert">
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                                <span>{{ session('error') }}</span>
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

    {{-- A tiny store for the mobile nav so the hamburger can toggle it
         and the resize handler can keep it open on md+. Only set on
         authenticated pages; on public pages the sidebar doesn't exist
         so the navOpen flag is irrelevant. --}}
    <script>
        document.addEventListener('alpine:init', () => {
            if (document.querySelector('aside[x-data]')) {
                Alpine.store('navOpen', window.innerWidth >= 768);
            }
        });
    </script>
</body>
</html>
