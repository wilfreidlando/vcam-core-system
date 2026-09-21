@props([
    'page' => 1,
    'perPage' => 25,
    'total' => 0,
])

@php
    $pages = max(1, (int) ceil($total / max(1, $perPage)));
@endphp

@if ($pages > 1)
<nav class="flex items-center justify-between mt-4" aria-label="Pagination">
    <p class="text-xs text-slate-500">
        Page {{ $page }} sur {{ $pages }}
        · {{ $total }} {{ $total === 1 ? 'élément' : 'éléments' }}
    </p>
    <div class="inline-flex gap-1">
        @php
            $prevParams = request()->query();
            $prevParams['page'] = max(1, $page - 1);
            $nextParams = request()->query();
            $nextParams['page'] = min($pages, $page + 1);
        @endphp
        <a
            href="{{ request()->url() }}?{{ http_build_query($prevParams) }}"
            @class([
                'inline-flex items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 bg-white hover:bg-slate-50',
                'pointer-events-none opacity-50' => $page <= 1,
            ])
            aria-disabled="{{ $page <= 1 ? 'true' : 'false' }}">
            Précédent
        </a>
        <a
            href="{{ request()->url() }}?{{ http_build_query($nextParams) }}"
            @class([
                'inline-flex items-center gap-1 rounded-md border border-slate-300 px-3 py-1.5 text-sm font-medium text-slate-700 bg-white hover:bg-slate-50',
                'pointer-events-none opacity-50' => $page >= $pages,
            ])
            aria-disabled="{{ $page >= $pages ? 'true' : 'false' }}">
            Suivant
        </a>
    </div>
</nav>
@endif
