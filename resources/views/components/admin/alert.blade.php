{{--
    Bandeau de message (WCAG 4.1.3, 1.4.1).

    Props
      variant  success | error | info | warning (défaut info)
      title    titre facultatif (gras)
    Rôle déduit : `alert` pour error, `status` pour les autres. Le slot porte le message.
    L'état n'est pas porté par la couleur seule : icône + préfixe lu par les lecteurs d'écran.

    Usage : <x-admin.alert variant="success">Invitation créée.</x-admin.alert>
--}}
@props([
    'variant' => 'info',
    'title' => null,
])

@php
    $variant = in_array($variant, ['success', 'error', 'info', 'warning'], true) ? $variant : 'info';
    $role = $variant === 'error' ? 'alert' : 'status';
    $palette = match ($variant) {
        'success' => 'border-emerald-300 bg-emerald-50 text-emerald-900',
        'error' => 'border-rose-300 bg-rose-50 text-rose-900',
        'warning' => 'border-amber-300 bg-amber-50 text-amber-900',
        default => 'border-indigo-200 bg-indigo-50 text-indigo-900',
    };
    $prefix = match ($variant) {
        'success' => 'Succès : ',
        'error' => 'Erreur : ',
        'warning' => 'Attention : ',
        default => 'Information : ',
    };
    $iconPath = match ($variant) {
        'success' => 'M9 12.75 11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        'error' => 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z',
        'warning' => 'M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z',
        default => 'M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z',
    };
@endphp

<div role="{{ $role }}" {{ $attributes->class(['mb-4 flex items-start gap-3 rounded-md border px-4 py-3 text-sm', $palette]) }}>
    <svg xmlns="http://www.w3.org/2000/svg" class="mt-0.5 h-5 w-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $iconPath }}"/></svg>
    <div class="min-w-0 flex-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif
        <div><span class="sr-only">{{ $prefix }}</span>{{ $slot }}</div>
    </div>
</div>
