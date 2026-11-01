{{--
    Champ de formulaire accessible de la console (WCAG 1.3.1, 3.3.1, 3.3.2, 4.1.2).

    Props
      name          (requis) nom du champ ; `items[]` / `a[b]` sont lus comme `items` / `a.b` pour old() et les erreurs.
      label         (requis) libellé visible (ou masqué si `label-hidden`).
      type          text (défaut) | email | password | number | date | url | tel | … | textarea | select | custom
      value         valeur du modèle. `old($name, $value)` : la saisie précédente GAGNE toujours sur le modèle.
      required      pose `required` + une étoile visuelle ; optional = affiche « (facultatif) ».
      hint          indice lié par aria-describedby (id `{id}-hint`).
      autocomplete  jeton WCAG 1.3.5 (`email`, `current-password`, `off`…).
      id            défaut : `field-{name}`.
      options       select : tableau valeur => libellé (la valeur courante est présélectionnée).
      placeholder   select : première option vide ; autres types : attribut placeholder (jamais comme libellé).
      rows          textarea (défaut 3).
      error-key     clé d'erreur si différente du name.
      wrapper-class classes du <div> englobant.
      label-hidden  libellé en sr-only (filtres compacts).
    Tout autre attribut (min, max, maxlength, x-model, class, autofocus…) est posé sur le contrôle.

    Slot par défaut (optionnel)
      - type="select" : le slot contient les <option> (alternative à `options`).
      - type="custom" (ou tout type ≠ select avec un slot non vide) : le slot REMPLACE le contrôle.
        Le composant garde label, indice et erreur ; le contrôle posé par l'appelant doit suivre la convention :
          id="{id}"  aria-describedby="{id}-hint {id}-error"  aria-invalid="true" (si erreur)
        avec {id} = `field-{name}` (ou la prop `id`). Les ids `{id}-hint` et `{id}-error` sont stables.

    Défaut B26 : le contrôle porte TOUJOURS `border` (le preflight Tailwind force border-width:0).
--}}
@props([
    'name',
    'label',
    'type' => 'text',
    'value' => null,
    'required' => false,
    'optional' => false,
    'hint' => null,
    'autocomplete' => null,
    'id' => null,
    'options' => null,
    'placeholder' => null,
    'rows' => 3,
    'errorKey' => null,
    'wrapperClass' => '',
    'labelHidden' => false,
])

@php
    $fieldId = $id ?: 'field-'.trim((string) preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $name), '-');
    $key = $errorKey ?? trim(str_replace(['[]', '[', ']'], ['', '.', ''], (string) $name), '.');

    $bag = isset($errors) ? $errors : new \Illuminate\Support\ViewErrorBag();
    $message = (string) $bag->first($key);
    $invalid = $message !== '';

    // L'ordre compte (défaut #22) : la saisie précédente d'abord, le modèle ensuite.
    $current = old($key, $value);

    $hintId = $fieldId.'-hint';
    $errorId = $fieldId.'-error';
    $hasHint = $hint !== null && $hint !== '';
    $describedBy = trim(($hasHint ? $hintId : '').' '.($invalid ? $errorId : ''));

    $controlClass = 'block w-full rounded-md border bg-white px-3 py-2 text-base text-slate-900 shadow-sm '
        .($invalid ? 'border-rose-600' : 'border-slate-300');

    $hasSlot = trim((string) $slot) !== '';
    $replaces = $hasSlot && $type !== 'select';
@endphp

<div class="{{ $wrapperClass }}">
    <label for="{{ $fieldId }}" @class(['block text-sm font-medium text-slate-700', 'mb-1' => ! $labelHidden, 'sr-only' => $labelHidden])>
        {{ $label }}
        @if ($required)
            <span aria-hidden="true" class="text-rose-700">*</span>
        @elseif ($optional)
            <span class="font-normal text-slate-500">(facultatif)</span>
        @endif
    </label>

    @if ($replaces)
        {{ $slot }}
    @elseif ($type === 'textarea')
        <textarea
            id="{{ $fieldId }}"
            name="{{ $name }}"
            rows="{{ $rows }}"
            @required($required)
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($invalid) aria-invalid="true" @endif
            {{ $attributes->class([$controlClass]) }}>{{ is_array($current) ? '' : $current }}</textarea>
    @elseif ($type === 'select')
        <select
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @required($required)
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($invalid) aria-invalid="true" @endif
            {{ $attributes->class([$controlClass]) }}>
            @if ($hasSlot)
                {{ $slot }}
            @else
                @if ($placeholder !== null)
                    <option value="">{{ $placeholder }}</option>
                @endif
                @foreach ((array) $options as $optValue => $optLabel)
                    <option value="{{ $optValue }}" @selected(in_array((string) $optValue, array_map('strval', (array) $current), true))>{{ $optLabel }}</option>
                @endforeach
            @endif
        </select>
    @else
        <input
            type="{{ $type }}"
            id="{{ $fieldId }}"
            name="{{ $name }}"
            @if ($type !== 'password' && $current !== null && ! is_array($current)) value="{{ $current }}" @endif
            @required($required)
            @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
            @if ($placeholder) placeholder="{{ $placeholder }}" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            @if ($invalid) aria-invalid="true" @endif
            {{ $attributes->class([$controlClass]) }}>
    @endif

    @if ($hasHint)
        <p id="{{ $hintId }}" class="mt-1 text-sm text-slate-600">{{ $hint }}</p>
    @endif
    @if ($invalid)
        <p id="{{ $errorId }}" class="mt-1 text-sm font-medium text-rose-700"><span class="sr-only">Erreur : </span>{{ $message }}</p>
    @endif
</div>
