{{-- F-08 — an audit actor as a person reads it. `$actor` is an App\Http\View\ActorLabel:
     the label is the main text, the full internal identifier stays in `title`
     and in screen-reader text only. --}}
<span title="{{ $actor->full }}" class="whitespace-nowrap">{{ $actor->label }}<span class="sr-only"> ({{ $actor->full }})</span></span>
