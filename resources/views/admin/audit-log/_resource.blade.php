{{-- F-08 — an audited resource (« payment:01a187… »). `$resource` is an App\Http\View\ActorLabel:
     a resource with a known kind reads « Paiement 01a187… » (identifier in `title`); any other is shown as it is, in monospace. --}}
@if ($resource->label !== $resource->full)
    <span title="{{ $resource->full }}">{{ $resource->label }}<span class="sr-only"> ({{ $resource->full }})</span></span>
@else
    <span class="font-mono">{{ $resource->full }}</span>
@endif
