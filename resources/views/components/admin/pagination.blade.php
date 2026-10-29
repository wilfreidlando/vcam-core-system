@props([
    'page' => 1,
    'perPage' => 25,
    'total' => 0,
])

@php
    $page = (int) $page;
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
    $prevParams = request()->query();
    $prevParams['page'] = max(1, $page - 1);
    $nextParams = request()->query();
    $nextParams['page'] = min($pages, $page + 1);
    $btn = 'inline-flex items-center gap-1 rounded-md border border-slate-300 px-4 py-2 text-base font-medium text-slate-700 bg-white';
    $hasPrev = $page > 1;
    $hasNext = $page < $pages;
@endphp

@if ($pages > 1)
<nav class="flex items-center justify-between mt-4" aria-label="Pagination">
    <p class="text-sm text-slate-600">
        Page {{ $page }} sur {{ $pages }}
        · {{ $total }} {{ $total === 1 ? 'élément' : 'éléments' }}
    </p>
    <div class="inline-flex gap-2">
        {{-- F7 : un lien désactivé n'a PAS de href (sinon Entrée navigue quand même) ; il sort de l'ordre de tabulation. --}}
        @if ($hasPrev)
            <a href="{{ request()->url() }}?{{ http_build_query($prevParams) }}" rel="prev" class="{{ $btn }} hover:bg-slate-50">Précédent</a>
        @else
            <a role="link" aria-disabled="true" tabindex="-1" class="{{ $btn }} cursor-not-allowed opacity-50">Précédent</a>
        @endif
        @if ($hasNext)
            <a href="{{ request()->url() }}?{{ http_build_query($nextParams) }}" rel="next" class="{{ $btn }} hover:bg-slate-50">Suivant</a>
        @else
            <a role="link" aria-disabled="true" tabindex="-1" class="{{ $btn }} cursor-not-allowed opacity-50">Suivant</a>
        @endif
    </div>
</nav>
@endif
