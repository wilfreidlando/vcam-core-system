@php
    $paths = [
        'home' => 'M3 11l9-8 9 8v9a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z',
        'subscription' => 'M4 4v6h6M20 20v-6h-6M5.6 15A8 8 0 0 0 19 12M18.4 9A8 8 0 0 0 5 12',
        'plans' => 'M12 3l2.6 5.6 6.1.7-4.5 4.2 1.2 6L12 16.5 6.6 19.5l1.2-6L3.3 9.3l6.1-.7z',
        'invoices' => 'M7 3h7l5 5v13H7zM14 3v5h5M10 13h6M10 17h6',
        'payments' => 'M3 7h18v12H3zM3 11h18M7 15h3',
        'usage' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'profile' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
        'logout' => 'M10 4H5v16h5M15 8l5 4-5 4M20 12H9',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'back' => 'M15 5l-7 7 7 7',
        'help' => 'M12 17h.01M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.7.4-1 1-1 1.7M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18z',
    ];
@endphp
<svg class="icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="{{ $paths[$name] ?? '' }}"/></svg>
