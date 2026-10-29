{{-- Pagination par curseur AVEC « Précédent » (défaut #34).

     L'API ne donne que le curseur suivant ; la vue garde donc la piste des curseurs déjà
     parcourus dans la query (`trail[]`) : « Suivant » y ajoute la page courante, « Précédent »
     en retire le dernier. Le contrôleur ignore `trail`.

     Variables : $routeName, $routeParams (paramètres de la route, filtres compris), $nextCursor. --}}
@php
    $currentCursor = request()->query('cursor');
    $currentCursor = is_string($currentCursor) && $currentCursor !== '' ? $currentCursor : null;
    $trail = array_values(array_filter((array) request()->query('trail', []), 'is_string'));

    $prevParams = $routeParams;
    if ($trail !== []) {
        $prevParams = array_merge($routeParams, ['cursor' => end($trail)]);
        if (count($trail) > 1) {
            $prevParams['trail'] = array_slice($trail, 0, -1);
        }
    }

    $nextParams = array_merge($routeParams, ['cursor' => $nextCursor]);
    $nextTrail = $currentCursor === null ? $trail : [...$trail, $currentCursor];
    if ($nextTrail !== []) {
        $nextParams['trail'] = $nextTrail;
    }

    $linkClass = 'inline-block rounded-md border border-slate-300 bg-white px-2.5 py-1.5 text-sm font-medium text-indigo-700 hover:bg-slate-50 hover:text-indigo-900';
@endphp
@if ($currentCursor !== null || $nextCursor)
    <nav class="flex items-center justify-between gap-3" aria-label="Pagination">
        @if ($currentCursor !== null)
            <a href="{{ route($routeName, $prevParams) }}" rel="prev" class="{{ $linkClass }}">← {{ $previousLabel ?? 'Page précédente' }}</a>
        @else
            <span></span>
        @endif
        @if ($nextCursor)
            <a href="{{ route($routeName, $nextParams) }}" rel="next" class="{{ $linkClass }}">{{ $nextLabel ?? 'Page suivante' }} →</a>
        @endif
    </nav>
@endif
