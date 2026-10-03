{{-- ADR-0059 — "Parcours" : chaque étape du paiement, de la requête de
     l'intégrateur au dernier webhook, dans l'ordre chronologique. Les
     secrets sont masqués à la source (jamais stockés en clair). --}}
<div class="bg-white border border-slate-200 rounded-lg p-6" id="parcours">
    <div class="flex items-center justify-between gap-4 mb-4">
        <h2 class="text-lg font-semibold text-slate-900">Parcours</h2>
        <p class="text-sm text-slate-500">{{ count($journey) }} étape(s)</p>
    </div>

    @if ($journey === [])
        <p class="text-slate-500 text-sm">Aucune étape enregistrée pour ce paiement (paiement antérieur à la mise en place du parcours, ou purgé après la durée de rétention).</p>
    @else
        <ol class="relative border-l-2 border-slate-200 ml-2 space-y-5">
            @foreach ($journey as $step)
                <li class="ml-5">
                    <span @class([
                        'absolute -left-[9px] mt-1.5 h-4 w-4 rounded-full border-2 border-white',
                        'bg-emerald-500' => $step['tone'] === 'success',
                        'bg-rose-500' => $step['tone'] === 'danger',
                        'bg-amber-500' => $step['tone'] === 'warning',
                        'bg-slate-400' => $step['tone'] === 'neutral',
                    ])></span>
                    <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                        <span class="font-mono text-xs text-slate-500 tabular-nums">{{ $step['at'] !== '' ? \Illuminate\Support\Carbon::parse($step['at'])->format('d/m/Y H:i:s.v') : '—' }}</span>
                        <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">{{ $step['label'] }}</span>
                    </div>
                    <p class="mt-1 text-sm text-slate-800 break-words">{{ $step['summary'] }}</p>

                    <details class="mt-1 group">
                        <summary class="cursor-pointer text-xs text-indigo-600 hover:text-indigo-800 select-none">Voir le détail</summary>
                        <pre class="mt-2 max-h-96 overflow-auto rounded-md bg-slate-900 p-3 text-xs text-slate-100 whitespace-pre-wrap break-all">{{ json_encode($step['details'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                        @if ($step['correlation_id'] !== null || $step['trace_id'] !== null)
                            <p class="mt-1 text-xs text-slate-500">
                                @if ($step['correlation_id'] !== null)
                                    Correlation-Id : <span class="font-mono">{{ $step['correlation_id'] }}</span>
                                @endif
                                @if ($grafanaUrl !== '')
                                    · <a class="text-indigo-600 hover:text-indigo-800" target="_blank" rel="noopener"
                                        href="{{ $grafanaUrl }}/d/core-journey?var-correlation_id={{ urlencode((string) $step['correlation_id']) }}&var-trace_id={{ urlencode((string) $step['trace_id']) }}">voir les logs et la trace technique</a>
                                @endif
                            </p>
                        @endif
                    </details>
                </li>
            @endforeach
        </ol>
    @endif
</div>
