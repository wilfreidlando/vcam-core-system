@props([
    'placeholder' => 'Rechercher…',
    'value' => '',
    // Nom du paramètre de requête (défaut `search` ; `q` pour le portefeuille).
    'name' => 'search',
    // Libellé accessible (sr-only) ; défaut : le placeholder.
    'label' => null,
    // Id de l'input ; défaut unique par instance (le composant peut servir plusieurs fois par page).
    'id' => null,
])

@php
    $inputId = $id ?: 'search-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(6));
    $labelText = $label ?? rtrim((string) $placeholder, '…. ');
    $value = (string) $value;
@endphp

{{-- A single GET form that resubmits the current URL with `search` set —
     every other active query param (page is deliberately dropped so a
     new search always starts at page 1; anything else, like an
     organization_id filter, survives via hidden inputs the caller
     passes as the component's slot). --}}
<form method="GET" action="{{ request()->url() }}" role="search" class="flex items-center gap-2">
    {{ $slot ?? '' }}
    <div class="relative flex-1 max-w-xs">
        <label for="{{ $inputId }}" class="sr-only">{{ $labelText }}</label>
        <svg xmlns="http://www.w3.org/2000/svg" class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true" focusable="false"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
        <input
            type="text"
            id="{{ $inputId }}"
            name="{{ $name }}"
            value="{{ $value }}"
            placeholder="{{ $placeholder }}"
            autocomplete="off"
            class="block w-full rounded-md border border-slate-300 pl-9 pr-3 py-2.5 text-base shadow-sm" />
    </div>
    <button type="submit" class="rounded-md border border-slate-300 bg-white px-4 py-2.5 text-base font-medium text-slate-700 hover:bg-slate-50">
        Rechercher
    </button>
    @if ($value !== '')
        <a href="{{ request()->url() }}" class="inline-block py-1.5 text-base text-slate-600 hover:text-slate-800">
            Effacer
        </a>
    @endif
</form>
