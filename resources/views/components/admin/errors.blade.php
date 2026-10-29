{{--
    Résumé d'erreurs de formulaire (WCAG 3.3.1, 2.4.3, 4.1.3).

    Props
      bag       MessageBag | ViewErrorBag | tableau ; défaut : $errors de la vue.
      messages  tableau de chaînes (prioritaire sur `bag`).
      title     titre du résumé.
      id        défaut `form-errors`.
      focus     true (défaut) : le résumé prend le focus au chargement.
    N'affiche rien s'il n'y a aucune erreur.

    Usage : <x-admin.errors />   ou   <x-admin.errors :messages="[$e->getMessage()]" title="Échec" />
--}}
@props([
    'bag' => null,
    'messages' => null,
    'title' => 'Le formulaire contient des erreurs.',
    'id' => 'form-errors',
    'focus' => true,
])

@php
    $source = $messages ?? $bag ?? (isset($errors) ? $errors : null);
    if (is_object($source) && method_exists($source, 'all')) {
        $source = $source->all();
    } elseif (is_object($source) && method_exists($source, '__call')) {
        $source = $source->all();
    }
    $list = array_values(array_unique(array_map('strval', collect($source ?? [])->flatten()->all())));
@endphp

@if ($list !== [])
    <div
        id="{{ $id }}"
        role="alert"
        tabindex="-1"
        @if ($focus) x-data x-init="$el.focus()" @endif
        {{ $attributes->class(['mb-4 rounded-md border border-rose-300 bg-rose-50 p-4 text-rose-900']) }}>
        <p class="font-semibold">{{ $title }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-sm">
            @foreach ($list as $line)
                <li>{{ $line }}</li>
            @endforeach
        </ul>
    </div>
@endif
