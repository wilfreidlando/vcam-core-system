{{-- F-08 — an audit action in French; an action the platform has no wording for is
     shown as it is, in monospace, rather than hidden or failing. `$action` is the raw string. --}}
@php($wording = \App\Http\View\AuditLabels::action($action))
@if ($wording !== null)
    <span title="{{ $action }}" class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 text-sm text-slate-700">{{ $wording }}</span>
@else
    <span class="inline-flex rounded-md bg-slate-100 px-2 py-0.5 font-mono text-xs text-slate-700">{{ $action }}</span>
@endif
