<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · Enterprise Core</title>

    {{-- Tailwind via CDN. Pinning keeps the layout stable across builds
         without a bundler. --}}
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

    <div class="flex min-h-screen md:min-h-0">

        {{-- Sidebar: only rendered for authenticated operators. Hidden
             entirely on login/MFA pages (no OperatorContext bound yet).
             Fixed-width on md+, slide-over on mobile. --}}
        @if (app()->bound(\Core\Identity\Contracts\OperatorContext::class))
            @php
                /** @var \Core\Identity\Contracts\OperatorContext $operator */
                $operator = app(\Core\Identity\Contracts\OperatorContext::class);
                $has = fn (string $permission) => in_array('*', $operator->permissions ?? [], true) || in_array($permission, $operator->permissions ?? [], true);
            @endphp

            <aside
                x-data
                x-show="$store.navOpen || (window.innerWidth >= 768)"
                @resize.window="window.innerWidth >= 768 && $store.navOpen = true"
                :class="$store.navOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0'"
                class="fixed inset-y-0 left-0 z-40 w-64 bg-slate-900 text-slate-100 transition-transform duration-150 ease-out md:relative md:translate-x-0 md:flex md:flex-col">

                {{-- Brand --}}
                <div class="flex items-center h-14 px-5 border-b border-slate-800/60">
                    <a href="{{ route('admin.dashboard') }}" class="flex items-center gap-2">
                        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 6h18M3 14h18M3 18h18"/></svg>
                        <span class="font-semibold tracking-tight">Core Admin</span>
                    </a>
                </div>

                {{-- Navigation --}}
                <nav class="flex-1 overflow-y-auto px-3 py-4 space-y-1 text-sm">
                    @if($has('audit:read'))
                        <a href="{{ route('admin.audit-log.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.audit-log.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                            Audit log
                        </a>
                    @endif

                    @if($has('projects:read'))
                        <a href="{{ route('admin.organizations.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.organizations.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h2"/></svg>
                            Organizations
                        </a>
                    @endif

                    @if($has('payments:read'))
                        <a href="{{ route('admin.payments.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.payments.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h2m5 0h2M11 17h2m5 0h2M3 7h18"/></svg>
                            Payments
                        </a>
                    @endif

                    @if($has('webhooks:read'))
                        <a href="{{ route('admin.webhook-deliveries.dead') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.webhook-deliveries.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
                            Webhooks (DLQ)
                        </a>
                    @endif

                    @if($has('payments:reconcile:write'))
                        <a href="{{ route('admin.reconciliation.new') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.reconciliation.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5.197m0 0v5.197M15 4v5h.582M19.418 15A8.003 8.003 0 0115 19.418M4.582 9H9"/></svg>
                            Reconciliation
                        </a>
                    @endif

                    @if($has('operators:read'))
                        <a href="{{ route('admin.operators.index') }}" class="flex items-center gap-3 px-3 py-2 rounded-md hover:bg-slate-800/60 {{ request()->routeIs('admin.operators.*') ? 'bg-slate-800 text-white' : 'text-slate-300' }}">
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            Operators
                        </a>
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

        {{-- Main content — always rendered. On unauthenticated pages the
             content takes the full width (login form uses max-w-md mx-auto
             mt-12 to center itself). --}}
        <main class="flex-1 min-w-0">

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

            {{-- Page content --}}
            <div class="px-4 sm:px-6 lg:px-8 py-6">
                @yield('content')
            </div>
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
