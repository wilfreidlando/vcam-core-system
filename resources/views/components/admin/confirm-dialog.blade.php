{{--
    Modale de confirmation UNIQUE de la console (remplace les 7 copies divergentes).
    WCAG 4.1.2, 2.4.3, 2.1.2, 2.5.8.

    Props
      title          (requis) titre de la modale (aria-labelledby).
      message        texte explicatif (aria-describedby).
      action         (requis) URL du formulaire.
      method         POST (défaut) | DELETE | PUT | PATCH | GET — @csrf + @method posés pour tout sauf GET.
      confirm        libellé du bouton de confirmation (défaut « Confirmer »).
      cancel         libellé du bouton d'annulation (défaut « Annuler »).
      variant        danger (défaut, rose) | primary (indigo).
      trigger-label  libellé du bouton déclencheur par défaut (si le slot `trigger` est absent).
      trigger-class  classes du bouton déclencheur par défaut.
      id             préfixe des ids (défaut aléatoire).

    Slots
      trigger  (nommé) le bouton déclencheur : <x-slot:trigger><button type="button" class="…">Révoquer</button></x-slot:trigger>
               Le composant y pose aria-haspopup="dialog" et :aria-expanded, et ouvre la modale au clic.
               Sans `<button`/`<a ` dans le slot, son contenu devient le libellé d'un bouton par défaut.
      défaut   champs supplémentaires DANS le formulaire (motif obligatoire, champ caché…). Ils reçoivent
               le focus initial ; sans champ, le focus va sur « Annuler ».
               Utiliser <x-admin.field> pour un motif : il garde label + erreur.

    Comportement : piège de focus (Tab / Shift+Tab bouclent), Échap ferme, clic sur le fond ferme,
    focus rendu au déclencheur à la fermeture, x-cloak. Ne pas imbriquer dans un autre <form>.
    Chaque instance porte son propre état Alpine (plusieurs par page / par ligne de tableau : OK).
--}}
@props([
    'title',
    'message' => null,
    'action',
    'method' => 'POST',
    'confirm' => 'Confirmer',
    'cancel' => 'Annuler',
    'variant' => 'danger',
    'triggerLabel' => null,
    'triggerClass' => null,
    'id' => null,
])

@php
    $uid = $id ?: 'dialog-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));
    $titleId = $uid.'-title';
    $descId = $uid.'-desc';
    $verb = strtoupper((string) $method);
    $formMethod = $verb === 'GET' ? 'GET' : 'POST';
    $hasFields = trim((string) $slot) !== '';

    $danger = $variant !== 'primary';
    $confirmClass = $danger ? 'bg-rose-700 hover:bg-rose-800' : 'bg-indigo-600 hover:bg-indigo-700';
    $defaultTriggerClass = $triggerClass ?? ($danger
        ? 'inline-flex items-center rounded-md border border-rose-300 px-3 py-1.5 text-sm font-medium text-rose-700 hover:bg-rose-50'
        : 'inline-flex items-center rounded-md border border-indigo-300 px-3 py-1.5 text-sm font-medium text-indigo-700 hover:bg-indigo-50');

    $triggerHtml = trim((string) ($trigger ?? ''));
    $ariaAttrs = 'aria-haspopup="dialog" aria-expanded="false" :aria-expanded="ariaExpanded"';
    if ($triggerHtml !== '' && preg_match('/<(button|a)\b/i', $triggerHtml)) {
        // Le bouton fourni par l'appelant : on lui pose les attributs ARIA du motif « dialogue ».
        $triggerHtml = preg_replace('/<(button|a)\b/i', '<$1 '.$ariaAttrs, $triggerHtml, 1);
    } else {
        $label = $triggerHtml !== '' ? $triggerHtml : e($triggerLabel ?? $confirm);
        $triggerHtml = '<button type="button" '.$ariaAttrs.' class="'.e($defaultTriggerClass).'">'.$label.'</button>';
    }
@endphp

{{-- `confirmDialog` holds the state of the dialog: the focus trap, the
     Escape key, the focus given back to the trigger. The component lives in
     `public/assets/admin/console.js`: Alpine's CSP build cannot evaluate
     an object literal, only a property path (ADR-0102).

     One instance per dialog — `x-data` instantiates the component once per
     element, so several dialogs on the same page each own their state. --}}
<div
    x-data="confirmDialog"
    @keydown.escape.window="onEscape"
    class="inline-block">
    <span x-ref="wrapper" class="inline-block" @click="show">{!! $triggerHtml !!}</span>

    <div
        x-show="open"
        x-cloak
        class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4"
        @click.self="close"
        @keydown.tab="trap">
        <div
            x-ref="panel"
            role="dialog"
            aria-modal="true"
            aria-labelledby="{{ $titleId }}"
            @if ($message) aria-describedby="{{ $descId }}" @endif
            class="w-full max-w-md rounded-lg bg-white p-6 text-left shadow-xl">
            <h2 id="{{ $titleId }}" class="mb-2 text-base font-semibold text-slate-900">{{ $title }}</h2>
            @if ($message)
                <p id="{{ $descId }}" class="mb-4 text-sm text-slate-700">{{ $message }}</p>
            @endif

            <form method="{{ $formMethod }}" action="{{ $action }}">
                @if ($formMethod !== 'GET')
                    @csrf
                    @if ($verb !== 'POST')
                        @method($verb)
                    @endif
                @endif
                @if ($hasFields)
                    <div data-dialog-fields class="mb-4 space-y-3">{{ $slot }}</div>
                @endif
                <div class="flex justify-end gap-2">
                    <button type="button" x-ref="cancel" @click="close"
                            class="rounded-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium text-slate-700 hover:bg-slate-50">{{ $cancel }}</button>
                    <button type="submit"
                            class="rounded-md px-3 py-1.5 text-sm font-medium text-white {{ $confirmClass }}">{{ $confirm }}</button>
                </div>
            </form>
        </div>
    </div>
</div>
