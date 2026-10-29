{{--
    Tableau de la console (WCAG 1.3.1).

    Props
      headers  liste de libellés. Un libellé vide ('') est une colonne d'actions : le <th> reçoit aria-label="Actions".
      caption  nom accessible du tableau, rendu en <caption class="sr-only"> (63 tableaux sans nom avant : D1).
      empty    texte de l'état vide.
    Slot : les lignes <tr>. Le <tbody> est facultatif côté appelant (D6) : s'il manque, le composant l'ajoute ;
    un appelant qui a besoin d'un <tbody x-data> par groupe de lignes (ligne de détail extensible) le fournit lui-même.

    Usage : <x-admin.table caption="Factures du projet" :headers="['Numéro', 'Total', '']"> <tbody>…</tbody> </x-admin.table>
--}}
@props([
    'headers' => [],
    'empty' => 'Aucun résultat.',
    'caption' => null,
])

@php
    $headers = collect($headers);
    $hasRows = trim((string) $slot) !== '';
    $hasBody = stripos((string) $slot, '<tbody') !== false;
@endphp

<div class="bg-white border border-slate-200 rounded-lg overflow-x-auto">
    <table class="min-w-full divide-y divide-slate-200">
        @if ($caption !== null && $caption !== '')
            <caption class="sr-only">{{ $caption }}</caption>
        @endif
        <thead class="bg-slate-50">
            <tr>
                @foreach ($headers as $label)
                    <th scope="col" @if (trim((string) $label) === '') aria-label="Actions" @endif class="px-4 py-3 text-left text-sm font-semibold uppercase tracking-wider text-slate-600">
                        {{ $label }}
                    </th>
                @endforeach
            </tr>
        </thead>
        @if ($hasRows && $hasBody)
            {{ $slot }}
        @elseif ($hasRows)
            <tbody class="divide-y divide-slate-100 bg-white">
                {{ $slot }}
            </tbody>
        @else
            <tbody class="divide-y divide-slate-100 bg-white">
                <tr>
                    <td colspan="{{ max(1, $headers->count()) }}" class="px-4 py-8 text-center text-base text-slate-600">
                        {{ $empty }}
                    </td>
                </tr>
            </tbody>
        @endif
    </table>
</div>
